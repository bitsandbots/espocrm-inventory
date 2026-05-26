<?php

namespace Espo\Modules\Inventory\Hooks\InventoryPurchaseOrderItem;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\Inventory\Services\CcInventoryDbService;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * Handles PO item receipt: increments product qty in cc-inventory when
 * qtyReceived increases, mirrors the receive_purchase_order() logic.
 *
 * @implements AfterSave<\Espo\ORM\Entity>
 */
class PoItemWriteThrough implements AfterSave
{
    public static int $order = 20;

    public function __construct(
        private InjectableFactory $injectableFactory,
        private Log $log
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get('skipInventorySync')) {
            return;
        }

        $qtyReceived     = (int) ($entity->get('qtyReceived') ?? 0);
        $prevQtyReceived = (int) ($entity->getFetched('qtyReceived') ?? 0);
        $delta           = $qtyReceived - $prevQtyReceived;

        if ($delta <= 0) {
            return;
        }

        $db = $this->injectableFactory->create(CcInventoryDbService::class);

        $ccPoItemId  = $entity->get('ccInventoryId');
        $ccProductId = null;
        $ccPoId      = null;

        try {
            $productLink = $entity->get('product');
            if ($productLink) {
                $ccProductId = $productLink->get('ccInventoryId');
            }

            $poLink = $entity->get('purchaseOrder');
            if ($poLink) {
                $ccPoId = $poLink->get('ccInventoryId');
            }

            if (!$ccProductId || !$ccPoId) {
                $this->log->warning("Inventory: PoItemWriteThrough — missing ccInventoryId on product or PO.");
                return;
            }

            $qtyOrdered  = (int) ($entity->get('qtyOrdered') ?? 0);
            $cappedDelta = min($delta, $qtyOrdered - $prevQtyReceived);

            if ($cappedDelta <= 0) {
                return;
            }

            $db->beginTransaction();

            if ($ccPoItemId) {
                $db->execute(
                    "UPDATE purchase_order_items SET qty_received = qty_received + ? WHERE id = ?",
                    [$cappedDelta, $ccPoItemId]
                );
            }

            $db->execute(
                "UPDATE products SET quantity = quantity + ? WHERE id = ?",
                [$cappedDelta, $ccProductId]
            );

            $db->execute(
                "INSERT INTO stock (product_id, quantity, comments, date) VALUES (?, ?, ?, NOW())",
                [$ccProductId, $cappedDelta, "Received via PO #{$ccPoId} (EspoCRM)"]
            );

            $this->recalcPoStatus($db, (int) $ccPoId);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            $this->log->warning("Inventory: PoItemWriteThrough failed for '{$entity->getId()}': " . $e->getMessage());
        }
    }

    private function recalcPoStatus(CcInventoryDbService $db, int $ccPoId): void
    {
        $items = $db->fetchAll(
            "SELECT qty_ordered, qty_received FROM purchase_order_items WHERE po_id = ?",
            [$ccPoId]
        );

        if (empty($items)) {
            return;
        }

        $allReceived = true;
        $anyReceived = false;

        foreach ($items as $item) {
            if ((int) $item['qty_received'] < (int) $item['qty_ordered']) {
                $allReceived = false;
            }
            if ((int) $item['qty_received'] > 0) {
                $anyReceived = true;
            }
        }

        if ($allReceived) {
            $db->execute(
                "UPDATE purchase_orders SET status = 'received', received_at = CURDATE() WHERE id = ?",
                [$ccPoId]
            );
        } elseif ($anyReceived) {
            $db->execute(
                "UPDATE purchase_orders SET status = 'partial' WHERE id = ?",
                [$ccPoId]
            );
        }
    }
}

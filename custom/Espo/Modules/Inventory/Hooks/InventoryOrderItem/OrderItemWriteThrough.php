<?php

namespace Espo\Modules\Inventory\Hooks\InventoryOrderItem;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\Inventory\Services\CcInventoryDbService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use RuntimeException;
use Throwable;

/**
 * @implements AfterSave<\Espo\ORM\Entity>
 */
class OrderItemWriteThrough implements AfterSave
{
    public static int $order = 20;

    public function __construct(
        private InjectableFactory $injectableFactory,
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get('skipInventorySync')) {
            return;
        }

        if (!$entity->isNew()) {
            return;
        }

        $qty   = (int) ($entity->get('qty') ?? 1);
        $price = (float) ($entity->get('price') ?? 0.0);
        $date  = $entity->get('dateItem') ?? date('Y-m-d');

        if ($qty <= 0) {
            $this->log->warning("Inventory: OrderItemWriteThrough — invalid qty {$qty} for '{$entity->getId()}'; skipping.");
            return;
        }

        $db = $this->injectableFactory->create(CcInventoryDbService::class);

        $ccOrderId   = null;
        $ccProductId = null;

        try {
            $orderLink = $entity->get('order');
            if ($orderLink) {
                $ccOrderId = $orderLink->get('ccInventoryId');
            }

            $productLink = $entity->get('product');
            if ($productLink) {
                $ccProductId = $productLink->get('ccInventoryId');
            }

            if (!$ccOrderId || !$ccProductId) {
                $this->log->warning("Inventory: OrderItemWriteThrough — missing ccInventoryId on order or product.");
                return;
            }

            $db->beginTransaction();

            $saleId = $this->insertSale($db, $ccOrderId, $ccProductId, $qty, $price, $date);

            $affected = $db->execute(
                "UPDATE products SET quantity = quantity - ? WHERE id = ? AND quantity >= ?",
                [$qty, $ccProductId, $qty]
            );

            if ($affected === 0) {
                throw new RuntimeException("Insufficient stock for product #{$ccProductId} (requested {$qty} units).");
            }

            $db->execute(
                "INSERT INTO stock (product_id, quantity, comments, date) VALUES (?, ?, ?, NOW())",
                [$ccProductId, -$qty, "Sale via EspoCRM (order #{$ccOrderId})"]
            );

            $db->execute(
                "INSERT INTO audit_log (module, action, record_id, summary, created_at)
                 VALUES ('sales', 'create', ?, 'Created via EspoCRM', NOW())",
                [$ccOrderId]
            );

            $db->commit();

            // Persist cc-inventory sale ID back to EspoCRM (after commit so ID is stable)
            $entity->set('ccInventoryId', $saleId);
            $this->entityManager->saveEntity($entity, ['skipInventorySync' => true, 'silent' => true]);
        } catch (Throwable $e) {
            $db->rollBack();
            $this->log->warning("Inventory: OrderItemWriteThrough failed for '{$entity->getId()}': " . $e->getMessage());
        }
    }

    private function insertSale(
        CcInventoryDbService $db,
        int $ccOrderId,
        int $ccProductId,
        int $qty,
        float $price,
        string $date
    ): int {
        $db->execute(
            "INSERT INTO sales (order_id, product_id, qty, price, date) VALUES (?, ?, ?, ?, ?)",
            [$ccOrderId, $ccProductId, $qty, $price, $date]
        );
        return (int) $db->lastInsertId();
    }
}

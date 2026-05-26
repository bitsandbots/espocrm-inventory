<?php

namespace Espo\Modules\Inventory\Hooks\InventoryOrderItem;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\Inventory\Services\CcInventoryDbService;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * @implements AfterSave<\Espo\ORM\Entity>
 */
class OrderItemWriteThrough implements AfterSave
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

        if (!$entity->isNew()) {
            return;
        }

        $db = $this->injectableFactory->create(CcInventoryDbService::class);

        $ccOrderId   = null;
        $ccProductId = null;
        $qty         = (int) ($entity->get('qty') ?? 1);
        $price       = (float) ($entity->get('price') ?? 0.0);
        $date        = $entity->get('dateItem') ?? date('Y-m-d');

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

            $db->execute(
                "INSERT INTO sales (order_id, product_id, qty, price, date) VALUES (?, ?, ?, ?, ?)",
                [$ccOrderId, $ccProductId, $qty, $price, $date]
            );

            $db->execute(
                "UPDATE products SET quantity = quantity - ? WHERE id = ? AND quantity >= ?",
                [$qty, $ccProductId, $qty]
            );

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
        } catch (Throwable $e) {
            $db->rollBack();
            $this->log->warning("Inventory: OrderItemWriteThrough failed for '{$entity->getId()}': " . $e->getMessage());
        }
    }
}

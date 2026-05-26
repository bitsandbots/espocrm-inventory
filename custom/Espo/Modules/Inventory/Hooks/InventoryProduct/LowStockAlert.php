<?php

namespace Espo\Modules\Inventory\Hooks\InventoryProduct;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * Creates an EspoCRM notification when a product quantity drops to or below
 * its low-stock threshold. Only fires when quantity actually changed.
 *
 * @implements AfterSave<\Espo\ORM\Entity>
 */
class LowStockAlert implements AfterSave
{
    public static int $order = 20;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get('skipInventorySync')) {
            return;
        }

        $qty       = (int) ($entity->get('quantity') ?? 0);
        $threshold = (int) ($entity->get('lowStockThreshold') ?? 0);
        $prevQty   = (int) ($entity->getFetched('quantity') ?? $qty);

        if ($qty > $threshold) {
            return;
        }

        if ($prevQty === $qty && !$entity->isNew()) {
            return;
        }

        try {
            $this->createLowStockNotification($entity, $qty, $threshold);
        } catch (Throwable $e) {
            $this->log->warning("Inventory: LowStockAlert failed for '{$entity->getId()}': " . $e->getMessage());
        }
    }

    private function createLowStockNotification(Entity $product, int $qty, int $threshold): void
    {
        $admins = $this->entityManager
            ->getRepository('User')
            ->where('isAdmin', true)
            ->where('isActive', true)
            ->find();

        $message = sprintf(
            "Low stock alert: %s (SKU: %s) — %d units on hand (threshold: %d)",
            $product->get('name') ?? 'Unknown product',
            $product->get('sku') ?? 'N/A',
            $qty,
            $threshold
        );

        foreach ($admins as $admin) {
            $notification = $this->entityManager->getNewEntity('Notification');
            $notification->set('type', 'Message');
            $notification->set('message', $message);
            $notification->set('userId', $admin->getId());
            $notification->set('relatedType', 'InventoryProduct');
            $notification->set('relatedId', $product->getId());
            $this->entityManager->saveEntity($notification);
        }
    }
}

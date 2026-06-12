<?php

namespace Espo\Modules\Inventory\Hooks\InventoryPurchaseOrder;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\Inventory\Services\CcInventoryDbService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * @implements AfterSave<\Espo\ORM\Entity>
 */
class PurchaseOrderWriteThrough implements AfterSave
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

        $db = $this->injectableFactory->create(CcInventoryDbService::class);

        try {
            $ccId       = $entity->get('ccInventoryId');
            $supplierId = null;

            // Load supplier account directly via FK
            $espoSupplierId = $entity->get('supplierId');
            if ($espoSupplierId) {
                $supplierAccount = $this->entityManager->getEntityById('Account', $espoSupplierId);
                if ($supplierAccount) {
                    $ccSupplierId = $supplierAccount->get('ccInventorySupplierId');
                    if ($ccSupplierId) {
                        $supplierId = (int) $ccSupplierId;
                    }
                }
            }

            $data = [
                'supplier_id' => $supplierId,
                'reference'   => $entity->get('reference') ?? '',
                'status'      => $entity->get('status') ?? 'draft',
                'notes'       => $entity->get('notes') ?? '',
                'expected_at' => $entity->get('expectedAt'),
            ];

            if (!$ccId) {
                $db->execute(
                    "INSERT INTO purchase_orders (supplier_id, reference, status, notes, expected_at, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())",
                    array_values($data)
                );
                $newCcId = (int) $db->lastInsertId();

                $db->execute(
                    "INSERT INTO audit_log (module, action, record_id, summary, created_at)
                     VALUES ('purchase_orders', 'create', ?, 'Created via EspoCRM', NOW())",
                    [$newCcId]
                );

                $entity->set('ccInventoryId', $newCcId);
                $this->entityManager->saveEntity($entity, ['skipInventorySync' => true, 'silent' => true]);
            } else {
                $db->execute(
                    "UPDATE purchase_orders SET supplier_id = ?, reference = ?, status = ?,
                     notes = ?, expected_at = ? WHERE id = ?",
                    array_merge(array_values($data), [$ccId])
                );

                $db->execute(
                    "INSERT INTO audit_log (module, action, record_id, summary, created_at)
                     VALUES ('purchase_orders', 'update', ?, 'Updated via EspoCRM', NOW())",
                    [$ccId]
                );
            }
        } catch (Throwable $e) {
            $this->log->warning("Inventory: InventoryPurchaseOrder write-through failed for '{$entity->getId()}': " . $e->getMessage());
        }
    }
}

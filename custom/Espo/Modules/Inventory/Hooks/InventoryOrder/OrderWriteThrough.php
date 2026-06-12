<?php

namespace Espo\Modules\Inventory\Hooks\InventoryOrder;

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
class OrderWriteThrough implements AfterSave
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
            $customerId = null;

            // Load account directly via FK — avoids lazy-load miss on new entities
            $espoCustomerId = $entity->get('customerId');
            if ($espoCustomerId) {
                $customerAccount = $this->entityManager->getEntityById('Account', $espoCustomerId);
                if ($customerAccount) {
                    $ccCustomerId = $customerAccount->get('ccInventoryCustomerId');
                    if ($ccCustomerId) {
                        $customerId = (int) $ccCustomerId;
                    }
                }
            }

            $data = [
                'customer'    => $entity->get('customerName') ?? '',
                'customer_id' => $customerId,
                'status'      => $entity->get('status') ?? 'pending',
                'notes'       => $entity->get('notes') ?? '',
                'paymethod'   => $entity->get('payMethod') ?? '',
                'date'        => $entity->get('dateOrdered') ?? date('Y-m-d'),
            ];

            if (!$ccId) {
                $db->execute(
                    "INSERT INTO orders (customer, customer_id, status, notes, paymethod, date)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    array_values($data)
                );
                $newCcId = (int) $db->lastInsertId();

                $db->execute(
                    "INSERT INTO audit_log (module, action, record_id, summary, created_at)
                     VALUES ('orders', 'create', ?, 'Created via EspoCRM', NOW())",
                    [$newCcId]
                );

                // Persist the new cc-inventory ID back to EspoCRM
                $entity->set('ccInventoryId', $newCcId);
                $this->entityManager->saveEntity($entity, ['skipInventorySync' => true, 'silent' => true]);
            } else {
                $db->execute(
                    "UPDATE orders SET customer = ?, customer_id = ?, status = ?,
                     notes = ?, paymethod = ?, date = ? WHERE id = ?",
                    array_merge(array_values($data), [$ccId])
                );

                $db->execute(
                    "INSERT INTO audit_log (module, action, record_id, summary, created_at)
                     VALUES ('orders', 'update', ?, 'Updated via EspoCRM', NOW())",
                    [$ccId]
                );
            }
        } catch (Throwable $e) {
            $this->log->warning("Inventory: InventoryOrder write-through failed for '{$entity->getId()}': " . $e->getMessage());
        }
    }
}

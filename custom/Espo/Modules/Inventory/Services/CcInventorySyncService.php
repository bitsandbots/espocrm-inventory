<?php

namespace Espo\Modules\Inventory\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\ORM\EntityManager;
use Throwable;

class CcInventorySyncService
{
    private int $syncCount = 0;

    public function __construct(
        private EntityManager $entityManager,
        private CcInventoryDbService $db,
        private Log $log
    ) {}

    public function runFullSync(): int
    {
        $this->syncCount = 0;

        $this->syncCategories();
        $this->syncProducts();
        $this->syncCustomersAsAccounts();
        $this->syncSuppliersAsAccounts();
        $this->syncOrders();
        $this->syncOrderItems();
        $this->syncPurchaseOrders();
        $this->syncPurchaseOrderItems();
        $this->syncStockAdjustments();

        $this->updateLastSyncMeta();

        return $this->syncCount;
    }

    // -------------------------------------------------------------------------
    // Categories
    // -------------------------------------------------------------------------

    private function syncCategories(): void
    {
        $rows = $this->db->fetchAll("SELECT id, name FROM categories ORDER BY id");

        foreach ($rows as $row) {
            $this->upsert('InventoryCategory', (int) $row['id'], [
                'name'          => $row['name'],
                'ccInventoryId' => (int) $row['id'],
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Products
    // -------------------------------------------------------------------------

    private function syncProducts(): void
    {
        $rows = $this->db->fetchAll(
            "SELECT p.id, p.name, p.description, p.sku, p.location,
                    p.quantity, p.low_stock_threshold, p.buy_price, p.sale_price,
                    p.category_id, p.date, p.deleted_at
             FROM products p
             ORDER BY p.id"
        );

        foreach ($rows as $row) {
            $categoryId = null;
            if ($row['category_id']) {
                $cat = $this->findByCcId('InventoryCategory', (int) $row['category_id']);
                $categoryId = $cat ? $cat->getId() : null;
            }

            $this->upsert('InventoryProduct', (int) $row['id'], [
                'name'              => $row['name'],
                'description'       => $row['description'],
                'sku'               => $row['sku'],
                'location'          => $row['location'],
                'quantity'          => (int) $row['quantity'],
                'lowStockThreshold' => (int) $row['low_stock_threshold'],
                'buyPrice'          => $row['buy_price'] !== null ? (float) $row['buy_price'] : null,
                'salePrice'         => (float) $row['sale_price'],
                'categoryId'        => $categoryId,
                'ccInventoryId'     => (int) $row['id'],
                'deleted'           => $row['deleted_at'] !== null,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Customers → EspoCRM Account (type Customer)
    // -------------------------------------------------------------------------

    private function syncCustomersAsAccounts(): void
    {
        $rows = $this->db->fetchAll(
            "SELECT id, name, address, city, region, postcode, telephone, email, paymethod
             FROM customers ORDER BY id"
        );

        foreach ($rows as $row) {
            $this->upsertAccountFromCcInventory(
                ccId: (int) $row['id'],
                ccIdField: 'ccInventoryCustomerId',
                name: $row['name'],
                type: 'Customer',
                extra: [
                    'billingAddressStreet'     => $row['address'],
                    'billingAddressCity'       => $row['city'],
                    'billingAddressState'      => $row['region'],
                    'billingAddressPostalCode' => $row['postcode'],
                    'phoneNumber'              => $row['telephone'],
                    'emailAddress'             => $row['email'],
                ]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Suppliers → EspoCRM Account (type Vendor)
    // -------------------------------------------------------------------------

    private function syncSuppliersAsAccounts(): void
    {
        $rows = $this->db->fetchAll(
            "SELECT id, name, contact, email, telephone, address, city, region,
                    postcode, website, notes, status
             FROM suppliers ORDER BY id"
        );

        foreach ($rows as $row) {
            $this->upsertAccountFromCcInventory(
                ccId: (int) $row['id'],
                ccIdField: 'ccInventorySupplierId',
                name: $row['name'],
                type: 'Vendor',
                extra: [
                    'billingAddressStreet'     => $row['address'],
                    'billingAddressCity'       => $row['city'],
                    'billingAddressState'      => $row['region'],
                    'billingAddressPostalCode' => $row['postcode'],
                    'phoneNumber'              => $row['telephone'],
                    'emailAddress'             => $row['email'],
                    'website'                  => $row['website'],
                    'description'              => $row['notes'],
                ]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Orders
    // -------------------------------------------------------------------------

    private function syncOrders(): void
    {
        $rows = $this->db->fetchAll(
            "SELECT id, customer_id, customer, status, notes, paymethod, date
             FROM orders ORDER BY id"
        );

        foreach ($rows as $row) {
            $accountId = null;
            if ($row['customer_id']) {
                $account = $this->findAccountByCcId('ccInventoryCustomerId', (int) $row['customer_id']);
                $accountId = $account ? $account->getId() : null;
            }

            $this->upsert('InventoryOrder', (int) $row['id'], [
                'name'          => 'Order #' . $row['id'],
                'status'        => $row['status'],
                'notes'         => $row['notes'],
                'payMethod'     => $row['paymethod'],
                'dateOrdered'   => $row['date'],
                'customerId'    => $accountId,
                'ccInventoryId' => (int) $row['id'],
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Order Items (sales)
    // -------------------------------------------------------------------------

    private function syncOrderItems(): void
    {
        $rows = $this->db->fetchAll(
            "SELECT id, order_id, product_id, qty, price, date
             FROM sales ORDER BY id"
        );

        if (!$rows) {
            return;
        }

        $orderMap   = $this->buildCcIdMap('InventoryOrder',   array_unique(array_column($rows, 'order_id')));
        $productMap = $this->buildCcIdMap('InventoryProduct', array_unique(array_column($rows, 'product_id')));

        foreach ($rows as $row) {
            $this->upsert('InventoryOrderItem', (int) $row['id'], [
                'name'          => 'Item #' . $row['id'],
                'orderId'       => $orderMap[(int) $row['order_id']] ?? null,
                'productId'     => $productMap[(int) $row['product_id']] ?? null,
                'qty'           => (int) $row['qty'],
                'price'         => (float) $row['price'],
                'dateItem'      => $row['date'],
                'ccInventoryId' => (int) $row['id'],
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Purchase Orders
    // -------------------------------------------------------------------------

    private function syncPurchaseOrders(): void
    {
        $rows = $this->db->fetchAll(
            "SELECT id, supplier_id, reference, status, notes,
                    expected_at, received_at, created_at
             FROM purchase_orders ORDER BY id"
        );

        foreach ($rows as $row) {
            $accountId = null;
            if ($row['supplier_id']) {
                $account = $this->findAccountByCcId('ccInventorySupplierId', (int) $row['supplier_id']);
                $accountId = $account ? $account->getId() : null;
            }

            $this->upsert('InventoryPurchaseOrder', (int) $row['id'], [
                'name'          => 'PO #' . $row['id'],
                'supplierId'    => $accountId,
                'reference'     => $row['reference'],
                'status'        => $row['status'],
                'notes'         => $row['notes'],
                'expectedAt'    => $row['expected_at'],
                'receivedAt'    => $row['received_at'],
                'ccInventoryId' => (int) $row['id'],
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Purchase Order Items
    // -------------------------------------------------------------------------

    private function syncPurchaseOrderItems(): void
    {
        $rows = $this->db->fetchAll(
            "SELECT id, po_id, product_id, qty_ordered, qty_received, unit_cost
             FROM purchase_order_items ORDER BY id"
        );

        if (!$rows) {
            return;
        }

        $poMap      = $this->buildCcIdMap('InventoryPurchaseOrder', array_unique(array_column($rows, 'po_id')));
        $productMap = $this->buildCcIdMap('InventoryProduct',       array_unique(array_column($rows, 'product_id')));

        foreach ($rows as $row) {
            $this->upsert('InventoryPurchaseOrderItem', (int) $row['id'], [
                'name'            => 'PO Item #' . $row['id'],
                'purchaseOrderId' => $poMap[(int) $row['po_id']] ?? null,
                'productId'       => $productMap[(int) $row['product_id']] ?? null,
                'qtyOrdered'      => (int) $row['qty_ordered'],
                'qtyReceived'     => (int) $row['qty_received'],
                'unitCost'        => (float) $row['unit_cost'],
                'ccInventoryId'   => (int) $row['id'],
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Stock Adjustments
    // -------------------------------------------------------------------------

    private function syncStockAdjustments(): void
    {
        $rows = $this->db->fetchAll(
            "SELECT id, product_id, quantity, comments, date
             FROM stock ORDER BY id"
        );

        if (!$rows) {
            return;
        }

        $productMap = $this->buildCcIdMap('InventoryProduct', array_unique(array_column($rows, 'product_id')));

        foreach ($rows as $row) {
            $productId = $productMap[(int) $row['product_id']] ?? null;

            $this->upsert('InventoryStockAdjustment', (int) $row['id'], [
                'name'          => 'Adjustment #' . $row['id'],
                'productId'     => $productId,
                'quantity'      => (int) $row['quantity'],
                'comments'      => $row['comments'],
                'dateAdjusted'  => $row['date'],
                'ccInventoryId' => (int) $row['id'],
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function upsert(string $entityType, int $ccId, array $data): void
    {
        $entity = $this->findByCcId($entityType, $ccId);

        if (!$entity) {
            $entity = $this->entityManager->getNewEntity($entityType);
        }

        foreach ($data as $field => $value) {
            $entity->set($field, $value);
        }

        $this->entityManager->saveEntity($entity, ['skipInventorySync' => true, 'silent' => true]);
        $this->syncCount++;
    }

    private function findByCcId(string $entityType, int $ccId): ?\Espo\ORM\Entity
    {
        return $this->entityManager
            ->getRepository($entityType)
            ->where('ccInventoryId', $ccId)
            ->findOne();
    }

    /**
     * Batch-load ccInventoryId → espo id for an entity type.
     * Reduces N per-row SELECTs to a single IN query.
     *
     * @param  int[]  $ccIds
     * @return array<int, string>  [ccInventoryId => espoId]
     */
    private function buildCcIdMap(string $entityType, array $ccIds): array
    {
        $ccIds = array_values(array_filter(array_map('intval', $ccIds)));

        if (!$ccIds) {
            return [];
        }

        $collection = $this->entityManager
            ->getRepository($entityType)
            ->select(['id', 'ccInventoryId'])
            ->where('ccInventoryId', $ccIds)
            ->find();

        $map = [];

        foreach ($collection as $entity) {
            $ccId = (int) $entity->get('ccInventoryId');
            if ($ccId) {
                $map[$ccId] = $entity->getId();
            }
        }

        return $map;
    }

    private function upsertAccountFromCcInventory(
        int $ccId,
        string $ccIdField,
        string $name,
        string $type,
        array $extra = []
    ): void {
        $account = $this->findAccountByCcId($ccIdField, $ccId);

        if (!$account) {
            $account = $this->entityManager
                ->getRepository('Account')
                ->where('name', $name)
                ->findOne();
        }

        if (!$account) {
            $account = $this->entityManager->getNewEntity('Account');
        }

        $account->set('name', $name);
        $account->set('type', $type);
        $account->set($ccIdField, $ccId);

        foreach ($extra as $field => $value) {
            if ($value !== null && $value !== '') {
                $account->set($field, $value);
            }
        }

        $this->entityManager->saveEntity($account, ['skipInventorySync' => true, 'silent' => true]);
        $this->syncCount++;
    }

    private function findAccountByCcId(string $field, int $ccId): ?\Espo\ORM\Entity
    {
        return $this->entityManager
            ->getRepository('Account')
            ->where($field, $ccId)
            ->findOne();
    }

    private function updateLastSyncMeta(): void
    {
        $integration = $this->entityManager->getEntityById(
            \Espo\Entities\Integration::ENTITY_TYPE,
            'CcInventory'
        );

        if (!$integration) {
            return;
        }

        $integration->set('lastSyncAt', date('Y-m-d H:i:s'));
        $integration->set('lastSyncCount', $this->syncCount);
        $integration->set('lastSyncError', null);

        $this->entityManager->saveEntity($integration);
    }

    public function recordSyncError(string $message): void
    {
        $integration = $this->entityManager->getEntityById(
            \Espo\Entities\Integration::ENTITY_TYPE,
            'CcInventory'
        );

        if (!$integration) {
            return;
        }

        $integration->set('lastSyncError', $message);
        $this->entityManager->saveEntity($integration);
    }
}

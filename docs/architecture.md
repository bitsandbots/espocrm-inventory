# Architecture

## System Overview

The module operates as a **bridge layer** between two independent databases: the cc-inventory MySQL database (source-of-truth for warehouse operations) and the EspoCRM MySQL database (source-of-truth for CRM records).

```
cc-inventory MySQL                    EspoCRM MySQL
      │                                     │
      │  ←── nightly pull (read-only) ───   │
      │                                     │
      │  ←── write-through (INSERT/UPDATE) ─ │
      │      on order/PO saves              │
```

No cc-inventory application code is modified. The bridge uses PDO to read from and write to cc-inventory directly by table name.

## Module Structure

```
espocrm-inventory/
├── custom/Espo/Modules/Inventory/
│   ├── Controllers/
│   │   └── InventorySync.php          REST: testConnection, runSync
│   ├── Hooks/
│   │   ├── InventoryOrder/
│   │   │   └── OrderWriteThrough.php  AfterSave → INSERT/UPDATE orders
│   │   ├── InventoryOrderItem/
│   │   │   └── OrderItemWriteThrough.php  AfterSave (new only) → INSERT sales
│   │   ├── InventoryProduct/
│   │   │   └── LowStockAlert.php      AfterSave → create Notification if qty ≤ threshold
│   │   └── InventoryPurchaseOrderItem/
│   │       └── PoItemWriteThrough.php AfterSave → UPDATE qty_received, recalc PO status
│   ├── Jobs/
│   │   └── SyncFromCcInventory.php    Scheduled job — calls runFullSync()
│   ├── Services/
│   │   ├── CcInventoryDbService.php   PDO wrapper (connect, fetchAll, execute, tx)
│   │   └── CcInventorySyncService.php Full sync orchestration (9 entity types)
│   └── Resources/
│       ├── i18n/en_US/                Labels for all entities
│       ├── layouts/                   List/detail/edit view layouts
│       ├── metadata/
│       │   ├── app/scheduledJobs.json Registers SyncFromCcInventory
│       │   ├── clientDefs/            UI config per entity
│       │   ├── entityDefs/            Field and link definitions
│       │   ├── integrations/          CcInventory integration schema
│       │   └── scopes/                ACL scope registrations
│       ├── module.json                Module order: 17, jsTranspiled: true
│       └── routes.json                API routes for testConnection and runSync
└── client/custom/modules/inventory/
    ├── src/views/
    │   ├── admin/integrations/inventory.js   Admin integration panel with sync status
    │   └── panels/inventory-summary.js       Account detail summary panel
    ├── lib/transpiled/                        AMD-compiled output (required by EspoCRM)
    └── res/templates/panels/
        └── inventory-summary.tpl             Handlebars template for summary panel
```

## Sync Flows

### Pull Flow (nightly)

```
System cron (every minute)
   └─► cron.php
          └─► EspoCRM Job Dispatcher
                 └─► SyncFromCcInventory::run()
                        └─► CcInventorySyncService::runFullSync()
                               ├─► syncCategories()      → SELECT categories
                               ├─► syncProducts()         → SELECT products
                               ├─► syncCustomersAsAccounts() → SELECT customers → upsert Account (type=Customer)
                               ├─► syncSuppliersAsAccounts() → SELECT suppliers → upsert Account (type=Vendor)
                               ├─► syncOrders()           → SELECT orders
                               ├─► syncOrderItems()       → SELECT sales
                               ├─► syncPurchaseOrders()   → SELECT purchase_orders
                               ├─► syncPurchaseOrderItems() → SELECT purchase_order_items
                               ├─► syncStockAdjustments() → SELECT stock
                               └─► updateLastSyncMeta()   → UPDATE Integration entity
```

All upserts use `ccInventoryId` as the stable key:
1. `SELECT WHERE ccInventoryId = ?` → found? UPDATE : INSERT
2. All saves use `skipInventorySync: true` to suppress write-through hooks
3. Record count tracked; stored on Integration entity with timestamp

### Write-Through Flow (real-time)

```
EspoCRM user edits InventoryOrder
   └─► EspoCRM ORM saveEntity()
          └─► OrderWriteThrough::afterSave()     [if NOT skipInventorySync]
                 ├─► if no ccInventoryId: INSERT INTO orders → store new id
                 └─► if ccInventoryId exists: UPDATE orders SET ...
                        └─► INSERT INTO audit_log (module=orders, action=update)
```

```
EspoCRM user adds new InventoryOrderItem
   └─► OrderItemWriteThrough::afterSave()        [new records only]
          ├─► BEGIN TRANSACTION
          ├─► INSERT INTO sales (order_id, product_id, qty, price, date)
          ├─► UPDATE products SET quantity = quantity - qty WHERE id = ?
          ├─► INSERT INTO stock (product_id, quantity=-qty, comments)
          ├─► INSERT INTO audit_log
          └─► COMMIT
```

```
EspoCRM user updates qtyReceived on InventoryPurchaseOrderItem
   └─► PoItemWriteThrough::afterSave()           [only when qtyReceived increases]
          ├─► BEGIN TRANSACTION
          ├─► UPDATE purchase_order_items SET qty_received += delta
          ├─► UPDATE products SET quantity += delta
          ├─► INSERT INTO stock (positive qty = receipt)
          ├─► recalcPoStatus() → UPDATE purchase_orders SET status
          └─► COMMIT
```

### Loop Guard

All AfterSave hooks check `$options->get('skipInventorySync')` before executing. All `saveEntity()` calls within sync operations pass `['skipInventorySync' => true]`, preventing infinite recursion.

### Low-Stock Alert Flow

```
InventoryProduct saved with new quantity
   └─► LowStockAlert::afterSave()
          ├─► if qty > threshold → return (no alert)
          ├─► if qty unchanged and not new → return (no repeat alert)
          └─► foreach active admin:
                 └─► saveEntity(Notification, type=Message, userId=admin.id)
```

## Admin UI

### Integration Config Panel (`Admin → Integrations → CC Inventory`)

- Extends EspoCRM's built-in `IntegrationsEditView`
- Renders a status section below the form: last sync timestamp, record count, last error
- Buttons: **Test Connection** (POST `/api/v1/Inventory/testConnection`) and **Sync Now** (POST `/api/v1/Inventory/runSync`)
- After sync completes, re-fetches model and re-renders status section

### Inventory Summary Panel (Account detail view)

- Injected as a side panel on the Account entity via `clientDefs/Account.json`
- On render, fetches up to 10 open orders and 10 open POs for the account via EspoCRM API
- Orders filter: status IN (pending, processing, shipped)
- POs filter: status IN (draft, ordered, partial)
- Renders linked record names with status badges and dates

## cc-inventory Database Tables Used

| Operation | cc-inventory Table |
|-----------|-------------------|
| Pull | `categories`, `products`, `customers`, `suppliers`, `orders`, `sales`, `purchase_orders`, `purchase_order_items`, `stock` |
| Write (orders) | `orders`, `audit_log` |
| Write (order items) | `sales`, `products`, `stock`, `audit_log` |
| Write (PO items) | `purchase_order_items`, `products`, `stock`, `purchase_orders` |

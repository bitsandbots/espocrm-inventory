# API & Component Reference

## REST API Routes

Defined in `custom/Espo/Modules/Inventory/Resources/routes.json`. All routes require admin authentication.

| Method | Route | Controller → Action | Description |
|--------|-------|---------------------|-------------|
| POST | `/api/v1/Inventory/testConnection` | `InventorySync::postActionTestConnection` | Attempts PDO connection using Integration credentials. Returns `{success: true}` or throws 500 with error message. |
| POST | `/api/v1/Inventory/runSync` | `InventorySync::postActionRunSync` | Runs full sync. Returns `{success: true, count: N}` where N is total records processed. Records error on Integration entity if sync throws. |

Standard EspoCRM entity CRUD routes are available for all Inventory entities (list, view, create, edit, delete) via the default EspoCRM REST API at `/api/v1/{EntityType}`.

---

## Entity Reference

### InventoryProduct

| Field | Type | Notes |
|-------|------|-------|
| `name` | varchar(255) | Required |
| `sku` | varchar(100) | Indexed |
| `description` | text | |
| `location` | varchar(255) | Warehouse location |
| `quantity` | int | Read-only (managed by sync and write-through hooks) |
| `lowStockThreshold` | int | Default: 10. Triggers notification when quantity ≤ this value |
| `buyPrice` | currency | |
| `salePrice` | currency | Required |
| `category` | link → InventoryCategory | |
| `ccInventoryId` | int | Read-only. Foreign key to cc-inventory `products.id` |
| `stockAdjustments` | hasMany → InventoryStockAdjustment | |
| `orderItems` | hasMany → InventoryOrderItem | |

### InventoryCategory

| Field | Type | Notes |
|-------|------|-------|
| `name` | varchar(255) | Required |
| `ccInventoryId` | int | Read-only |
| `products` | hasMany → InventoryProduct | |

### InventoryOrder

| Field | Type | Notes |
|-------|------|-------|
| `name` | varchar(100) | Auto-generated: "Order #N" |
| `status` | enum | `pending` \| `processing` \| `shipped` \| `fulfilled` \| `cancelled` |
| `notes` | text | |
| `payMethod` | varchar(10) | |
| `dateOrdered` | date | |
| `customer` | link → Account | Linked via `ccInventoryCustomerId` on Account |
| `ccInventoryId` | int | Read-only |
| `orderItems` | hasMany → InventoryOrderItem | |

### InventoryOrderItem

| Field | Type | Notes |
|-------|------|-------|
| `name` | varchar(100) | Auto-generated: "Item #N" |
| `order` | link → InventoryOrder | Required |
| `product` | link → InventoryProduct | Required |
| `qty` | int | |
| `price` | float | Unit price |
| `dateItem` | date | |
| `ccInventoryId` | int | Read-only |

### InventoryPurchaseOrder

| Field | Type | Notes |
|-------|------|-------|
| `name` | varchar(100) | Auto-generated: "PO #N" |
| `supplier` | link → Account | Linked via `ccInventorySupplierId` on Account |
| `reference` | varchar(100) | External PO reference number |
| `status` | enum | `draft` \| `ordered` \| `partial` \| `received` \| `cancelled` |
| `notes` | text | |
| `expectedAt` | date | Expected receipt date |
| `receivedAt` | date | Read-only. Set automatically when all items received |
| `ccInventoryId` | int | Read-only |
| `purchaseOrderItems` | hasMany → InventoryPurchaseOrderItem | |

### InventoryPurchaseOrderItem

| Field | Type | Notes |
|-------|------|-------|
| `name` | varchar(100) | Auto-generated: "PO Item #N" |
| `purchaseOrder` | link → InventoryPurchaseOrder | Required |
| `product` | link → InventoryProduct | Required |
| `qtyOrdered` | int | |
| `qtyReceived` | int | Incrementing this triggers stock update and PO status recalc |
| `unitCost` | float | |
| `ccInventoryId` | int | Read-only |

### InventoryStockAdjustment

| Field | Type | Notes |
|-------|------|-------|
| `name` | varchar(100) | Auto-generated: "Adjustment #N" |
| `product` | link → InventoryProduct | Required |
| `quantity` | int | Required. Positive = stock increase; negative = decrease |
| `comments` | text | |
| `dateAdjusted` | datetime | |
| `ccInventoryId` | int | Read-only |

---

## Hook Reference

### `Hooks/InventoryProduct/LowStockAlert` (AfterSave)

**Triggers:** When `quantity` changes to a value ≤ `lowStockThreshold`  
**Skips:** If `skipInventorySync` option is set, or if quantity is unchanged and entity is not new  
**Effect:** Creates one `Notification` (type=Message) per active admin user, linking back to the product  
**File:** `custom/Espo/Modules/Inventory/Hooks/InventoryProduct/LowStockAlert.php`

### `Hooks/InventoryOrder/OrderWriteThrough` (AfterSave)

**Triggers:** Any InventoryOrder save that does not carry `skipInventorySync`  
**Effect:**
- New record (no `ccInventoryId`): `INSERT INTO orders` → stores returned ID as `ccInventoryId`, logs to `audit_log`
- Existing record: `UPDATE orders SET ... WHERE id = ccInventoryId`, logs to `audit_log`  
**File:** `custom/Espo/Modules/Inventory/Hooks/InventoryOrder/OrderWriteThrough.php`

### `Hooks/InventoryOrderItem/OrderItemWriteThrough` (AfterSave)

**Triggers:** New InventoryOrderItem saves only (returns early if `!$entity->isNew()`)  
**Effect (transactional):** INSERT into `sales`, decrement `products.quantity`, log `stock` record, log `audit_log`  
**Note:** Updates to existing order items (qty, price) do NOT write through — see gap analysis  
**File:** `custom/Espo/Modules/Inventory/Hooks/InventoryOrderItem/OrderItemWriteThrough.php`

### `Hooks/InventoryPurchaseOrderItem/PoItemWriteThrough` (AfterSave)

**Triggers:** When `qtyReceived` increases on an existing PO item  
**Effect (transactional):** Increment `purchase_order_items.qty_received`, increment `products.quantity`, log `stock` record, recalculate PO status (`partial` → `received`)  
**Note:** Delta is capped at `qtyOrdered - prevQtyReceived` to prevent over-receipt  
**File:** `custom/Espo/Modules/Inventory/Hooks/InventoryPurchaseOrderItem/PoItemWriteThrough.php`

---

## Service API (PHP)

### `CcInventoryDbService`

PDO wrapper. Constructed by EspoCRM's `InjectableFactory`; reads connection parameters from the `CcInventory` Integration entity.

| Method | Signature | Description |
|--------|-----------|-------------|
| `getConnection()` | `(): PDO` | Returns cached PDO connection; connects on first call; throws `Error` if integration disabled or credentials missing |
| `testConnection()` | `(): bool` | Calls `SELECT 1`; throws `Error` on failure |
| `fetchAll()` | `(string $sql, array $params = []): array` | Prepared SELECT → array of rows |
| `fetchOne()` | `(string $sql, array $params = []): ?array` | Prepared SELECT → first row or null |
| `execute()` | `(string $sql, array $params = []): int` | Prepared INSERT/UPDATE/DELETE → affected row count |
| `lastInsertId()` | `(): string` | PDO `lastInsertId()` |
| `beginTransaction()` | `(): void` | |
| `commit()` | `(): void` | |
| `rollBack()` | `(): void` | Rolls back only if in transaction |

### `CcInventorySyncService`

Orchestrates all sync operations. Constructed via `InjectableFactory`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `runFullSync()` | `(): int` | Runs all 9 sync methods in order; returns total record count |
| `recordSyncError()` | `(string $message): void` | Writes error string to Integration entity's `lastSyncError` field |

---

## Client-Side Components

### `inventory:views/admin/integrations/inventory` (AMD module)

Extends `views/admin/integrations/edit`. Renders a status block below the form:
- Last sync timestamp and record count
- Last sync error (if any) in a red-tinted panel
- **Test Connection** and **Sync Now** buttons

Both buttons POST to the custom API routes above and update the status block on completion.

### `inventory:views/panels/inventory-summary` (AMD module)

Rendered as a side panel on Account detail view. Fetches open orders and open POs for the current account using `Espo.Ajax.getRequest()` and renders them as linked lists.

Data passed to template:
- `orders` — array of InventoryOrder records (status: pending/processing/shipped)
- `purchaseOrders` — array of InventoryPurchaseOrder records (status: draft/ordered/partial)
- `hasOrders` — boolean (length > 0)
- `hasPurchaseOrders` — boolean (length > 0)

### Template: `inventory:panels/inventory-summary`

Handlebars template. Uses `hasOrders` / `hasPurchaseOrders` guards, then iterates each list rendering a `<a href>` record link, a `statusStyle`-colored status badge, and a date if present.

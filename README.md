# EspoCRM CC Inventory Integration

One-way bridge from a **cc-inventory MySQL database** into EspoCRM, with write-through
hooks that push EspoCRM order/PO edits back to cc-inventory in real time.

## What It Does

- Pulls categories, products, customers, suppliers, orders, POs, and stock adjustments
  from a cc-inventory MySQL database into EspoCRM entities on a nightly schedule.
- Maps cc-inventory customers/suppliers to EspoCRM Accounts (upsert on ccInventoryId).
- Pushes EspoCRM order and PO edits back to cc-inventory via AfterSave hooks.
- Fires an EspoCRM Notification when a product's quantity drops to or below its
  `lowStockThreshold`.

## New Entities

| Entity | Description |
|---|---|
| InventoryProduct | Products with SKU, stock quantity, pricing, low-stock threshold |
| InventoryCategory | Product groupings |
| InventoryOrder | Customer orders with status lifecycle |
| InventoryOrderItem | Line items within orders |
| InventoryPurchaseOrder | Supplier POs with status lifecycle |
| InventoryPurchaseOrderItem | Line items within POs |
| InventoryStockAdjustment | Manual stock adjustments |

**Extended core entities:** Account gains `ccInventoryCustomerId`, `ccInventorySupplierId`,
plus `inventoryOrders` and `inventoryPurchaseOrders` relationship panels.

## Requirements

- EspoCRM 9.x
- PHP 8.3+ with PDO MySQL (`ext-pdo_mysql`)
- Network access from EspoCRM server to the cc-inventory MySQL host

## Installation

**From a release ZIP:**

```bash
cd /path/to/espocrm
unzip espocrm-inventory-v*.zip
bash scripts/install.sh --espo-path /path/to/espocrm
```

**From source:**

```bash
git clone https://github.com/coreconduit/espocrm-inventory.git
cd espocrm-inventory
scripts/install.sh --espo-path /path/to/espocrm
```

## Configuration

1. In EspoCRM: **Admin → Integrations → CC Inventory**
   - Enter the cc-inventory database **host**, **port**, **database name**, **username**, and **password**
   - Click **Save**, then **Test Connection** to verify connectivity
2. Enable the scheduled job: **Admin → Scheduled Jobs → Inventory: Sync from CC Inventory**
3. Run the initial sync: **Admin → Integrations → CC Inventory → Sync Now**
4. Configure cron (once per minute, as the web server user):
   ```
   * * * * * www-data php /path/to/espocrm/cron.php > /dev/null 2>&1
   ```

## Sync Mechanics

**Pull (nightly scheduled job):**
1. Queries cc-inventory MySQL for each entity type.
2. Upserts EspoCRM records matching on `ccInventoryId` (creates new or updates existing).
3. Customers → Accounts (sets `ccInventoryCustomerId`).
4. Suppliers → Accounts (sets `ccInventorySupplierId`).
5. Saves last sync time and record count to the Integration entity.

**Write-through (AfterSave hooks):**
- Edits to InventoryOrder or InventoryOrderItem in EspoCRM are immediately pushed back
  to cc-inventory via PDO INSERT/UPDATE.
- Edits to InventoryPurchaseOrderItem similarly sync back (tracks `receivedAt` date).
- All internal hook saves use `skipInventorySync` to prevent recursion.

## Development & Testing

Tests require a local EspoCRM installation for the `Espo\Core\*` namespace:

```bash
ESPO_PATH=/path/to/espocrm \
  /path/to/espocrm/vendor/bin/phpunit \
  --configuration phpunit.xml \
  --no-coverage
```

To build a release ZIP:

```bash
scripts/release.sh --version 1.0.0 --espo-path /path/to/espocrm
# Output: releases/espocrm-inventory-v1.0.0.zip
```

## License

MIT — see [LICENSE](LICENSE).

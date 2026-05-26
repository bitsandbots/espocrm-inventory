# Gap Analysis — EspoCRM CC Inventory Module

**As of:** 2026-05-26  
**Module version:** 1.0.0

This document identifies functionality that is absent from the codebase or works incorrectly. Items are ordered by severity.

---

## Bugs (Defects in Existing Code)

### BUG-1 — Source JS out of sync with transpiled output

**Severity:** Medium (visual defect)  
**Status:** Fixed in this release  
**File:** `client/custom/modules/inventory/src/views/panels/inventory-summary.js`

The source `data()` method did not include `hasOrders` or `hasPurchaseOrders` — the Handlebars template requires these to show any content. The transpiled file in `lib/transpiled/` already contained the fix; the source was simply behind.

**Symptom:** The Inventory Summary panel on Account detail view always showed "No open inventory activity." even when open orders or POs existed.

**Fix:** Added `hasOrders` and `hasPurchaseOrders` booleans to `data()` return value.

---

## High Severity

### GAP-1 — `OrderItemWriteThrough` does not sync existing-item updates

**Severity:** High  
**Effort:** Small (~45 min)  
**File:** `custom/Espo/Modules/Inventory/Hooks/InventoryOrderItem/OrderItemWriteThrough.php:31`

The hook returns early for any entity that is not new (`if (!$entity->isNew()) return`). Updating the quantity or price of an existing order item in EspoCRM has no effect in cc-inventory.

**Recommended fix:** Add an `else` branch for existing items that UPDATEs `sales` when `qty` or `price` changes, and reconciles `products.quantity` and `stock` based on the delta.

---

### GAP-2 — No unit tests

**Severity:** High  
**Effort:** Medium (2–4 hours)  
**Directory:** `tests/unit/Espo/Modules/Inventory/` (exists but empty)

The test infrastructure is in place (bootstrap, phpunit.xml, test directory) but no test cases exist. The sync service, all write-through hooks, the low-stock alert, and the DB service all lack coverage.

**Recommended fix:** Add PHPUnit test classes for at minimum:
- `CcInventorySyncServiceTest` — verifies upsert logic with a mock EntityManager
- `LowStockAlertTest` — verifies alert fires only when threshold is crossed
- `PoItemWriteThroughTest` — verifies delta capping and PO status recalc

---

## Medium Severity

### GAP-3 — No `InventoryStockAdjustment` write-through

**Severity:** Medium  
**Effort:** Small (~1 hour)

Stock adjustments created or edited in EspoCRM do not write back to cc-inventory. The `stock` table in cc-inventory is never updated from this path, so cc-inventory's product quantities diverge if adjustments are made in EspoCRM between syncs.

**Recommended fix:** Add `Hooks/InventoryStockAdjustment/StockAdjustmentWriteThrough.php` that INSERTs into `stock` and UPDATEs `products.quantity`.

---

### GAP-4 — Deleted EspoCRM records not propagated to cc-inventory

**Severity:** Medium  
**Effort:** Small (~1 hour)

When an InventoryOrder, InventoryOrderItem, or InventoryPurchaseOrderItem is deleted in EspoCRM, cc-inventory is not notified. The next nightly sync will re-create the record in EspoCRM (since it still exists in cc-inventory).

**Recommended fix:** Add `BeforeDelete` hooks that soft-delete or flag the corresponding cc-inventory row. Alternatively, add a `deleted_at` column to cc-inventory tables and set it from the hook.

---

### GAP-5 — `deleted` field set directly via ORM may not soft-delete correctly

**Severity:** Medium  
**Effort:** Small (investigate + fix if needed)  
**File:** `custom/Espo/Modules/Inventory/Services/CcInventorySyncService.php:89`

`syncProducts()` passes `'deleted' => $row['deleted_at'] !== null` to `saveEntity()`. In EspoCRM ORM, the `deleted` column is a reserved system field managed by the repository layer. Setting it directly in an entity attribute array may not trigger the correct soft-delete behavior and could cause the entity to be invisible in list views.

**Recommended fix:** If a product is deleted in cc-inventory, either skip importing it (exclude `WHERE deleted_at IS NOT NULL`) or call `$this->entityManager->getRepository('InventoryProduct')->remove($entity)` instead of passing the `deleted` attribute.

---

### GAP-6 — Account name-fallback upsert can merge unrelated accounts

**Severity:** Medium  
**Effort:** Small  
**File:** `CcInventorySyncService.php:354-362`

`upsertAccountFromCcInventory()` falls back to a name-match when no account has the given `ccInventoryId`. If two different organizations in cc-inventory and EspoCRM happen to share a name (e.g., "Acme Corp"), the fallback will incorrectly merge them, overwriting the EspoCRM account's `ccInventoryId` with a foreign cc-inventory customer/supplier ID.

**Recommended fix:** Remove the name-based fallback. Only create a new Account when the `ccInventoryId` lookup returns nothing. Accept that new cc-inventory customers will create new EspoCRM Accounts.

---

### GAP-7 — No chunked processing; large datasets may OOM or timeout

**Severity:** Medium  
**Effort:** Medium (~2 hours)  
**File:** `CcInventorySyncService.php` — all `syncX()` methods

Every sync method uses a single unbounded `SELECT ... ORDER BY id`. A cc-inventory database with tens of thousands of products, orders, or sales rows will load everything into a single PHP array, potentially exhausting memory or hitting the EspoCRM scheduled job timeout.

**Recommended fix:** Add `LIMIT` / `OFFSET` pagination (or keyset pagination using `WHERE id > ?`) and process records in batches of 500–1000, flushing the PHP array between batches.

---

## Low Severity

### GAP-8 — No `InventoryProduct` write-through

**Severity:** Low  
**Effort:** Small  

Product edits made in EspoCRM (price changes, description updates) do not push back to cc-inventory. The next nightly sync overwrites the EspoCRM changes with cc-inventory values.

**Note:** Product `quantity` is intentionally read-only in EspoCRM (managed by hooks and sync), so quantity changes are not in scope here.

---

### GAP-9 — No `InventoryCategory` write-through

**Severity:** Low  
**Effort:** Trivial  

Categories created or renamed in EspoCRM do not propagate to cc-inventory.

---

### GAP-10 — Low-stock alert targets all active admins

**Severity:** Low  
**Effort:** Small  
**File:** `Hooks/InventoryProduct/LowStockAlert.php:54-58`

All active EspoCRM admins receive low-stock notifications. There is no way to target a specific user, team, or role.

**Recommended fix:** Add a configurable `notifyUserId` field to the Integration entity, falling back to all admins if unset.

---

### GAP-11 — Inventory summary panel shows only open records

**Severity:** Low  
**Effort:** Trivial  
**File:** `client/custom/modules/inventory/src/views/panels/inventory-summary.js`

The panel filters for `status IN (pending, processing, shipped)` for orders and `status IN (draft, ordered, partial)` for POs. Fulfilled orders and received POs are never visible on the Account record, making the panel less useful for historical lookups.

**Recommended fix:** Add a "Show all" toggle or a separate "Recent Activity" section showing the last 5 records regardless of status.

---

### GAP-12 — Connection timeout not configurable

**Severity:** Low  
**Effort:** Trivial  
**File:** `Services/CcInventoryDbService.php:47`

PDO connection options do not include `PDO::ATTR_TIMEOUT`. Slow or unreachable cc-inventory hosts will block the sync job for the full PHP `default_socket_timeout` (often 60 seconds), which can cause EspoCRM's cron runner to time out entirely.

**Recommended fix:** Add `PDO::ATTR_TIMEOUT => 10` (or a configurable value from the Integration entity) to the PDO options array.

---

### GAP-13 — No retry logic for transient connection failures

**Severity:** Low  
**Effort:** Medium  

If cc-inventory is temporarily unavailable (network blip, DB restart), the sync job fails immediately and records the error. It will not retry until the next scheduled run.

**Recommended fix:** Wrap the `runFullSync()` call in a simple retry loop (max 3 attempts, exponential backoff) within `SyncFromCcInventory::run()`.

---

### GAP-14 — `lastSyncAt` stored in server local time, not UTC

**Severity:** Low  
**Effort:** Trivial  
**File:** `CcInventorySyncService.php:401`

`date('Y-m-d H:i:s')` uses PHP's local server timezone. EspoCRM datetime fields display in the user's configured timezone, but the stored value should be UTC for consistency.

**Recommended fix:** Use `gmdate('Y-m-d H:i:s')` or `(new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')`.

---

## Summary Table

| ID | Description | Severity | Status | Effort |
|----|-------------|----------|--------|--------|
| BUG-1 | Source JS missing hasOrders/hasPurchaseOrders | Medium | Fixed | Done |
| GAP-1 | OrderItemWriteThrough ignores existing-item updates | High | Open | Small |
| GAP-2 | No unit tests | High | Open | Medium |
| GAP-3 | No StockAdjustment write-through | Medium | Open | Small |
| GAP-4 | Deleted records not propagated to cc-inventory | Medium | Open | Small |
| GAP-5 | `deleted` field may not soft-delete correctly | Medium | Open | Small |
| GAP-6 | Account name-fallback can merge wrong records | Medium | Open | Small |
| GAP-7 | No chunking; large datasets may OOM | Medium | Open | Medium |
| GAP-8 | No Product write-through | Low | Open | Small |
| GAP-9 | No Category write-through | Low | Open | Trivial |
| GAP-10 | Low-stock alerts go to all admins | Low | Open | Small |
| GAP-11 | Summary panel hides fulfilled/received records | Low | Open | Trivial |
| GAP-12 | Connection timeout not configurable | Low | Open | Trivial |
| GAP-13 | No retry on transient connection failure | Low | Open | Medium |
| GAP-14 | lastSyncAt stored in local time, not UTC | Low | Open | Trivial |

## Recommended Priority Order

For a production hardening pass:

1. **BUG-1** (Fixed) — shipped in v1.0.0
2. **GAP-2** — unit tests before any further development
3. **GAP-5** — deleted flag behavior needs investigation first; could cause silent data loss
4. **GAP-6** — name-fallback is a silent data correctness issue
5. **GAP-1** — order item write-through completeness
6. **GAP-3** — stock adjustment write-through
7. **GAP-4** — deletion propagation
8. **GAP-7** — chunked sync (required before production use with large datasets)
9. All Low severity items: defer to v1.1 or v1.2

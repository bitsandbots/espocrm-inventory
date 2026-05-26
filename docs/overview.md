# EspoCRM CC Inventory — Overview

## Purpose

EspoCRM CC Inventory is an open-source EspoCRM 9.x custom module that bridges a **cc-inventory MySQL database** into EspoCRM. It provides a read-unified view of inventory, orders, and purchase orders inside the CRM, while keeping cc-inventory as the authoritative data store.

## Goals

- Eliminate duplicate data entry between cc-inventory (warehouse/POS system) and EspoCRM (CRM)
- Surface inventory activity (open orders, purchase orders, stock levels) on EspoCRM Account records
- Preserve cc-inventory write paths while letting EspoCRM operators manage order and PO status
- Notify admins automatically when product stock drops below threshold

## Key Features

| Feature | Description |
|---------|-------------|
| Nightly pull sync | Imports all cc-inventory data into EspoCRM entities via a scheduled job |
| Real-time write-through | Order and PO changes in EspoCRM are immediately pushed back to cc-inventory |
| Low-stock alerts | EspoCRM notifications to all admins when product quantity ≤ `lowStockThreshold` |
| Account mapping | cc-inventory customers → EspoCRM Accounts (type Customer); suppliers → Accounts (type Vendor) |
| Admin UI panel | Integration config, connection test, and manual sync trigger under Admin → Integrations |
| Inventory summary panel | Open orders and open POs displayed on Account detail view |

## New Entities

| Entity | Purpose |
|--------|---------|
| `InventoryProduct` | Products with SKU, stock quantity, pricing, low-stock threshold |
| `InventoryCategory` | Product groupings |
| `InventoryOrder` | Customer orders (pending → processing → shipped → fulfilled / cancelled) |
| `InventoryOrderItem` | Line items within orders (qty, price, product link) |
| `InventoryPurchaseOrder` | Supplier POs (draft → ordered → partial → received / cancelled) |
| `InventoryPurchaseOrderItem` | Line items within POs (qtyOrdered, qtyReceived, unitCost) |
| `InventoryStockAdjustment` | Manual stock-level adjustments with comments |

## Core Entity Extensions

The standard EspoCRM `Account` entity is extended with:

| Field | Purpose |
|-------|---------|
| `ccInventoryCustomerId` | Foreign key to cc-inventory `customers.id` |
| `ccInventorySupplierId` | Foreign key to cc-inventory `suppliers.id` |
| `inventoryOrders` (panel) | hasMany relationship panel — shows linked orders |
| `inventoryPurchaseOrders` (panel) | hasMany relationship panel — shows linked purchase orders |

## Requirements

- EspoCRM 9.x
- PHP 8.3+ with `ext-pdo_mysql`
- Network access from the EspoCRM server to the cc-inventory MySQL host
- Cron configured to run `cron.php` every minute (standard EspoCRM requirement)

## License

MIT — see [LICENSE](../LICENSE). Open-source lead-gen vehicle for CoreConduit consulting.

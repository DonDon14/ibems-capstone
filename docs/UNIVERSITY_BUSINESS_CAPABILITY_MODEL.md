# University Business Capability Model

## Decision

IBEMS uses one transaction, payment, debt, receipt, and audit core for every university business. A store is configured with multiple independent capabilities instead of one rigid business-type enum.

This matters because a cafeteria can simultaneously sell packaged retail goods, prepared meals, and university-produced dairy items. A water station can sell bottled water, perform refill services, and collect a separate refundable container deposit.

## Research basis

- Square distinguishes fixed item variations (such as size) from checkout modifiers and add-ons. IBEMS therefore keeps package/size variants separate from future meal customizations: https://squareup.com/help/us/en/article/5119-create-and-manage-item-modifiers
- Odoo activates restaurant behavior per POS while keeping the same POS foundation: https://www.odoo.com/documentation/17.0/applications/sales/point_of_sale/restaurant.html
- Odoo and ERPNext model manufactured products from a bill of materials, rather than treating ingredients as product variants: https://docs.frappe.io/erpnext/bill-of-materials and https://www.odoo.com/documentation/19.0/applications/sales/point_of_sale/shop/serial_numbers.html
- Odoo and ERPNext require lot/batch identity for expiration and recall traceability: https://www.odoo.com/documentation/17.0/applications/inventory_and_mrp/inventory/product_management/product_tracking/expiration_dates.html and https://docs.frappe.io/erpnext/batch
- Odoo models a refundable deposit as a separate service line that can later be reversed. IBEMS follows the same audit-friendly separation: https://www.odoo.com/documentation/18.0/applications/sales/rental/manage_deposits.html

## Implemented foundation

### Store capabilities

- `retail`
- `food_service`
- `production`
- `refill_service`
- `container_deposits`

Capabilities are descriptive and combinable. They do not fork the POS or create incompatible reporting paths.

### Product behavior

Every product now records:

- `item_type`: stocked, prepared, manufactured, service, or refundable deposit
- `stock_policy`: tracked or untracked
- `unit_code`: piece, bottle, can, pack, serving, meal, tray, gallon, liter, container, or service

Tracked items are atomically deducted and create inventory movements. Untracked items can be sold at zero stock and do not create a false inventory movement. Services and deposits are always untracked.

### Historical integrity

Transaction items snapshot product name, SKU, variant, unit, and item type. Renaming a product later will not silently change what a historical receipt represented.

## Business mapping

| Operation | Capability mix | Product examples |
| --- | --- | --- |
| Cafeteria | Retail + Food service | bottled drinks as tracked bottles; daily viand as tracked servings when a cooked batch is counted, or untracked prepared items when made to order |
| Gatas 955 | Retail + Production | milk size/package variants as tracked manufactured bottles or packs |
| Water station | Retail + Refill service + Container deposits | bottled water as tracked bottles; refill as an untracked gallon service; container deposit as a separate deposit line |
| School supplies | Retail | tracked pieces, packs, and size/package variants |

## Next domain modules

The current release establishes accurate catalog and checkout semantics. The following should be added as separate modules rather than overloaded product fields:

1. Recipes/BOM and production batches for ingredient consumption and finished-goods yield.
2. Lot/batch numbers, manufacture dates, expiration dates, FEFO picking, and recall search.
3. Daily cafeteria offerings with business-date availability and optional planned quantity.
4. Container custody ledger with issue, return, loss, deposit collection, and deposit refund events.
5. Modifiers/add-ons for meal choices and extras, with their own pricing and optional ingredient effects.
6. Decimal quantity support only when the university decides to sell partial weight or volume; it requires a coordinated quantity migration across stock, cart, movements, receipts, and reports.

These modules should reuse the existing transaction and audit core, preserve immutable snapshots, and be enabled only for stores that need them.

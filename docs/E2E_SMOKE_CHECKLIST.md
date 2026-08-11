# IBEMS End-To-End Smoke Checklist

Last updated: 2026-07-01

Use this checklist after migrations, seeded data changes, or broad UI/backend edits. Run against `http://localhost:8080` unless `.env` is intentionally changed.

## Setup

- [ ] Start MySQL from XAMPP.
- [ ] Run `php spark migrate`.
- [ ] Run `php spark serve --port 8080`.
- [ ] Open `http://localhost:8080`.
- [ ] Confirm demo password is `123456`.

## Authentication And Role Switching

- [ ] Log in as `admin@ibems.local`.
- [ ] Confirm Admin Dashboard loads real KPI cards and alerts.
- [ ] Use Switch Portal if the account has multiple roles; confirm the selected portal loads.
- [ ] Log out and log in as `store.main@ibems.local`.
- [ ] Log out and log in as `accounting@ibems.local`.
- [ ] Log out and log in as `maria.santos@ibems.local`.

## Store Workflow

- [ ] In Store Dashboard, confirm an assigned store is visible.
- [ ] Open today's store day with opening cash and opening e-cash if not already open.
- [ ] Go to Store Inventory.
- [ ] Create a product with unique SKU and optional barcode.
- [ ] Confirm supplier, location/bin, low-stock threshold, and opening stock persist.
- [ ] Stock-in the product and confirm stock movement appears.
- [ ] Adjust stock and confirm the adjustment appears in recent movements.
- [ ] Go to POS and confirm products load.
- [ ] Add product to cart.
- [ ] Complete a cash sale.
- [ ] Confirm receipt modal shows transaction reference, date, store, items, total, and QR/link.
- [ ] Open Store History and confirm the transaction appears.
- [ ] Open the receipt from history and confirm it matches the POS receipt data.

## Debt Purchase And User Portal

- [ ] Log in as a faculty/staff user.
- [ ] Set or change the debt authorization PIN from User Dashboard.
- [ ] Log back in as store staff.
- [ ] Select the same faculty/staff user for debt payment in POS.
- [ ] Confirm the projected debt and remaining credit preview is shown.
- [ ] Enter an incorrect PIN five times and confirm debt authorization locks for
  15 minutes without creating a transaction.
- [ ] Enter the user's PIN and complete the debt transaction.
- [ ] Log in as the user.
- [ ] Confirm User Dashboard debt status, current debt, credit limit, available credit, and recent transaction update.
- [ ] Open User History and confirm filters, cashbook entry, transaction details, and receipt link work.

## Accounting Workflow

- [ ] Log in as `accounting@ibems.local`.
- [ ] Open Accounting Debts.
- [ ] Confirm the debt customer appears with correct status and balance.
- [ ] Open a debt investigation, record findings and evidence, then confirm the
  recommending user cannot approve their own correction.
- [ ] Approve the recommendation as a different Accounting user and confirm a
  linked reversal reduces debt without changing or deleting the original sale.
- [ ] Use `accounting.supervisor@ibems.local` for independent correction
  approval and deduction-period finalization.
- [ ] Open the user debt profile/history.
- [ ] Apply a partial deduction and confirm the debt cashbook updates.
- [ ] Apply a full deduction or settlement preview/apply flow for the selected month.
- [ ] Export or print the settlement summary.
- [ ] Confirm User Portal reflects paid, unpaid, partially settled, or over-limit status as applicable.

## Admin Oversight

- [ ] Log in as `admin@ibems.local`.
- [ ] Open Admin Stores and inspect the store details operational snapshot.
- [ ] Open Admin Products and confirm it is read-only oversight.
- [ ] Open Admin User Management and confirm the edited user balance/roles are visible.
- [ ] Open Admin Audit and confirm POS, inventory, settlement, and user change events are visible.

## Expected Result

- [ ] No PHP errors, browser console errors, or broken route responses occur.
- [ ] Transaction totals reconcile between POS receipt, Store History, User History, and Accounting Debt cashbook.
- [ ] Stock quantities and inventory movements reconcile after sale, stock-in, and adjustment.
- [ ] Debt balances reconcile after purchase and deduction/settlement.

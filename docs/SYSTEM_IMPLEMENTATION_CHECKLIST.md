# IBEMS System Implementation Checklist

Last updated: 2026-06-30
Active project path: `D:\xampp\htdocs\ibems-tailwind-test`

Use this file as the durable work tracker when the chat context is compressed. Update each checkbox as work is completed, and add short notes under the relevant module instead of relying only on chat history.

## Current Baseline

- [x] Active project confirmed as `D:\xampp\htdocs\ibems-tailwind-test`.
- [x] Role-based portals exist for Admin, Store, Accounting, and User.
- [x] Login redirects to the correct role flow and Dashboard-first behavior was corrected.
- [x] Multi-role role switcher exists and is visible in portal headers.
- [x] Store Dashboard quick access and Store Start Work actions use the improved animated action UI.
- [x] Store Inventory has product filters, stock summary, recent stock activity, manage modal, stock adjustment, stock-in, product creation readiness, duplicate guards, image support, low-stock threshold, supplier, and location/bin.
- [x] Store POS has category tabs, search, barcode/QR scanner, daily store-day open/close lock, debt customer search, stock-aware cart, confirmation modal, receipt modal, print/view receipt, and accurate immediate receipt transaction metadata.
- [x] Store History has been polished previously and should be regression-tested with real transactions.

## Phase 1 - Stabilize Store Workflow

- [x] Store Daily Operations: Add `store_day_sessions` migration/model for real daily opening and closing.
- [x] Store Daily Operations: POS readiness now requires today's open store session instead of only a one-time initial opening balance.
- [x] Store Daily Operations: Add open-day UI with opening cash and opening e-cash.
- [x] Store Daily Operations: Add close-day UI with counted cash/e-cash, expected values, and variance recording.
- [x] Store Daily Operations: Lock POS transactions and cash movements when today&apos;s store day is not open.
- [x] Store Daily Operations: Store Dashboard and Reports now surface daily opening/session context.
- [x] Inventory: Persist product low-stock threshold.
- [x] Inventory: Persist supplier and location/bin.
- [x] Inventory: Show supplier and bin metadata in product list.
- [x] POS: Use product `low_stock_threshold` instead of hardcoded stock threshold.
- [x] POS: Include supplier/bin in product search.
- [x] POS: Show supplier/bin as compact product-card metadata.
- [x] POS: Return and display real `client_txn_id`, `created_at`, and backend total in immediate receipt.
- [x] POS: Add a checkout preflight refresh that revalidates cart stock against latest `/store/products` before opening the confirmation modal.
- [x] POS: Show clearer credit impact for debt checkout before final confirmation: current debt, transaction amount, projected debt, remaining credit.
- [x] POS: Require debtor-owned authorization PIN only for debt payment, with server-side verification and failed-attempt audit logging.
- [x] POS: Surface missing debtor PIN setup before checkout without exposing PIN hashes.
- [x] POS: Add empty/error/loading states for payment method load failures.
- [x] Store History: Verify transaction details and receipt links use the same receipt data contract as POS.
- [x] Store Reports: Verify sales, stock-in cost, projected profit, payment breakdown, and cash movement summaries reconcile after test transactions.

## Phase 2 - Accounting Workflow

- [x] Accounting Debts: Review debt list, profile drawer/page, payment/deduction flows, and CSV import validation.
- [x] Accounting Debts: Add clearer settlement status indicators: pending, partially settled, settled, over-limit.
- [x] Accounting Debts: Add import preview validation before applying CSV rows.
- [x] Accounting Settlement: Review preview/apply flow for duplicate month protection and user-facing confirmation details.
- [x] Accounting Settlement: Add export/print summary for settlement runs.
- [x] Accounting Dashboard: Align KPI cards with real debt cashbook and settlement data.
- [x] Accounting Dashboard: Add alerts for over-limit users, stale debts, and failed imports.

## Phase 3 - Admin Workflow

- [x] Admin Dashboard: Review metrics against real store/accounting/user data and remove any placeholder values.
- [x] Admin Stores: Audit store create/edit/toggle-status flow, officer assignment, and store details page.
- [x] Admin Store Details: Add operational snapshot: assigned officers, active products, today sales, debt transactions, low-stock count.
- [x] Admin User Management: Review create/edit/import flows, role assignments, credit limit, salary, and status toggles.
- [x] Admin User Management: Ensure record-click and explicit actions are not redundant or confusing.
- [x] Admin Products: Converted admin product management into a read-only oversight view; Store Inventory remains the operational product management surface.
- [x] Admin Audit: Add an admin-facing audit log screen for product changes, stock adjustments, POS transactions, settlement runs, and user edits.

## Phase 4 - User Portal

- [x] User Dashboard: Review debt summary, recent purchases, credit limit, and current balance presentation.
- [x] User Portal: Add self-service debt PIN setup/change flow; require current password when changing an existing PIN.
- [x] User History: Verify filters, receipt links, and transaction details.
- [x] User Cashbook: Confirm whether cashbook route has a complete UI; implement or remove route if incomplete.
- [x] User Receipt: Align receipt display with Store receipt and shared `receipt-standard.js`.
- [x] User Portal: Add clear debt status messaging for paid, unpaid, partially settled, and over-limit states.

## Phase 5 - Cross-System Data Integrity

- [ ] Create end-to-end smoke checklist: login, switch role, inventory create, stock-in, POS sale, receipt, history, accounting debt update, settlement.
- [ ] Add backend validation tests for transaction creation: stock race, disabled payment method, missing opening balance, invalid debt customer, insufficient credit.
- [x] Add migration-backed storage for hashed debt authorization PINs on user accounts.
- [ ] Add backend validation tests for inventory: duplicate SKU, duplicate barcode, low-stock threshold, supplier/location persistence, stock movement creation.
- [ ] Add seed data covering low-stock, out-of-stock, debt customers, over-limit customer, multiple stores, and multi-role users.
- [ ] Normalize all dates/times and money formatting through shared helpers where practical.
- [ ] Review all `ADMIN` access paths to ensure admins can inspect without accidentally bypassing store/accounting constraints.

## Phase 6 - Usability And Polish

- [ ] Standardize loading, empty, error, success, and confirmation patterns across Admin, Store, Accounting, and User pages.
- [ ] Standardize table controls: search, filters, reset, pagination or result count.
- [ ] Standardize modal layout and close/discard behavior.
- [ ] Review mobile responsiveness for Store POS, Inventory, Accounting Debts, and Admin User Management.
- [ ] Add keyboard-friendly actions for POS scanning, cart controls, modal confirmation, and table search.
- [ ] Replace remaining unclear labels, placeholder copy, and redundant controls.

## Phase 7 - Release Hygiene

- [ ] Run `php -l` on changed PHP files before every handoff.
- [ ] Run `node --check` on changed JS files before every handoff.
- [ ] Run `php spark migrate` after adding migrations.
- [ ] Run a browser smoke test on `http://localhost:8080` after major UI changes.
- [ ] Update this checklist after each completed implementation.
- [ ] Keep unrelated user changes intact; do not revert dirty files unless explicitly requested.
- [ ] Before pushing to GitHub, review `git status --short`, summarize changed scope, then commit intentionally.

## Recommended Next Work Order

1. Finish Store workflow validation:
   - POS stock preflight refresh.
   - Debt checkout projected-credit confirmation.
   - Store History/Reports reconciliation check.
2. Move to Accounting:
   - Debt list/profile flow.
   - CSV import preview validation.
   - Settlement run summary/export.
3. Move to Admin:
   - Store details operational snapshot.
   - User Management action clarity.
   - Audit log viewer.
4. Move to User:
   - Debt status clarity.
   - Transaction history and receipt consistency.

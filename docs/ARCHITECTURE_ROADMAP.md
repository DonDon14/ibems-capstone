# IBEMS Architecture Roadmap

The current CodeIgniter structure is suitable for the capstone, but the largest controllers and browser scripts should be decomposed before major new feature work.

This roadmap intentionally favors small, behavior-preserving extractions. Do not combine structural refactoring with changes to financial, inventory, authorization, or settlement rules.

## Completed Foundation

- Shared authenticated portal shell extracted to `app/Views/components/portal_shell.php`.
- Role layouts now supply portal configuration and navigation instead of duplicating the shell.
- Modern UI compatibility layer added in `public/assets/css/modern-ui.css`, retaining USTP color while adopting the NXX-style product hierarchy.
- Shared page-header component introduced and adopted by the Admin dashboard, Store dashboard, and Product Oversight.
- Shared statistic card component is used across portal dashboards.
- Shared data-state component provides loading, empty, error, and success presentation.
- Shared CSS covers buttons, tables, filters, metric cards, status pills, and modals.
- Shared JavaScript covers CSRF, formatting, layout, and receipt behavior.
- `StoreAccessService` now owns the first extracted active-store resolution boundary used by `StoreController`.
- University debt policy, configurable deduction periods, partial carryovers,
  investigation, correction authority, and retention defaults are defined in
  `docs/DEBT_POLICY_AND_IMPLEMENTATION_PLAN.md`.

## Locked Portal Direction

IBEMS should present four workspaces:

1. System Administration
2. Store Operations
3. Accounting and Debt Processing
4. Employee/Customer

`STORE_SYSTEM` and `STORE_SUPERVISOR` remain distinct permissions but should
share the Store Operations workspace. Accounting preparation and payroll-result
confirmation should initially share one workspace with separate permissions.
Do not create a fifth portal until the university confirms a strict departmental
boundary.
- University debt policy, configurable deduction periods, partial carryovers,
  investigation, correction authority, and retention defaults are defined in
  `docs/DEBT_POLICY_AND_IMPLEMENTATION_PLAN.md`.

## Locked Portal Direction

IBEMS should present four workspaces:

1. System Administration
2. Store Operations
3. Accounting and Debt Processing
4. Employee/Customer

`STORE_SYSTEM` and `STORE_SUPERVISOR` remain distinct permissions but should
share the Store Operations workspace. Accounting preparation and payroll-result
confirmation should initially share one workspace with separate permissions.
Do not create a fifth portal until the university confirms a strict departmental
boundary.

## Priority 1: Controller Boundaries

### Store

Extract from `StoreController` in independently tested slices:

1. Continue adopting `StoreAccessService` for accessible-store lists in read endpoints and layouts.
2. `StoreDayService` for opening, closing, expected totals, and variance review state.
3. `InventoryService` for product creation, editing, restock, and adjustments.
4. `StoreReportingService` for report aggregation and transaction history queries.
5. Keep HTTP validation and response shaping in focused controllers.

Suggested eventual controllers:

- `StoreDashboardController`
- `StoreDayController`
- `StoreInventoryController`
- `StoreReportsController`
- `StoreSettingsController`

### Admin

Extract:

1. `AdminOverviewService`
2. `UserAdministrationService`
3. `StoreAdministrationService`
4. `AuditQueryService`

Suggested eventual controllers:

- `AdminDashboardController`
- `AdminUsersController`
- `AdminStoresController`
- `AdminProductsController`
- `AdminAuditController`

### Accounting

Extract:

1. `DebtQueryService`
2. `DebtAdjustmentService`
3. `SettlementService`
4. `SalaryImportService`
5. `AccountabilityService`

Settlement, deductions, and direct-payment behavior require transaction tests before and after extraction.

## Priority 2: Browser Modules

Split scripts by capability while preserving one entry file per page.

### Store POS

- API client
- Store-day state
- Product catalog/search
- Cart
- Checkout
- Debt authorization
- Receipt
- Modal/focus management

### Accounting Debts

- Filters and list rendering
- Profile/history
- Settlement
- CSV preview/import
- Debt adjustments
- Accountability views

### Store Inventory

- Product query/rendering
- Product form
- Stock movement
- Categories
- Image preview/upload

Use native ES modules only after confirming the local and deployed browser targets. Until then, small namespaced modules can avoid introducing a bundler migration.

## Priority 3: UI Components

Add components when at least two real screens share the same contract:

- Page heading and action bar
- Data-state panel
- Standard modal frame
- Form field with validation message
- Status pill
- Pagination controls

Avoid creating wrappers that merely rename a single HTML element.

## Priority 4: Quality Gates

Add or strengthen:

- Controller/service unit tests
- Database-backed transaction tests
- Route authorization audit coverage
- A browser smoke test for all four workspaces, including both Store Operator
  and Store Supervisor permission paths
- A lightweight convention check for duplicated shell markup and inline scripts/styles
- Responsive checks for Store POS, Accounting Debts, Store Admin, and Admin Products

## Extraction Rules

1. Preserve current routes and response contracts during refactoring.
2. Do not change database data or migration history as part of a code-organization change.
3. Keep authorization at both route and domain boundaries.
4. Wrap financial and inventory writes in database transactions.
5. Compare outputs before and after each extraction.
6. Commit each domain extraction separately after validation.

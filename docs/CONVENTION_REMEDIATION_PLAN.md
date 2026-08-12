# IBEMS Convention Remediation Plan

Last updated: 2026-08-11

## Objective

Bring the current IBEMS implementation into consistent alignment with
`docs/UI_CONVENTIONS.md` through small, behavior-preserving changes. This file
is the single active structural-remediation roadmap.

This plan deliberately begins with automated checks and low-risk presentation
work. Controller, service, and browser-module extraction starts only after the
existing behavior is protected by suitable tests.

## Locked Safety Boundaries

Every phase must preserve:

- Existing URLs, HTTP methods, request fields, response payloads, and status codes.
- Role filters and domain-level authorization checks.
- Store, user, accounting, and administrator workspace responsibilities.
- Financial, inventory, settlement, deduction, and store-day calculations.
- Database schema, migration history, persisted identifiers, and audit history.
- Existing transaction boundaries for financial and inventory writes.
- Server-rendered escaping, JavaScript output escaping, and shared CSRF handling.

Do not combine a convention cleanup with a database migration, policy change,
calculation change, permission change, or production-data operation.

## Baseline

The repository already has:

- Role-specific layouts backed by one shared portal shell.
- Shared page-header, statistic-card, form-field, and data-state components.
- Shared button, table, status, modal, formatting, CSRF, and dialog behavior.
- Explicit route-level role filters.
- Database transactions around important financial and inventory writes.
- Passing PHP and JavaScript syntax checks as of 2026-08-11.

The main remediation targets are incomplete shared-component adoption,
page-level redefinition of global CSS, inconsistent labels and modal source
semantics, monolithic controllers, and monolithic browser scripts.

## Phase 0 - Establish Repeatable Baselines

Risk: Low

### Work

- Record the current route list and role filters as a comparison fixture.
- Record representative JSON response shapes for read-only endpoints.
- Identify critical write-flow tests for POS, store-day open/close, inventory,
  debt repayment, deductions, settlements, and variance review.
- Add a documented command sequence for PHP lint, JavaScript syntax checks,
  unit tests, route audit, data audit, and preflight checks.
- Keep environment-dependent checks clearly separated from syntax-only checks.

### Acceptance criteria

- A future refactor can compare routes and response contracts before and after.
- Critical financial and inventory workflows have an identified automated or
  manual regression check.
- No application behavior or data is changed.

## Phase 1 - Add Automated Convention Checks

Risk: Low

### Work

Add a read-only repository check, preferably as a small PHP command or script,
that reports:

- Authenticated views that do not extend a role layout.
- Duplicated portal-shell markup.
- Inline `<style>` and inline `<script>` blocks outside an explicit allowlist.
- Page stylesheets that define canonical global button, table, status, or modal
  selectors.
- Modal roots that omit source-level `role="dialog"`, `aria-modal="true"`, or a
  valid accessible title relationship.
- Icon-only or close buttons without an accessible name.
- Select, date, search, and ordinary form controls without an associated label.
- New data-driven screens that do not use or preserve the shared data-state
  structure.
- Controller and JavaScript files above agreed warning thresholds.

Start the checker in report-only mode. Promote only stable, low-false-positive
rules to blocking checks.

Suggested thresholds:

- Controller warning: 800 lines.
- Browser entry-file warning: 700 lines.
- View warning: 350 lines.

### Allowlist rules

The allowlist must name a file and reason. Initial candidates:

- CodeIgniter framework error templates.
- Isolated printable receipt documents that require embedded print CSS.
- Dynamic progress widths where a CSS custom property or semantic `<progress>`
  element is not yet practical.

### Acceptance criteria

- The checker exits successfully for approved legacy findings in report mode.
- New violations are visible in local and CI output.
- Every exception has a documented reason; no directory-wide blanket exception
  is allowed.

## Phase 2 - Low-Risk UI Semantics

Risk: Low

### Work

Apply source-level accessibility and structural fixes without redesigning pages:

- Add visible labels to filters and form controls that currently rely only on
  placeholders or `aria-label`.
- Add explicit modal roles, accessible titles, and close-button labels in PHP
  markup instead of relying solely on `app-layout.js` repair behavior.
- Add `aria-hidden="true"` to decorative icons where missing.
- Add `aria-current="page"` through the shared navigation renderer where needed.
- Verify that action controls use `<button>` and navigation uses `<a>`.
- Preserve native select and date inputs as the submitted controls behind
  progressive enhancement.

Initial screen order:

1. Admin Products and Admin Stores.
2. Store Inventory and Store History.
3. Store POS and Store Staff Records.
4. Accounting Debts.
5. User Dashboard and User History.

### Acceptance criteria

- Controls retain the same IDs, values, request behavior, and JavaScript hooks.
- Every modal is correctly named before JavaScript initializes.
- Keyboard navigation and focus behavior still work.
- No route, response, calculation, or persistence behavior changes.

## Phase 3 - Shared Header and Data-State Adoption

Risk: Low to Medium

### Work

- Replace manually duplicated primary page headings with
  `components/page_header.php`.
- Replace custom initial loading, empty, error, and success markup with
  `components/data_state.php` where the shared component fits the contract.
- Make JavaScript replacements retain `.data-state` structure and semantics.
- Keep domain-specific recovery actions and explanatory text.
- Do not force the component onto receipts, scanners, or specialized workflow
  panels where the interaction contract is materially different.

Adopt one portal at a time so visual regressions remain bounded.

### Acceptance criteria

- Page hierarchy and action placement are consistent across each portal.
- Every data-driven section has loading, empty, error, and success behavior.
- Existing element IDs required by JavaScript remain stable.
- Responsive behavior is checked at 360px and a representative desktop width.

## Phase 4 - Consolidate the CSS Ownership Model

Risk: Medium

### Target ownership

- `app.css`: canonical product components and compatibility contracts.
- `modern-ui.css`: preferred global visual treatment and responsive refinements.
- Tailwind utilities: focused page composition and spacing.
- Page stylesheets: only genuinely page-specific layout or visualization rules.

### Work

- Inventory every page-level definition of shared button and modal selectors.
- Compare computed styles before moving or deleting any rule.
- Move legitimate global improvements into the canonical global layer.
- Namespace truly page-specific variants instead of redefining `.primary-btn`,
  `.secondary-btn`, or modal roots globally.
- Choose whether the unused `.tw-*` component classes have a real supported
  role. Adopt them consistently or remove them in a separate mechanical change.
- Preserve stylesheet load order until visual equivalence is confirmed.

### Acceptance criteria

- Canonical selectors have one intentional owner.
- Page stylesheets cannot silently change shared controls elsewhere.
- Representative screenshots or manual visual checks show no unintended
  changes across all portals.
- Tailwind builds without changing the generated asset unexpectedly.

## Phase 5 - Extract Inline Page Behavior

Risk: Low to Medium

### Work

- Move receipt-page inline JavaScript into matching external page scripts.
- Keep printable-window markup isolated when embedding print CSS and scripts is
  required by the new document context.
- Replace safe dynamic `style` attributes with CSS custom properties or semantic
  progress elements where this improves clarity without breaking rendering.
- Keep framework-owned CodeIgniter error templates outside application cleanup.

### Acceptance criteria

- Receipt loading, printing, and error handling remain unchanged.
- No new global variables or duplicated event handlers are introduced.
- Convention-check exceptions shrink and remain explicitly justified.

## Phase 6 - Protect Domain Behavior Before Backend Extraction

Risk: Medium

### Work

Add focused tests around the behavior that will be moved out of controllers:

- Accessible-store resolution.
- Store-day open, close, expected totals, and variance review.
- Product creation, update, restock, and stock adjustment.
- Store reports and transaction-history response contracts.
- Admin user and store management.
- Debt adjustment, direct repayment, deductions, settlement, and salary import.
- Audit-event creation for critical actions.

Include success, validation failure, authorization failure, duplicate/idempotent
requests, and transaction rollback cases where applicable.

### Acceptance criteria

- Tests assert outputs and persisted effects, not internal method structure.
- Financial and inventory transaction behavior is covered before extraction.
- Current legacy behavior is documented where changing it would require a
  separate product or policy decision.

## Phase 7 - Extract Backend Domains

Risk: Medium to High

Perform one independently reviewable extraction at a time.

### Store sequence

1. Expand `StoreAccessService` adoption.
2. Extract `StoreDayService`.
3. Extract `InventoryService`.
4. Extract `StoreReportingService`.
5. Split focused controllers only after service contracts stabilize.

### Admin sequence

1. Extract `AdminOverviewService`.
2. Extract `UserAdministrationService`.
3. Extract `StoreAdministrationService`.
4. Extract `AuditQueryService`.

### Accounting sequence

1. Extract `DebtQueryService`.
2. Extract `AccountabilityService`.
3. Extract `SalaryImportService`.
4. Extract `DebtAdjustmentService`.
5. Extract `SettlementService` last because it has the highest financial risk.

### Rules for every extraction

- Preserve controller method signatures until routes are deliberately reviewed.
- Keep response construction and HTTP codes at the controller boundary.
- Keep authorization checks at both route and domain boundaries.
- Preserve database transaction scope and locking behavior.
- Compare tests, routes, JSON shapes, and relevant persisted effects before and
  after the change.
- Do not perform two domain extractions in one commit.

### Acceptance criteria

- Controllers primarily validate HTTP input, invoke a domain service, and shape
  the response.
- Domain services are testable without rendering a view.
- All baseline comparisons remain equivalent.

## Phase 8 - Split Browser Modules

Risk: Medium

Keep one page entry point and split internal capabilities without changing the
global asset-loading contract until browser support is confirmed.

### Store POS sequence

- API client and request error normalization.
- Store-day state.
- Product catalog and search.
- Cart and stock preflight.
- Checkout and debt authorization.
- Receipt rendering and printing.
- Modal and focus handling.

### Accounting Debts sequence

- Filters and list rendering.
- Profile and history.
- Deduction workflow.
- Settlement history.
- CSV preview and import.
- Investigations and debt adjustments.

### Store Inventory sequence

- Product query and rendering.
- Product form state.
- Stock movements.
- Categories and image handling.

### Acceptance criteria

- Existing script URLs and page initialization remain stable.
- No duplicate listeners or requests are introduced.
- Escaping is centralized and applied to all generated database/user content.
- Page-level smoke tests pass before and after each extraction.

## Phase 9 - Documentation and Enforcement Completion

Risk: Low

### Work

- Add a concise current architecture summary to `README.md` covering the role
  model, services, frontend stack, and implemented workflows.
- Add a small contributor-facing section to `README.md` linking to the UI
  conventions, this remediation plan, and validation commands.
- Add `.editorconfig` and adopt PHP/JavaScript formatters only after agreeing on
  rules and verifying that the first formatting pass is isolated from behavior
  changes.
- Promote stable convention checks from report-only to blocking.

### Acceptance criteria

- A new contributor can identify the source of truth and validation commands
  from the README.
- Documentation describes the current system rather than a historical snapshot.
- CI blocks new high-confidence convention violations.

## Validation Matrix

Run checks in proportion to the phase:

| Change type | Required validation |
| --- | --- |
| Documentation only | Markdown review, link/path verification |
| Convention checker | Checker fixtures, PHP/JS syntax checks |
| Markup/accessibility | PHP lint, JS syntax, keyboard and responsive smoke test |
| CSS consolidation | Tailwind build, computed-style/visual comparison, responsive smoke test |
| Browser extraction | JS syntax, focused page workflow, request/response comparison |
| Controller/service extraction | Unit tests, transaction tests, route audit, response comparison |
| Financial-domain extraction | All backend checks plus persisted-effect and rollback verification |

Before any publish or deployment, separately review the working tree and obtain
the required authorization. This plan does not authorize Git publication,
database operations, or deployment.

## Recommended First Delivery

The first implementation slice should contain only:

1. A report-only convention checker.
2. Checker tests or representative fixtures.
3. A documented local command.
4. No application markup, CSS, controller, service, database, or workflow change.

This creates a measurable baseline before remediation begins and is the lowest
risk way to prevent further convention drift.

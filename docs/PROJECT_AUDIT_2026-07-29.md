# IBEMS Project Audit — July 29, 2026

## Scope

- PHP syntax across application and test sources
- JavaScript syntax across application assets
- Registered CodeIgniter routes
- PHPUnit suite
- Authenticated Admin and Store page smoke tests
- Horizontal viewport overflow
- Exposed machine identifiers
- Dropdown, date, dialog, and filter conventions
- Native browser prompt, confirm, and alert usage

## Verified

- 177 PHP files parse successfully.
- 25 JavaScript files parse successfully.
- CodeIgniter routes register successfully.
- 41 PHPUnit tests pass with 238 assertions.
- All 34 migrations complete on a newly created empty MariaDB database.
- `InitialSeeder` completes on that fresh database and creates 8 users, 2 stores, 9 products, and 3 transactions.
- A second migration run is clean, confirming migration idempotence.
- Seventeen representative authenticated Admin, Store, User, and Accounting screens load without body-level horizontal overflow at desktop and 390px mobile viewports.
- The custom Audit action dropdown and date picker work at mobile width; the date popover remains inside the viewport.
- The tested User dashboard emits no browser console errors.
- No uppercase underscore-delimited machine identifiers remain exposed on those representative screens.

## Fixes applied

- Humanized Audit action and entity labels while retaining raw values for filtering.
- Removed raw action codes from the primary Audit table presentation.
- Added shared identifier formatting to `IbemsFormat`.
- Made dropdown menus size to readable content and prevented horizontal menu scrolling.
- Added Audit date-range validation and fetch/network failure handling.
- Added Escape handling for the Audit detail dialog.
- Replaced application-level native prompts, confirms, and alerts with the shared accessible `IbemsDialog`.
- Added responsive filter-grid constraints and explicit date placeholders.
- Corrected the shared application grid so intrinsically wide page content can shrink at mobile breakpoints.
- Added dashboard-specific minimum-width constraints to prevent cards and panels from widening the mobile page.
- Normalized the seeded user batch columns so fresh installations no longer fail with a column-count mismatch.
- Added integration coverage for role enforcement, cross-store access, POS stock and debt updates, debt cashbook entries, accounting deductions, credit-limit changes, monthly settlements, duplicate settlement protection, and store-day open/close rules.

## Local environment recovery

- Repaired corrupted MariaDB privilege-table indexes in `mysql.tables_priv`, `mysql.db`, and `mysql.procs_priv`.
- Backups were retained under `D:\xampp\mysql\recovery-backups`.
- The original `ibems_tailwind_test` database remained intact with 21 tables, 8 users, and 4 stores.
- The isolated validation database was removed after testing, and `.env` was restored to `ibems_tailwind_test`.

## Remaining risk

- The PHPUnit suite is small relative to the controller and JavaScript surface. High-risk financial, inventory, settlement, and authorization workflows need additional integration tests.
- PHPUnit reports no installed code-coverage driver, so line and branch coverage cannot currently be measured.
- Visual smoke testing covers representative authenticated pages, not every modal state or every data combination.
- Technical codes remain visible on Store Settings where the code itself is an editable configuration value; this is intentional rather than a display-title leak.

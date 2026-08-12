# IBEMS 15-Day Operations Scenario

Run date: 2026-08-12  
Scenario period: 2026-07-15 through 2026-07-29  
Scenario key: `SCN15-2026-07`

## Executed coverage

- Four active stores, each with an assigned store officer.
- Sixty closed store-day sessions: 15 dates for every store.
- One dedicated acceptance product in every store, including JL Store, which previously had no product.
- Seventy-two completed transactions: 60 cash sales and 12 employee-debt sales.
- Cash replenishment movements and inventory sale movements for every store.
- Two debt-PIN changes with audit events.
- One purchase attempt rejected because PHP 500 exceeded PHP 400 available credit.
- One cash repayment, one investigated duplicate charge with independent approval and reversal, and one finalized salary-deduction period.
- One deliberate PHP 20 closing shortage retained with `needs_investigation` status.

The scenario is idempotent. Run `php spark ibems:scenario-15-days` to create it once or verify it again.

## One transaction lifecycle

1. Validate the store, payment method, customer, item identifiers, and quantities.
2. Confirm that the actor can operate the selected store and that today's store day is open.
3. Confirm each product belongs to the store and is active; compute prices from server-side product records.
4. For debt, require an active faculty/staff customer, validate the debt PIN, lock/read the balance, and reject the sale when available credit is insufficient.
5. In one database transaction, create the transaction header and lines, atomically deduct stock, create inventory movements, and write the audit event.
6. For debt, update the balance and append a debt-cashbook entry containing before/after and credit snapshots.
7. Commit all records together or roll everything back on any failure.

## Verification results

- `ibems:data-audit`: passed, zero warnings and zero errors.
- `ibems:auth-audit`: passed, zero warnings and zero errors.
- PHPUnit: 73 tests and 471 assertions passed; only the expected missing-coverage-driver warning remains.
- Browser: Main Campus Store history displayed all 18 applicable records for the selected 15-day range (15 cash plus 3 debt), with PHP 525 total sales and no console warnings/errors.
- `ibems:route-audit`: failed 38 of 41 write endpoints because its parser only recognizes legacy `role:*` filters. The routes use the newer `access:*` permission filters and are covered by the authorization tests.

## Improvements and remaining errors

### High priority

1. Update `IbemsRouteAudit` to recognize and validate `access:*` filters against the authorization capability map. The stale parser makes the full preflight fail and could hide a real unprotected route among false positives.
2. Prevent an active store from existing without an assigned operational officer, or give it an explicit `setup_pending` state. JL Store was active but had no officer and no products before this scenario.
3. Link `needs_investigation` store-day variances to a durable investigation/case identifier. The current variance review status and debt-investigation workflow are separate, so a reviewer cannot trace a flagged shortage to a case from end to end.

### Medium priority

4. Persist rejected financial attempts in a structured table or typed audit event with reason, attempted amount, store, customer, and available-credit snapshot. A rejected purchase correctly creates no completed transaction, but operations staff need a searchable exception trail.
5. Add a supported historical/simulation clock for acceptance testing. Normal POS operations intentionally use the server's current business date, which makes multi-day rehearsal impossible through the UI without a controlled scenario runner.
6. Add a store-readiness checklist before activation: officer, supervisor coverage, at least one active product, opening balance, payment methods, and category setup.
7. Make PIN-change audit metadata distinguish initial setup, user-initiated change, forced reset, and recovery without ever storing either PIN value.
8. Show salary-period state as a compact timeline: prepared, submitted, payroll-confirmed, reconciled, independently finalized. The rules are sound, but the workflow is difficult to understand from separate controls.

### Lower priority and usability

9. In transaction history, explain that the count is transaction count rather than business-day count. Main Campus correctly showed 18 transactions over 15 days, but the difference is easy to misread.
10. Add an exception dashboard joining over-limit attempts, failed PIN attempts, store-day variances, repayment exceptions, deduction carryovers, and open investigations.
11. Add a scenario cleanup command scoped only to the scenario key. The fixture is idempotent, but there is no safe, selective rollback command yet.

## Workflow assessment

The core transaction design is logical and safe: server-derived prices, store/day authorization, atomic stock deduction, debt balance locking, append-only cashbook history, and rollback on failure agree with each other. Salary deductions also enforce reconciliation and require a different Accounting user to finalize.

The weakest operational connection is exception handling. Credit rejections, PIN failures, store shortages, debt investigations, and salary carryovers exist in different surfaces without one case record tying the evidence, owner, status, and final disposition together.

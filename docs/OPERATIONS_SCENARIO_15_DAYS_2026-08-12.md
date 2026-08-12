# IBEMS 15-Day Operations Scenario

Run date: 2026-08-12  
Scenario period: 2026-07-15 through 2026-07-29  
Scenario key: `SCN15-2026-07`

## Executed coverage

- Four active stores, each with an assigned store officer and store supervisor. Missing coverage was filled with one dedicated, store-scoped synthetic supervisor per uncovered store.
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
- PHPUnit: 76 tests and 487 assertions passed; only the expected missing-coverage-driver warning remains.
- Browser: Main Campus Store history displayed all 18 applicable records for the selected 15-day range (15 cash plus 3 debt), with PHP 525 total sales and no console warnings/errors.
- `ibems:route-audit`: passed all 41 write endpoints after adding support for the current `access:*` permission filters.

## Improvements and remaining errors

### High priority

1. Create a durable exception/case record for store-day variances, with evidence attachments, an owner, eligible independent reviewers, status history, and final disposition. The review note alone is not a full investigation file.
2. Prevent an active store from existing without an assigned operational officer, or give it an explicit `setup_pending` state. JL Store was active but had no officer and no products before this scenario.
3. When segregation of duties blocks a reviewer, show who is eligible to continue and provide a direct handoff action. The current error is correct but leaves the operator to find another reviewer manually.

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
12. Add a historical store-day session list with date/status filters to Administrator and Store Supervisor store details. A later balanced close currently hides an older unresolved shortage because only the latest session is presented.
13. Add an Administrator alert for unresolved historical `pending` and `needs_investigation` reviews. The dashboard's current-day alerts did not surface the scenario's July 24 PHP 20 shortage.

Items 12 and 13 were implemented during follow-up acceptance. Administrator and Store Supervisor details now expose up to 60 recent sessions with review-status filters and permitted actions. The Administrator dashboard now counts and links unresolved historical reviews. Active-store creation or updates also require both a primary officer and supervisor coverage.

The first part of items 1 and 3 was implemented in the next follow-up. Every reviewable store-day variance now has a stable `SDV-*` case reference, an owner, an append-only event timeline, and a financial evidence snapshot. Existing variance records were backfilled without changing their amounts or dispositions. Store details list eligible independent reviewers and the self-review error names who can continue. File attachments and an active notification/handoff action remain future work.

The improved dashboard exposed a second pre-existing unresolved record: Dashboard Demo Store has a PHP 500 shortage from 2026-06-30. Read-only reconciliation confirmed PHP 3,000 expected cash, PHP 2,500 counted cash, no cash movement, and only one PHP 40 debt sale that did not affect cash. An Administrator attempt to review it was correctly blocked because that same user closed the store day. A separately assigned store supervisor then changed only the review status to `needs_investigation` and recorded the evidence; the PHP -500 variance, counted cash, and accountability remain unchanged pending source evidence.

## Role-by-role browser acceptance

- Store Officer: scenario product, remaining stock, and dated inventory movements persisted after load.
- Store Supervisor: assignment scope was correct, but Main Campus still reported an August 11 store day as open on August 12.
- Store Supervisor segregation of duties: the closer could not review their own variance. A different supervisor could record the investigation note, and reopening the dashboard preserved `Needs Investigation` with PHP -500 unchanged.
- Accounting: the scenario employee displayed PHP 150 debt and PHP 850 available credit; the finalized period displayed PHP 300 confirmed and PHP 150 carryover.
- Accounting investigation: the duplicate-charge case displayed its transaction reference, findings, independent closer, and PHP 50 posted reversal.
- Administrator: all four stores, their officers, products, totals, and transactions were visible. JL Store displayed 18 transactions, PHP 525 sales, one product, and 479 remaining units.
- Administrator exception review: the historical PHP 20 shortage was not discoverable from the dashboard or store detail once July 29 became the latest balanced session.

## Workflow assessment

The core transaction design is logical and safe: server-derived prices, store/day authorization, atomic stock deduction, debt balance locking, append-only cashbook history, and rollback on failure agree with each other. Salary deductions also enforce reconciliation and require a different Accounting user to finalize.

The weakest operational connection is exception handling. Credit rejections, PIN failures, store shortages, debt investigations, and salary carryovers exist in different surfaces without one case record tying the evidence, owner, status, and final disposition together. Segregation of duties is correctly enforced, but the interface should name the eligible independent reviewers when it blocks the closer so the operator knows who can continue the case.

# IBEMS Operations Remediation Roadmap

Based on: `SCN15-2026-07` 15-day acceptance scenario  
Prepared: 2026-08-12  
Scope: local/staging workflow and source review; no production deployment or unresolved financial adjustment

## Current position

The core financial paths passed acceptance: server-priced POS transactions, atomic stock movements, debt-limit rejection, debt PIN changes, repayments, investigation reversal, payroll deduction, carryover, independent finalization, and store-day reconciliation. All four stores have officer, supervisor, product, and 15-day records.

The main residual risk is operational exception management rather than transaction mathematics. Store-day variance cases now have stable references, owners, evidence history, private attachments, reviewer handoffs, acknowledgment deadlines, retention metadata, final-disposition guards, and a guarded stale-day resolution path. The remaining work is to collect real evidence and connect the other exception types to similarly searchable workflows.

## P0 - operational actions before resolving shortages

### 1. Complete the two open store-day investigations

Owners must collect actual source evidence before any approval, waiver, correction, or employee liability:

- `SDV-20260630-000001`, Dashboard Demo Store, PHP -500.00.
  - Confirm the physical count sheet and denomination breakdown.
  - Obtain the closing operator statement.
  - Check deposit, withdrawal, transfer, and opening-balance documents.
  - Explain why expected cash was PHP 3,000.00 and counted cash was PHP 2,500.00.
  - The PHP 40.00 debt sale is not a cash explanation.
- `SDV-20260724-000045`, JL Store, PHP -20.00.
  - Obtain the closing count sheet and operator statement.
  - Verify the PHP 1,025.00 expected and PHP 1,005.00 counted cash.
  - Administrator has acknowledged ownership; final disposition remains blocked because no evidence file is attached.

Acceptance gate: evidence is attached, its hash verifies on authorized download, the owner records findings, and a disposition is made by an eligible independent reviewer. Amounts must not be edited to force reconciliation.

### 2. Resolve stale open store days

Main Campus showed an August 11 store day still open on August 12. Add an explicit stale-day resolution workflow rather than letting yesterday's session silently remain operational:

- block a new day until the stale session is resolved;
- require counted cash/e-cash and a reason;
- preserve the original business date;
- escalate to the assigned supervisor;
- prohibit silent auto-close or date reassignment.

Acceptance gate: Officer, Supervisor, and Administrator browser tests cover stale detection, guarded resolution, independent review, and next-day opening.

Implementation status: completed in source and automated regression coverage. Ordinary Store Officers can no longer close a previous-date session. An assigned Store Supervisor or Administrator must enter independently counted cash/e-cash and a required reason; the original business date and opener are preserved, the action is audited, and any variance opens the existing case workflow for another reviewer. The live Main Campus August 11 record remains open until its real physical counts and reason are supplied; implementation did not invent or alter financial values.

## P1 - next implementation cycle

### 3. Structured rejected-attempt ledger

Persist rejected financial attempts separately from completed transactions. Capture event type, actor, customer, store, attempted amount, available credit, reason code, timestamp, and correlation ID. Never create a sale or debt entry for a rejected attempt.

First event types:

- credit limit exceeded;
- invalid/locked debt PIN;
- closed or missing store day;
- inactive or wrong-store product;
- insufficient stock;
- duplicate client transaction ID.

Acceptance gate: searchable Administrator/Accounting views reconcile each rejection to audit metadata without affecting sales, debt, cash, or inventory totals.

### 4. Store activation readiness state

Replace the binary active/inactive assumption with an explicit readiness check. Activation should require:

- primary officer;
- at least one supervisor;
- active product;
- category and payment method;
- opening-balance configuration;
- successful access check for both roles.

Use `setup_pending` or an equivalent state so an incomplete store is visible but cannot operate.

Acceptance gate: an incomplete store cannot open a day or post a transaction, and the UI lists every missing requirement.

### 5. Payroll workflow timeline

Show one chronological salary-period timeline: prepared, submitted, payroll-confirmed, reconciled, independently finalized, and carryover created. Link each transition to actor, timestamp, totals, and batch reference.

Acceptance gate: displayed requested, confirmed, deducted, and carryover totals reconcile to the debt cashbook and remain readable after later periods.

## P2 - control and usability improvements

### 6. Unified exception dashboard

Create one filterable view for store-day cases, rejected credit attempts, PIN lockouts, debt investigations, repayment exceptions, deduction carryovers, and stale store days. Show owner, age, due date, exposure, next action, and status without combining unlike financial balances.

### 7. PIN audit reason taxonomy

Record whether a PIN event is initial setup, user change, administrative reset, or recovery. Retain actor, timestamp, and reason only; never store or expose either the old or new PIN.

### 8. Supported simulation clock

Keep production operations bound to the server business date, but provide an isolated acceptance-only clock for multi-day rehearsal. It must be environment-gated, visibly labeled, audit logged, and impossible to enable accidentally in production.

### 9. Scenario lifecycle tooling

Add a dry-run-first cleanup command restricted to the scenario key. It must enumerate targeted rows, verify referential scope, refuse non-scenario records, and require explicit confirmation before deletion.

### 10. Presentation clarifications

- Label transaction counts as transactions, not business days.
- Display case owner and deadline in all unresolved-review cards.
- Explain why a final-disposition button is disabled before it is clicked.
- Add direct navigation from an alert to the expanded case, not only the store page.

## Deferred safeguards for evidence files

Before broader or production use, add malware scanning/quarantine, configurable retention policy, backup/restore verification for private evidence, and an administrator-only legal hold. File deletion must never occur merely because a case is resolved.

## Recommended delivery order

1. Collect and review real evidence for both open shortage cases.
2. Implement stale-store-day resolution. Completed; live use remains evidence-bound.
3. Implement the rejected-attempt ledger.
4. Add store readiness state and activation checklist.
5. Add payroll timeline.
6. Build the unified exception dashboard from the now-structured sources.
7. Add simulation and cleanup tooling last because they are acceptance infrastructure, not daily operational controls.

## Release gates

Every implementation batch should pass:

- focused unit/integration tests for calculations, authorization, and history;
- `php spark ibems:preflight`;
- full PHPUnit suite;
- role-specific browser acceptance and reload persistence;
- financial reconciliation proving no unintended balance, cash, stock, salary, or accountability change;
- migration forward test and rollback review;
- explicit deployment authorization before any push, staging publication, or production release.

## Do not do

- Do not approve, waive, correct, or charge either shortage without source evidence.
- Do not rewrite historical cash counts or delete case events.
- Do not treat debt sales as physical-cash movements.
- Do not let the closer review their own store day.
- Do not create completed transactions for rejected attempts.
- Do not expose private evidence through public upload paths.

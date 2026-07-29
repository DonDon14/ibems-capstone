# IBEMS University Debt Policy and Implementation Plan

Status: approved working baseline  
Last updated: 2026-07-30

This document converts the instructor-defined university use case and current
project decisions into implementation rules. Unknown payroll details must be
configurable and must not be hard-coded into accounting calculations.

## Product Boundary

IBEMS is a university school-store and employee debt management system. Its
connected responsibility is:

1. record school-store purchases;
2. authorize faculty/staff debt purchases;
3. maintain an explainable employee debt ledger;
4. prepare deductions for the responsible university department;
5. record actual deduction results;
6. carry unresolved balances forward;
7. provide statements, investigation, correction, and audit evidence.

IBEMS does not calculate payroll. It prepares and reconciles debt deductions
against results supplied or confirmed by the responsible payroll/accounting
office.

## Portal Model

The system should have four workspaces. Roles and permissions may change what a
user sees within a workspace; a separate portal is not required for every role.

| Portal | Responsibility |
| --- | --- |
| System Administration | Accounts, roles, stores, configuration, investigation oversight, and system audit |
| Store Operations | POS, inventory, stock acquisition, store-day closing, receipts, and supervisor review |
| Accounting and Debt Processing | Credit limits, deduction periods, deduction preparation, result confirmation, carryovers, reconciliation, and debt corrections |
| Employee/Customer | Personal debt, available credit, purchases, receipts, deductions, statements, PIN management, and disputes |

Store operators and store supervisors should share the Store Operations
workspace with different permissions. Accounting and payroll-processing
responsibilities should initially share the Accounting and Debt Processing
workspace. Split them only if the university confirms a strict organizational
or privacy boundary.

## Confirmed Eligibility Policy

- Debt is available only to active faculty and staff.
- Students are excluded from debt purchases.
- Students may retain non-debt purchase history and receipts if required.
- Each eligible employee has an individual credit limit.
- Accounting is the authority that approves and changes credit limits.
- Credit-limit changes require a reason and an immutable before/after audit
  record.
- Store operators and system administrators cannot change a credit limit as an
  operational shortcut.

Recommended initial-limit policy:

- New faculty/staff accounts start with no usable credit until Accounting
  assigns a limit.
- Salary may inform the decision, but IBEMS must not automatically determine a
  credit limit from salary without an approved university policy.
- The Accounting screen may show salary context to authorized users and suggest
  a value later, but a person must approve the final limit.

## Debt PIN Policy

The PIN requirement from the instructor discussion remains part of the system.

Recommended implementation:

- Use a 4-to-6 digit PIN.
- Store only a password hash; never store, display, log, export, or recover the
  original PIN.
- The employee creates or changes the PIN in the Employee Portal.
- Changing an existing PIN requires the employee's current account password.
- The PIN is entered privately by the employee during the purchase. The UI
  should display the store and final amount before authorization.
- The cashier must not ask the employee to say the PIN aloud or record it.
- Five failed attempts within 15 minutes lock debt authorization for that
  employee for 15 minutes.
- Failed attempts, lockouts, successful authorizations, and PIN changes are
  security audit events.
- A successful authorization is valid only for the transaction being submitted;
  it must not authorize later purchases.
- Administrators and cashiers cannot view or manually set an employee's PIN.
- PIN reset requires employee reauthentication or an audited identity-verification
  process.

Future enhancement:

- Add employee-device or QR confirmation as an optional stronger method without
  removing the instructor-required PIN.

## Deduction Period Policy

Deduction timing must be configurable. Do not encode "monthly" or "every 15
days" as the only valid schedule.

Each deduction period must contain:

- stable ID and human-readable code;
- date start and date end;
- expected pay/deduction date;
- frequency label (`semi_monthly`, `monthly`, or `custom`);
- status;
- preparation deadline;
- notes;
- creator, reviewer, confirmer, and timestamps.

Recommended default templates:

- Semi-monthly: days 1-15 and day 16 through month end.
- Monthly: one calendar-month period.
- Custom: explicit start, end, and expected processing date.

Period statuses:

1. `draft`
2. `reviewed`
3. `submitted`
4. `partially_processed` or `processed`
5. `reconciled`
6. `finalized`
7. `cancelled`

Only one non-cancelled period may use the same period code. Overlapping periods
should be rejected unless an Accounting Supervisor explicitly approves the
exception.

## Deduction Responsibility

Recommended separation of responsibility:

- Accounting prepares and reviews the deduction batch.
- Payroll or the department that actually processed salary confirms the result.
- If Payroll users do not access IBEMS, Accounting imports or records the
  official result received from Payroll.
- An Accounting Supervisor reconciles and finalizes the period.

Preparation is not proof of deduction. Employee debt must decrease only after a
successful result is recorded.

The existing one-step settlement behavior should be migrated toward
prepare-submit-confirm-finalize. Until that migration is complete, the UI must
not describe a prepared batch as a confirmed payroll deduction.

## Partial Deduction and Carry-Forward Policy

Partial deductions are allowed and must carry forward.

For every employee in a deduction period, record:

- requested amount;
- confirmed deducted amount;
- undeducted amount;
- result status;
- reason code;
- result reference;
- confirmer and confirmation time.

Result statuses:

- `fully_deducted`
- `partially_deducted`
- `not_deducted`
- `employee_not_found`
- `insufficient_salary`
- `duplicate`
- `returned_for_correction`

Only the confirmed deducted amount reduces current debt. Any remaining amount
stays in the debt ledger and is eligible for a later deduction period. Do not
copy or rewrite the original purchase; the open balance itself carries forward.

Carry-forward must remain traceable to the period and result that left the
balance unresolved.

## Corrections and Investigations

Recommended authority:

- A System Administrator or Accounting user may open an investigation.
- Store staff provide transaction, receipt, and store-day evidence.
- The Administrator records findings and recommends an outcome.
- An Accounting Supervisor approves and posts any financial reversal or
  correction.

This separation prevents a system administrator from silently changing an
employee balance.

Financial records are append-only:

- never delete or overwrite a completed debt purchase;
- never edit a finalized deduction result;
- post a reversal or correction entry referencing the original record;
- require reason, evidence summary, actor, approver, and timestamps.

Investigation statuses:

1. `submitted`
2. `under_review`
3. `awaiting_store_evidence`
4. `awaiting_employee_response`
5. `recommended`
6. `approved` or `rejected`
7. `resolved`
8. `closed`

## Employee Dispute Workflow

1. Employee selects a transaction or ledger entry.
2. Employee supplies a dispute category and explanation.
3. The system freezes no valid debt automatically; it marks the disputed amount
   for review.
4. Accounting checks the ledger and authorization record.
5. Store staff provide the receipt and operator/store-day context.
6. The investigator records findings.
7. The authorized Accounting Supervisor approves or rejects the correction.
8. If approved, IBEMS posts a linked reversal/correction.
9. The employee can see the outcome and explanation.

The system should prevent a disputed amount from being newly submitted to
payroll while an approved policy-defined hold is active. This behavior must be
explicitly visible to Accounting.

## Retention Baseline

This is a recommended system configuration, not a substitute for the
university's legal, records-management, tax, or Data Protection Officer review.

| Record category | Recommended retention |
| --- | --- |
| Transactions, receipts, inventory movements, debt journal, deduction results, settlement records | 10 years after the relevant fiscal year |
| Financial audit events and correction/investigation decisions | 10 years |
| Authentication and security events | 2 years searchable, then up to 5 years archived if required by policy |
| Unsuccessful import files and temporary processing artifacts | 90 days after resolution |
| Dispute attachments and investigation evidence | Life of the financial record or 10 years, whichever is longer |
| Inactive account profile details not required for financial records | Review for deletion or anonymization 2 years after separation and final settlement |
| Aggregated, anonymized statistics | May be retained longer when re-identification is not reasonably possible |

Deletion must be a scheduled, reviewable process. Financial records that must be
retained should be pseudonymized where practical after the person is no longer
active, while preserving required accounting links.

The university must publish a privacy notice describing purpose, access,
sharing, retention, employee rights, and the contact details of its Data
Protection Officer.

## Stock Acquisition Boundary

Formal suppliers and purchase orders are deferred.

School stores may buy from changing external stores. IBEMS should instead record
a lightweight stock acquisition:

- optional purchased-from name;
- purchase date;
- purchaser;
- receipt/reference;
- optional receipt image;
- products, quantities, and actual unit cost;
- payment source and remarks;
- resulting inventory movements.

Do not create supplier accounts, contracts, accounts payable, or purchase-order
approval until the university confirms centralized procurement.

## Implementation Slices

### Slice 1: Policy and terminology

- Adopt this document as the working baseline.
- Rename user-facing monthly settlement language to deduction period/batch.
- Keep existing routes compatible during migration.
- Add a centralized status and permission vocabulary.

### Slice 2: Authorization hardening

- Persist failed PIN attempts and lockout state.
- Enforce five attempts per 15 minutes.
- Record successful authorization against the transaction.
- Add focused tests for student exclusion, eligibility, lockout, and successful
  retry after expiry.

### Slice 3: Configurable deduction periods

- Add `deduction_periods`.
- Add period-code, dates, frequency, status, workflow actors, and timestamps.
- Generate optional semi-monthly/monthly templates.
- Replace `run_month` as the primary workflow identity while retaining legacy
  settlement readability.

### Slice 4: Deduction preparation and confirmation

- Add `deduction_batches` and `deduction_batch_items`, or migrate the existing
  settlement tables to equivalent semantics.
- Separate requested amounts from confirmed results.
- Apply debt reductions only after confirmation.
- Make result import idempotent.

### Slice 5: Partial carryovers

- Record full, partial, and failed results.
- Leave undeducted balances open.
- Surface carryover source and age.
- Add employee and Accounting views.

### Slice 6: Disputes and corrections

- Add investigation/dispute records and evidence references.
- Add recommendation and approval separation.
- Post linked debt-journal reversals.
- Prevent direct mutation of finalized records.

### Slice 7: Retention and privacy operations

- Add configurable retention categories.
- Add archive and disposal reports.
- Add secure attachment cleanup.
- Document backup, restore, anonymization, and legal-hold procedures.

### Slice 8: Acceptance and release

- Add database-backed service tests.
- Add end-to-end debt purchase, partial deduction, carryover, dispute, and
  correction scenarios.
- Run migration, seed, preflight, PHPUnit, JavaScript syntax, responsive browser,
  and fresh-database validation.

## Explicitly Deferred

- Supplier master data
- Purchase orders
- Accounts payable
- General payroll calculation
- Attendance and scheduling
- Student debt
- Loyalty and ecommerce
- AI credit scoring
- Automatic salary-based credit-limit decisions


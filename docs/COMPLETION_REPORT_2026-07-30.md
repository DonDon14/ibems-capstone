# IBEMS Functional Completion Report

Date: 2026-07-30
Branch: `codex/ci-validation`

## Completion Status

The agreed local implementation scope is complete.

| Area | Status |
| --- | --- |
| Admin, Store, Store Admin, Accounting, and Employee portals | Complete |
| Faculty/staff-only individual debt accounts | Complete |
| Debtor-owned PIN authorization and shared lockout | Complete |
| Configurable deduction periods | Complete |
| Prepare, submit, confirm, reconcile, and finalize workflow | Complete |
| Partial deductions and traceable carryover | Complete |
| Independent finalization | Complete |
| Investigations and independently approved corrections | Complete |
| Append-only debt cashbook and audit history | Complete |
| Employee receipts and debt history | Complete |
| Shared modal, search, dropdown, and date conventions | Complete |
| Legacy one-click Accounting mutations retired | Complete |
| Automated tests and deployment preflight | Complete |

## Final Debt Workflow

1. An active faculty/staff employee receives an individual Accounting-approved
   credit limit and creates a private debt PIN.
2. Store staff select the employee at POS. The employee privately enters the PIN.
3. Five failures in 15 minutes lock PIN authorization for 15 minutes across all
   store terminals.
4. Accounting creates a semi-monthly, monthly, or custom deduction period.
5. Accounting prepares employee requests. Preparation does not reduce debt.
6. Accounting submits the batch for payroll processing.
7. Official payroll results are recorded with confirmed amount, result status,
   reason, reference, confirmer, and time.
8. Only confirmed amounts reduce debt. The unresolved debt remains open as
   traceable carryover.
9. Accounting reconciles batch totals.
10. A different Accounting user finalizes and locks the period.
11. Incorrect purchases use an investigation, evidence, recommendation, and
    independent Accounting approval. Corrections are new linked ledger entries;
    original purchases are never deleted or rewritten.

## Validation Evidence

- All application migrations are applied to the local MariaDB database.
- The local smoke check confirms every required table and writable path.
- PHPUnit: 55 tests and 319 assertions pass.
- Authentication, route-security, and data-integrity audits pass with zero
  warnings and zero errors.
- The data audit checks deduction amount invariants, finalized periods, approval
  separation, reversal links, and PIN attempt-state limits.
- PHP syntax scanning covered 195 files.
- JavaScript syntax scanning covered 26 files.
- Tailwind assets were rebuilt after the final UI changes.
- Browser checks confirmed the Accounting deduction, investigation, shared
  dropdown, shared calendar, and read-only legacy-history interactions.
- Critical Accounting pages and modals do not overflow the desktop viewport.

## Demo Separation-of-Duties Accounts

- Preparer: `accounting@ibems.local`
- Independent approver/finalizer: `accounting.supervisor@ibems.local`

Both local demo accounts use the documented development password. The supervisor
account is created idempotently by `AccountingSupervisorDemoSeeder`.

## Boundaries

This report means the agreed application feature scope is complete locally. It
does not mean the branch has been pushed, merged, deployed, or approved by the
university. Those are explicit release decisions. University Accounting, Payroll,
the Data Protection Officer, and the instructors should still confirm the
configured deduction schedule and retention policy before production use.

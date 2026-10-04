# IBEMS Role and Permission Matrix

This document is the authorization source of truth for IBEMS. Route filters, controller policies, navigation, and tests must agree with this matrix. Hiding a control in the interface is not authorization.

Don confirmed on October 4, 2026 that **Accounting only** may change an existing employee's credit percentage or limit. `ACCOUNTING_OFFICE` and `ACCOUNTING_HEAD` use the financial-profile workflow with a reason and before/after audit snapshots. Admin identity/role edits and existing-user CSV imports preserve the financial profile and debt; forged Admin financial changes are rejected. Multi-role accounts are checked using their active role. New employee onboarding retains the approved standard baseline.

Named route permissions are defined centrally in `app/Config/Authorization.php`. Routes use the `access:<policy>` filter. Raw role filters are not registered, and unknown policy names fail closed.

## Role meanings

| Role code | Business name | Scope | Primary responsibility |
| --- | --- | --- | --- |
| `ADMIN` | System Administrator | Institution-wide | Configure and audit the platform, manage users and stores, and handle documented escalations. |
| `HR` | Human Resources | Employee onboarding | Create and import staff accounts; no Accounting or Cashier authority. |
| `STORE_SUPERVISOR` | Store Supervisor | Assigned stores only | Manage assigned-store inventory, configuration, records, reports, and independently review store-day exceptions. |
| `STORE_SYSTEM` | Store Cashier | Cashier-assigned store only | Open the POS, process sales and repayments, and perform store-day cash custody. |
| `ACCOUNTING_OFFICE` | Accounting Officer | Institution-wide accounting | Manage debt, deductions, investigations, and settlement workflows. |
| `ACCOUNTING_HEAD` | Accounting Head / Bookkeeper | Accounting-wide | Inspect and manage Accounting workflows, including deductions. |
| `PAYROLL_CLERK` | Accounting Payroll Clerk | Payroll workflow | Process salary-deduction periods and batches; no debt imports, financial-profile edits, investigations, or settlements. |
| `CASHIER_HEAD` | School Cashier Head | Cashier Office | Inherits the Collecting Officer's sales and deduction capabilities and sees an office-wide sales rollup; receives BE-approved store reports. |
| `COLLECTING_OFFICER` | School Collecting Officer | Cashier Office | Views daily sales totals by store and processes the same salary-deduction periods and batches as the Payroll Clerk. |
| `BE_DIRECTOR` | Business Enterprise Director | Business oversight | Reviews and routes assigned-store daily reports; reads scoped financial reports. |
| `USER` | Employee / User | Own account only | View personal transactions, debt, receipts, and cashbook records. |

`ADMIN` is the current System Administrator role. Do not add a separate `SUPER_ADMIN` role unless a lower, limited administrator role is introduced later.

The existing `STORE_SYSTEM` and `STORE_SUPERVISOR` roles remain store-assignment roles, not School Cashier Office roles. Cashier Office sales totals are direct recorded transactions, not BE Director-reviewed daily report submissions. Both Cashier roles can view those per-store totals; only the Head sees the office-wide rollup. The user confirmed on 2026-09-23 that Collecting Officer and Payroll Clerk may both post actual salary deductions through the same function, and Cashier Head inherits Collecting Officer capabilities. This supersedes the contradictory payroll-only line of the September 3 minutes. The shared `deduction.workflow` routes use one deduction service; they do not grant Cashier users access to general Accounting debt management. The former public one-click apply route is removed. Partial deductions remain unchanged. `ACCOUNTING_OFFICE` is retained as a legacy broad role until account migration is planned; `ACCOUNTING_HEAD` has the same Accounting-wide scope. The assigned Store Supervisor alone submits a closed-day report to BE; the Store Cashier cannot. BE review, Cashier receipt, and Accounting furnished-copy inboxes are implemented for versioned reports.

## Permission matrix

The table records the active-role gate for each named route policy. An allowed role is not enough by itself: store assignment, report state, batch ownership, and independent-review checks still apply inside the relevant workflow. `ACCOUNTING_OFFICE` is the retained legacy broad Accounting role.

| Route policy | Allowed active roles | Additional scope |
| --- | --- | --- |
| `system.manage` | `ADMIN` | System configuration and audited oversight; no automatic financial approval. |
| `hr.staff.create` | `HR` | Staff onboarding and import only. |
| `store.inspect` | `ADMIN`, `STORE_SUPERVISOR`, `STORE_SYSTEM` | Supervisor and cashier access is store-assigned; Admin inspection is institution-wide. |
| `store.manage_assigned` | `STORE_SUPERVISOR` | Assigned-store inventory and configuration. |
| `store.review_assigned` | `STORE_SUPERVISOR` | Assigned store; reviewer must be independent of the closer. |
| `store.report_submit` | `STORE_SUPERVISOR` | Assigned store and closed day only. |
| `store.checkout` | `STORE_SYSTEM` | Assigned-store POS and store-day custody only. |
| `store.offerings` | `STORE_SUPERVISOR`, `STORE_SYSTEM` | Assigned-store offering actions are further limited by the endpoint. |
| `be.review` | `BE_DIRECTOR` | First review of submitted store reports. |
| `cashier.office` | `CASHIER_HEAD`, `COLLECTING_OFFICER` | Both can receive BE-approved reports; Head also has the office-wide sales view. |
| `report.accounting_copy` | `ACCOUNTING_OFFICE`, `ACCOUNTING_HEAD` | Read-only furnished copies after BE approval. |
| `finance.reports` | `BE_DIRECTOR`, `CASHIER_HEAD`, `COLLECTING_OFFICER`, `ACCOUNTING_OFFICE`, `ACCOUNTING_HEAD` | Read-only sales, charges, deductions, and waste reports. |
| `accounting.payroll` | `PAYROLL_CLERK` | Payroll landing page; shared deductions use a separate policy. |
| `deduction.workflow` | `ACCOUNTING_OFFICE`, `ACCOUNTING_HEAD`, `PAYROLL_CLERK`, `CASHIER_HEAD`, `COLLECTING_OFFICER` | Shared period, batch, and actual-result workflow; partial results remain supported. |
| `accounting.inspect` | `ADMIN`, `ACCOUNTING_OFFICE`, `ACCOUNTING_HEAD` | Admin inspection does not confer Accounting writes. |
| `accounting.debt_manage` | `ACCOUNTING_OFFICE`, `ACCOUNTING_HEAD` | Debt imports and financial-profile changes. |
| `accounting.investigate` | `ACCOUNTING_OFFICE`, `ACCOUNTING_HEAD` | Debt investigation decisions. |
| `accounting.settle` | `ACCOUNTING_OFFICE`, `ACCOUNTING_HEAD` | Department allocations and settlements. |
| `user.self` | `USER` | Own purchase, debt, receipt, and account history. |

`portal.enter` and `account.self` are common entry and account-settings gates, not business-function grants. A person with another operational role sees personal employee information only after deliberately selecting an assigned `USER` role.

## Authorization rules

1. Every protected endpoint must enforce its role server-side.
2. Store-scoped endpoints must also verify the active user's assignment to the requested store.
3. A store-day reviewer must be different from the operator who closed that store day.
4. System Administrator access to operational and accounting data is read-only unless a route is explicitly documented as an audited escalation.
5. Every financial approval, waiver, correction, reset, or override must record the actor, target, reason, timestamp, and resulting state in the audit log.
6. Users with multiple roles must deliberately select an active role; permissions are evaluated using that active role rather than the union of all assigned roles.
7. Shared business logic belongs in services or policies. A scoped portal must not acquire global authority merely by inheriting a global controller.

## Review workflow

1. The Store Cashier opens, operates, and closes the store day through the POS.
2. A balanced close requires no approval.
3. A variance is sent to a Store Supervisor assigned to that store.
4. The closing Store Cashier cannot review that variance, even if the same account also has the Store Supervisor role.
5. If no independent assigned Store Supervisor is available, the case is escalated to the System Administrator and the override reason is audited.

Changes to this matrix are business-rule changes. Update the related route, policy, endpoint, audit, and authorization tests together.

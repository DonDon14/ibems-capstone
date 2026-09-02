# IBEMS Role and Permission Matrix

This document is the authorization source of truth for IBEMS. Route filters, controller policies, navigation, and tests must agree with this matrix. Hiding a control in the interface is not authorization.

Named route permissions are defined centrally in `app/Config/Authorization.php`. Routes use the `access:<policy>` filter. Raw role filters are not registered, and unknown policy names fail closed.

## Role meanings

| Role code | Business name | Scope | Primary responsibility |
| --- | --- | --- | --- |
| `ADMIN` | System Administrator | Institution-wide | Configure and audit the platform, manage users and stores, and handle documented escalations. |
| `STORE_SUPERVISOR` | Store Supervisor | Assigned stores only | Manage assigned-store inventory, configuration, records, reports, and independently review store-day exceptions. |
| `STORE_SYSTEM` | Store Cashier | Cashier-assigned store only | Open the POS, process sales and repayments, and perform store-day cash custody. |
| `ACCOUNTING_OFFICE` | Accounting Officer | Institution-wide accounting | Manage debt, deductions, investigations, and settlement workflows. |
| `USER` | Employee / User | Own account only | View personal transactions, debt, receipts, and cashbook records. |

`ADMIN` is the current System Administrator role. Do not add a separate `SUPER_ADMIN` role unless a lower, limited administrator role is introduced later.

## Permission matrix

Legend: **Manage** includes create/update actions; **Review** is an independent approval action; **Inspect** is read-only.

| Capability | System Administrator | Store Supervisor | Store Cashier | Accounting Officer | User |
| --- | --- | --- | --- | --- | --- |
| Manage users and role assignments | Manage | No | No | No | No |
| Manage stores and cashier/supervisor assignments | Manage | No | No | No | No |
| View stores | All stores | Assigned stores | Cashier-assigned store | No | No |
| Inspect store sales, inventory, and transactions | All stores | Assigned stores | POS-required data only | No | No |
| Create POS transactions | No | No | Assigned store | No | No |
| Open or close a store day | No | No | Assigned store | No | No |
| Restock or adjust inventory | No | Assigned stores | No | No | No |
| Configure categories and payment settings | No | Assigned stores | No | No | No |
| Review a store-day variance | Escalation only | Assigned stores | No | No | No |
| Review a store day closed by the same person | No | No | No | No | No |
| Reset an opening balance | Audited escalation | No | No | No | No |
| View system audit log | All | No | No | No | No |
| Manage accounting workflows | Inspect/escalate only | No | No | Manage | No |
| View personal account information | Via an assigned `USER` role | Via an assigned `USER` role | Via an assigned `USER` role | Via an assigned `USER` role | Own account |

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

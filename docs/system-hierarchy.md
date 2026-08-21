# IBEMS System Hierarchy

This hierarchy is the reference for portal responsibilities, system diagrams, and future workflow discussions.

```mermaid
flowchart TD
    ADMIN[Administrator<br/>System governance and school-wide oversight]
    ACCT[Accounting Office<br/>Credit, debt, deductions, settlements]
    SUP[Store Supervisor<br/>Assigned-store review and variance handling]
    STORE[Store Officer / Cashier<br/>POS, inventory, receipts, store-day custody]
    USER[Employee / User<br/>Catalog, history, credit, deductions]

    ADMIN --> ACCT
    ADMIN --> SUP
    ADMIN --> STORE
    ADMIN --> USER
    SUP --> STORE
    STORE --> USER
```

## Connected transaction flow

```mermaid
flowchart LR
    CATALOG[Active store and product catalog]
    POS[POS transaction and payment allocation]
    HISTORY[Employee history and per-store totals]
    DEBT[Credit balance and debt cashbook]
    PAYROLL[Accounting deduction workflow]
    AUDIT[Supervisor and administrator review]

    CATALOG --> POS
    POS --> HISTORY
    POS --> DEBT
    DEBT --> PAYROLL
    POS --> AUDIT
    PAYROLL --> AUDIT
```

## Responsibility rules

- Administrator: users, stores, products, school-wide operations, and audit records.
- Accounting Office: employee financial profiles, credit balances, deductions, settlements, and debt investigations.
- Store Supervisor: assigned-store review, store-day variances, supporting evidence, and handoffs.
- Store Officer / Cashier: POS transactions, products, inventory, receipts, repayments, and store-day custody.
- Employee / User: active store catalog, personal transactions, receipts, credit status, and deduction history.

The active role controls authorization. Older transactions and inactive-store names remain readable for historical integrity.

# IBEMS System Hierarchy

This is the current source reference for the external client document at `docs/client/IBEMS-System-Hierarchy.docx`. The client document must be regenerated and visually reviewed before it is treated as current. This is not a portal page.

```mermaid
flowchart TD
    ADMIN[System Administrator<br/>Institution-wide configuration and audit]
    HR[Human Resources<br/>Staff onboarding]
    USER[Faculty / Staff / Student users<br/>Own account and history]
    CASHHEAD[School Cashier Head<br/>Office-wide sales and inherited collecting functions]
    COLLECT[Collecting Officer<br/>Daily sales and shared deductions]
    ACCTHEAD[Accounting Head / Bookkeeper<br/>Accounting-wide oversight]
    PAYROLL[Payroll Clerk<br/>Shared deductions]
    BE[Business Enterprise Director<br/>Store report review and finance reports]
    SUP[Store Supervisor<br/>Assigned-store operations and report submission]
    STORECASH[Store Cashier<br/>Assigned-store POS and store-day custody]

    ADMIN --> HR
    ADMIN --> USER
    ADMIN --> CASHHEAD
    ADMIN --> ACCTHEAD
    ADMIN --> BE
    CASHHEAD --> COLLECT
    ACCTHEAD --> PAYROLL
    BE --> SUP
    SUP --> STORECASH
```

The arrows describe the business hierarchy, not automatic permission inheritance. The only confirmed inheritance is Cashier Head access to the Collecting Officer's functions. The School Cashier Office is separate from the Store Cashier and Store Supervisor. A user's active role, not their position in this diagram, determines route access; store assignments further constrain store routes. System Administrator actions that affect financial records require documented, audited authority rather than implicit approval.

## Connected transaction and report flow

```mermaid
flowchart LR
    POS[Store Cashier sale and payment]
    DEBT[Employee or department debt ledger]
    DEDUCT[Shared deduction workflow<br/>Collecting Officer / Payroll Clerk / Cashier Head]
    CLOSE[Store Cashier closes store day]
    SUBMIT[Assigned Store Supervisor submits snapshot]
    REVIEW[BE Director reviews]
    RECEIVE[School Cashier Office receives]
    COPY[Accounting receives furnished copy]

    POS --> DEBT
    DEBT --> DEDUCT
    POS --> CLOSE
    CLOSE --> SUBMIT
    SUBMIT --> REVIEW
    REVIEW --> RECEIVE
    REVIEW --> COPY
```

Partial deductions remain supported. Both the Collecting Officer and Payroll Clerk can confirm an actual deduction through the same service; preparation alone does not reduce debt. For exact route permissions and role scope, use `docs/ROLE_PERMISSION_MATRIX.md`.

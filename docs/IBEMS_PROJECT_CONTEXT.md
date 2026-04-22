# IBEMS Project Context

Last updated: 2026-04-21 (Portal scope split locked)

## Project Overview

- System: IBEMS (Integrated Business Enterprise Management System)
- Stack: CodeIgniter 4 (PHP), MySQL, Bootstrap/Vanilla JS
- Architecture: MVC (CI4), REST-style backend, session-based auth

## Current Progress (Confirmed)

### Database

Core tables are in place:

- users
- stores
- balances
- products
- transactions
- transaction_items
- inventory_movements
- salary_import (batch + rows)
- settlement_runs
- notifications
- audit_logs

### Models

- UserModel
- StoreModel
- BalanceModel
- ProductModel
- TransactionModel
- TransactionItemModel
- InventoryMovementModel
- AuditLogModel

### Core System

POS backend is working with central transaction flow in:

- `processTransaction(array $request)`

Flow includes:

- validate items
- compute total
- check credit
- create transaction
- insert items
- deduct stock
- log inventory movement
- log audit
- update debt (if debt payment)

### Auth

- login (email/password)
- password hashing
- session fields used:
  - `session()->get('user_id')`
  - `session()->get('role')`
  - `session()->get('logged_in')`

### Role Access

`RoleFilter` is implemented.

Behavior:

- unauthorized: 401
- forbidden: 403

Roles in use:

- ADMIN
- STORE_SYSTEM
- ACCOUNTING_OFFICE
- USER

### Routes (Current)

Working routes include:

- `POST /auth/login`
- `GET /auth/logout`
- `GET /auth/me`
- `GET /login`
- `GET /dashboard`
- `POST /pos/transactions`
- `GET /store/pos`
- `GET /user/dashboard`
- `GET /user/history`

Current store routes also include:

- `GET /store/history`
- `GET /store/inventory`
- `GET /store/staff-records`
- `GET /store/my-stores`
- `GET /store/products`
- `GET /store/debt-customers`
- `GET /store/transactions`
- `GET /store/staff-transactions`
- `GET /store/transactions/{id}`
- `POST /store/inventory/restock`
- `POST /store/inventory/adjust-stock`
- `POST /store/inventory/add-product`

Accounting routes now include:

- `GET /accounting/dashboard`
- `GET /accounting/debts`
- `GET /accounting/debts/data`
- `POST /accounting/debts/deduct`
- `POST /accounting/debts/credit-limit`

## Portal Scope Split (Locked)

This is now a strict architecture rule to avoid mixing responsibilities:

### 1) Store Portal (`STORE_SYSTEM` / `ADMIN`)

Purpose: per-store operations and per-store financial view only.

Allowed:

- POS sales
- stock in and stock adjustment
- product maintenance for assigned store
- store transaction history
- staff debt/transaction lookup for that store context
- store-level margin/profit views (operational)

Not allowed:

- salary import
- payroll deduction execution
- global settlement runs across all stores
- school-wide debt finalization controls

### 2) School Accounting Department Portal (`ACCOUNTING_OFFICE` / `ADMIN`)

Purpose: institution-level debt settlement and payroll-linked processing.

Allowed:

- global debt monitoring across stores
- manual deduction processing per faculty/staff (phase 1)
- credit limit updates
- accounting audit reports

Not allowed:

- editing store inventory and product stock as daily store operations
- operating store POS

### Authority Rule

- Store side can create debt transactions via POS.
- Only School Accounting Department can settle/clear debt through salary deductions.

## Route Boundary Plan (Do Not Mix)

- Store operations must stay under `/store/*`.
- School accounting operations must stay under `/accounting/*`.
- User self-service stays under `/user/*`.
- Admin management stays under `/admin/*`.

If a feature is salary/payout/settlement related, it belongs to `/accounting/*`, not `/store/*`.

## Admin Portal Notes (Current)

- Admin store navigation is unified as one tab: `Stores & POS`.
- `GET /admin/stores` is the main admin store hub (gallery + management).
- Clicking a store opens `GET /admin/stores/{id}` for per-store inventory and transaction records.
- Legacy `admin/store-ops` now redirects to `/admin/stores` for backward compatibility.

### UI Structure (Current)

Views and layouts are now organized with shared assets:

- `app/Views/layouts/{store,user,admin,accounting}.php`
- `app/Views/auth/login.php`
- `app/Views/store/pos.php`
- `app/Views/user/*`
- `app/Views/admin/*`
- `app/Views/accounting/*`

Assets:

- `public/assets/css/app.css`
- `public/assets/css/auth-login.css`
- `public/assets/css/store-pos.css`
- `public/assets/js/auth-login.js`
- `public/assets/js/store-pos.js`

## Current Limitations

- no QR scanning yet
- School Accounting portal is still minimal (needs salary-import + settlement workflow UI)
- Admin/User portals are still starter-level UI

## Next Steps (In Order)

1. School Accounting Department Portal (phase 1 manual controls)
- debt search + monitoring
- manual debt deduction
- manual credit-limit updates
- audit-log tracking for all accounting actions

2. School Accounting Department Portal (phase 2 automation)
- optional salary import workflow
- optional payroll-linked settlement run

3. Store Financial Reporting (store-side)
- realized profit report (separate from school accounting)
- daily/weekly/monthly store summaries

4. Admin Portal
- user management
- product management
- store management

5. User Portal
- view balance
- view transactions
- receipts

## Important Rules

- POS user should come from session, not request payload
- critical actions should remain logged in `audit_logs` and `inventory_movements`

## Continue Prompt

Use this prompt in a new chat:

`Continue IBEMS project with strict portal split: Store Portal handles per-store operations; School Accounting Portal handles salary import and debt settlement runs. Next priority is School Accounting Department workflow.`

## Session Checklist Status (2026-04-22)

- [x] Settlement run idempotency lock
  - Added DB-level uniqueness for `settlement_runs.run_month`
  - Added duplicate-safe handling in settlement apply flow (`409` on duplicate month)
- [x] Settlement run UX polish (optional)
  - Disable/relabel apply action when selected month already has a completed run
  - Offer quick link to open existing run details
- [x] Store financial reporting (phase 1)
  - Added `/store/reports` page with period filters (today/week/month/custom)
  - Added `/store/reports/summary` API with:
    - summary KPIs (sales, estimated cost/profit, margin, ticket)
    - payment breakdown
    - top products
    - daily trend

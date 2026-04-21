# IBEMS Project Context

Last updated: 2026-04-21

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

- POS products are still hardcoded in UI
- no dynamic product loading from DB yet
- no QR scanning yet
- accounting/admin/user modules are still starter-level UI

## Next Steps (In Order)

1. Make POS dynamic
- Create product listing API endpoint
- Load products from DB in POS JS
- Replace hardcoded product row

2. Improve Store POS UX
- quantity controls (+/-)
- remove line item
- optional client-side stock safeguards before submit

3. Accounting Portal
- debt monitoring
- settlement workflow
- reporting screens

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

`Continue IBEMS project. POS backend, auth, role filters, and basic POS UI are done. Next step: make POS products dynamic from database.`

# IBEMS (CodeIgniter 4)

Integrated Business Enterprise Management System starter implementation for capstone.

## Features Included
- Authentication and session login/logout
- Login security hardening:
  - account lockout after repeated failed login attempts
  - input validation for credentials
- RBAC filters (`ADMIN`, `STORE_SYSTEM`, `ACCOUNTING_OFFICE`, `USER`)
- Global request hardening:
  - invalid character filter
  - secure response headers
- Core schema migrations for users, stores, balances, POS, inventory, imports, settlements, notifications, audit logs, app settings
- Seeder with demo users, stores, products (with categories), and balance profiles
- Admin modules: users, stores, products, system settings
- HR CSV import with validation and credit auto-compute
- POS cashier flow:
  - click products to cart
  - category tabs + search
  - quantity controls
  - keyboard shortcuts: `F2` search, `F4` customer type, `F8` checkout
  - debt/advance payment rules by customer type
  - offline queue fallback (transactions stored locally when offline)
  - manual pending sync button (`/sync/transactions/batch`)
- Thermal-style receipt page with print button and configurable footer
- Accounting credit override + global settle
- Reports:
  - daily sales
  - debt aging
  - low stock
  - settlement history
  - audit trail
- User self-service page (`/me`) with profile + password update
- Notification plumbing + daily summary command

## Quick Start
1. Create database `ibems` in MySQL.
2. Update `.env` DB and SMTP settings.
3. Install dependencies:
   - `composer install`
4. Run migrations and seeders:
   - `php spark migrate`
   - `php spark db:seed IbemsSeeder`
5. Start local server:
   - `php spark serve`
6. Open [http://localhost:8080](http://localhost:8080)

## Demo Accounts
- Admin: `admin@ibems.local` / `admin1234`
- Accounting: `accounting@ibems.local` / `accounting1234`
- Store: `store@ibems.local` / `store1234`
- Faculty: `faculty@ibems.local` / `faculty1234`
- Student: `student@ibems.local` / `student1234`

## CSV Import Format
Columns in order:
1. `employee_id`
2. `name`
3. `email`
4. `monthly_salary`

## Useful Commands
- Run daily summary email command:
  - `php spark ibems:send-daily-summary`
- Run unit tests:
  - `vendor\\bin\\phpunit --no-coverage tests\\unit\\CreditServiceTest.php`

## Notes
- `client_txn_id` is unique for idempotent transaction handling.
- Walk-in transactions use `customer_type = walk_in` and `user_id = null`.
- Debt and advance payment are restricted to `faculty` and `staff` user types.
- Admin system settings are available at `/settings`.

# IBEMS Capstone

[![CI](https://github.com/DonDon14/ibems-capstone/actions/workflows/ci.yml/badge.svg)](https://github.com/DonDon14/ibems-capstone/actions/workflows/ci.yml)

Integrated Business Enterprise Management System for school store operations, POS transactions, inventory, debt monitoring, accounting settlement, and role-based portals.

Database migration guidance: [Supabase Migration Plan](docs/SUPABASE_MIGRATION_PLAN.md).

## Current Local Setup

Active local project path:

```powershell
D:\xampp\htdocs\ibems-tailwind-test
```

Default local URL:

```text
http://localhost:8080
```

The app is configured in `.env` with:

```ini
app.baseURL = 'http://localhost:8080/'
database.default.database = ibems_tailwind_test
database.default.username = root
database.default.password =
database.default.port = 3306
```

## How To Run

1. Start MySQL from XAMPP.

2. Open PowerShell in the project folder:

```powershell
cd D:\xampp\htdocs\ibems-tailwind-test
```

3. Install PHP dependencies if `vendor` is missing:

```powershell
composer install
```

4. Apply database migrations:

```powershell
php spark migrate
```

5. Seed demo data if the database is empty:

```powershell
php spark db:seed InitialSeeder
```

Optional smaller account-only seeder:

```powershell
php spark db:seed PortalAccountsSeeder
```

6. Start the app on the configured port:

```powershell
php spark serve --port 8080
```

7. Open:

```text
http://localhost:8080
```

Important: CodeIgniter generates CSS, JS, image, and debugbar URLs from `.env` `app.baseURL`. The browser host and port must match `app.baseURL`. This project is currently configured for `http://localhost:8080`, so use exactly that URL.

## If Port 8080 Is Busy

Using another port requires two changes. First update `.env`:

```ini
app.baseURL = 'http://localhost:8082/'
```

Then restart the app on the same port:

```powershell
php spark serve --port 8082
```

Do not browse to `8082` while `.env` still says `8080`. The HTML may load, but the CSS, JS, images, and debugbar will still point to `8080`, causing failed asset requests in the console.

To return to the default port, change `.env` back to:

```ini
app.baseURL = 'http://localhost:8080/'
```

Then restart:

```powershell
php spark serve --port 8080
```

## Demo Credentials

All seeded demo accounts use this password:

```text
123456
```

Primary seeded accounts:

| Portal | Email | Password |
| --- | --- | --- |
| Admin | `admin@ibems.local` | `123456` |
| Accounting | `accounting@ibems.local` | `123456` |
| Accounting Supervisor | `accounting.supervisor@ibems.local` | `123456` |
| Store - Main | `store.main@ibems.local` | `123456` |
| Store - Tech | `store.tech@ibems.local` | `123456` |
| User - Faculty | `maria.santos@ibems.local` | `123456` |
| User - Staff | `mark.delacruz@ibems.local` | `123456` |
| User - Student | `jane.estrella@ibems.local` | `123456` |

Additional `PortalAccountsSeeder` accounts, if seeded:

| Portal | Email | Password |
| --- | --- | --- |
| Store | `store@ibems.local` | `123456` |
| User | `user@ibems.local` | `123456` |

## Debt PIN Testing

Debt purchases require the debtor to set their own debt authorization PIN first.

1. Log in as a faculty or staff user.
2. Open User Dashboard.
3. Set a 4 to 6 digit Debt Authorization PIN.
4. Log in as a Store user.
5. In POS, choose Debt payment, select the debtor, enter their PIN, then complete checkout.

The PIN is stored only as a hash. Store and admin users cannot view it. Five
failed attempts within 15 minutes lock debt PIN authorization for 15 minutes
across all store terminals. Security events are recorded without the PIN.

## Frontend Assets

Tailwind support is available, but most current screens still use project CSS under `public/assets/css`.

Install Node dependencies if needed:

```powershell
npm install
```

Build Tailwind assets:

```powershell
npm run build:tailwind
```

Watch Tailwind assets during UI work:

```powershell
npm run watch:tailwind
```

## Useful Checks

Run the complete automated test suite:

```powershell
npm.cmd test
```

Build Tailwind and verify the generated asset has no uncommitted differences:

```powershell
npm.cmd run build:tailwind
git diff --exit-code -- public/assets/css/tailwind.css
```

Run PHP syntax checks on changed PHP files:

```powershell
php -l app\Controllers\UserController.php
```

Run JavaScript syntax checks on changed JS files:

```powershell
node --check public\assets\js\store-pos.js
```

Check migration status:

```powershell
php spark migrate:status
```

## Common Startup Mistakes

- Opening `http://127.0.0.1:8080` when `php spark serve` announced `http://localhost:8080`.
- Opening `http://localhost:8082` while `.env` `app.baseURL` is still `http://localhost:8080/`.
- Starting `php spark serve --port 8082` without changing `.env` to the same `8082` base URL.
- Starting the PHP server before MySQL is running.
- Changing `.env` `app.baseURL` without restarting `php spark serve`.
- Running commands from `D:\BEMS` instead of `D:\xampp\htdocs\ibems-tailwind-test`.
- Using the old `D:\xampp\htdocs\ibems` project folder instead of the current advanced version.

## Repository

GitHub repository:

```text
https://github.com/DonDon14/ibems-capstone
```

Default integration branch:

```text
main
```

Feature work should be developed on a separate branch and merged through a pull request after CI passes.

# Supabase Migration Plan

Date: 2026-08-11

## Staging progress

- Supabase project: `ibems-staging`
- Project reference: `pukjmscgjtmqvhdncjpo`
- Region: Southeast Asia (Singapore), `ap-southeast-1`
- PostgreSQL extensions available locally: `pgsql` and `pdo_pgsql`
- Baseline source: `database/postgresql/001_ibems_baseline.sql`
- Baseline applied to staging: `2026-08-11-baseline`
- Verification result: 26 public tables and matching schema version
- Data API remains disabled; staging contains only the controlled demo seed and
  acceptance records, with no production rows
- Database password reset completed by the project owner
- IPv4 session pooler: `aws-0-ap-southeast-1.pooler.supabase.com:5432`
- Session-pooler user: `postgres.pukjmscgjtmqvhdncjpo`
- Password-prompted staging launcher:
  `database/postgresql/Invoke-IbemsSupabase.ps1`
- Corrected runtime overrides now identify the application connection as
  `Postgre / postgres`; secure seed, smoke, auth, data-integrity, and route
  audits pass against staging
- Browser acceptance passes for Admin, Accounting, Store Officer, and User
  portals with populated PostgreSQL-backed metrics and history
- Concurrent PostgreSQL acceptance passes: Tech Annex cash transaction `4`
  (PHP 55.00) and an Accounting credit-limit write overlapped successfully;
  Notebook stock changed `140 -> 139`, while Maria Santos remained at PHP
  850.00 debt and a PHP 7,000.00 credit limit
- Canonical logical migration matched all 25 application tables by row count
  and SHA-256 hash; the 13 normalized financial tables also match exactly
- PostgreSQL logical backup restoration matched all 25 pre-rehearsal table
  hashes, after which the reconciled MySQL demo dataset was imported again and
  passed the complete PostgreSQL preflight
- Staging now contains the reconciled 9-user, 4-store development/demo dataset;
  no production data was imported

## Decision

IBEMS should treat Supabase as a managed PostgreSQL database first. The existing
CodeIgniter authentication, authorization, services, audit trail, and UI remain
the application authority during the first migration. Supabase Auth, Storage,
Realtime, and direct browser database access are explicitly deferred.

This keeps the migration focused and avoids replacing several working systems at
the same time.

## Current state

- Production-style local development uses MySQL/MariaDB through CodeIgniter's
  `MySQLi` driver.
- Automated tests use SQLite.
- The repository contains no Supabase credentials.
- An empty Supabase staging project and PostgreSQL baseline now exist.
- Runtime queries mostly use CodeIgniter Query Builder.
- The audit-log daily count was made database-neutral by replacing MySQL
  `CURDATE()` logic with explicit application date boundaries.

## Known PostgreSQL blockers

The existing historical migrations cannot be run unchanged against Supabase:

1. MySQL `ENUM` columns are used for roles, user types, payment methods, and
   other status fields.
2. Integer definitions use MySQL `unsigned` metadata.
3. Several migrations use raw MySQL DDL, including `ADD UNIQUE KEY`,
   `DROP INDEX`, and `MODIFY ... ENUM`.
4. Auto-incrementing IDs must be emitted as PostgreSQL identity/sequence-backed
   columns.
5. Date grouping, boolean defaults, index names, and foreign-key behavior must
   be verified against a real PostgreSQL database.

These are schema portability issues. They do not justify changing the working
local database before a Supabase target passes acceptance.

## Target architecture

```text
Browser
  -> CodeIgniter application
      -> session authentication and role filters
      -> domain services and audit logging
      -> PostgreSQL connection using CodeIgniter Postgre driver
          -> Supabase managed PostgreSQL
```

For the first release:

- Do not expose the Supabase service-role key to the browser.
- Do not use the public Data API as an alternative write path.
- Do not duplicate users into Supabase Auth.
- Do not enable Realtime for financial tables.
- Keep product/user uploads on the current filesystem until storage migration
  has its own authorization and rollback plan.

## Required Supabase project choices

Before creating the project, the owner must choose:

- Project name, recommended: `ibems-staging`
- Region nearest the university and deployment host
- A strong database password stored outside Git
- Whether this is staging or production; the first project must be staging

Free-plan capacity is acceptable for migration testing and demonstrations, but
not a production reliability commitment.

## Migration stages

### Stage 1: Create an empty staging project

Create only the project. Do not paste credentials into source files and do not
import live data.

Record these values privately:

- Host
- Port
- Database name
- User
- Password
- SSL requirement

### Stage 2: Build a PostgreSQL baseline schema

Create a new PostgreSQL-specific baseline migration from the final logical
schema. Do not replay MySQL-specific historical alteration migrations.

Use:

- `VARCHAR` plus `CHECK` constraints for controlled status values
- PostgreSQL identity-backed integer primary keys
- `NUMERIC(12,2)` for currency
- `TIMESTAMP WITHOUT TIME ZONE` while the application owns Asia/Manila time
- Explicit unique indexes and foreign keys

### Stage 3: Validate an empty database

Point a separate environment at Supabase using CodeIgniter's `Postgre` driver.
Then run:

- Schema migration
- Demo seeders
- `php spark ibems:preflight`
- PHPUnit
- Browser acceptance for every portal

The local MySQL environment remains the rollback path.

### Stage 4: Rehearse data conversion

Export a sanitized copy of MySQL data, convert it, and import it into staging.
Validate:

- Table row counts
- Primary and foreign keys
- User role mappings
- Balances and outstanding debt totals
- Transaction and transaction-item totals
- Inventory quantities
- Debt cashbook totals
- Deduction batches, investigations, and audit history
- PostgreSQL sequences after imported IDs

No production cutover is allowed if any financial reconciliation differs.

### Stage 5: Cutover

Only after staging sign-off:

1. Announce a write freeze.
2. Take a final MySQL backup.
3. Import the final delta.
4. Run integrity and acceptance checks.
5. Switch the deployment's database environment variables.
6. Monitor writes, errors, balances, and audit logs.

Keep MySQL read-only and recoverable until the rollback window closes.

## Environment shape

Secrets belong in the deployment environment or local ignored `.env`, never in
Git. The launcher uses dedicated process-only variables so committed MySQL
`.env` values cannot override a Supabase session:

```ini
IBEMS_DATABASE_HOSTNAME = 'project-pooler-host'
IBEMS_DATABASE_NAME = 'postgres'
IBEMS_DATABASE_USERNAME = 'project-user'
IBEMS_DATABASE_PASSWORD = 'secret-from-password-manager'
IBEMS_DATABASE_DRIVER = 'Postgre'
IBEMS_DATABASE_PORT = 5432
IBEMS_DATABASE_SCHEMA = 'public'
IBEMS_DATABASE_SSLMODE = 'require'
```

The exact host, port, and username must be copied from the selected Supabase
connection mode. They must not be guessed.

The staging project now uses the exact IPv4 session-pooler values recorded
above. Run a password-safe validation from the project root with:

```powershell
powershell -ExecutionPolicy Bypass -File database\postgresql\Invoke-IbemsSupabase.ps1 -Action validate -CredentialDialog
```

The `validate` action prompts once, keeps the password only in process memory,
and runs seed, preflight, normalized snapshot, and smoke checks. To start a
detached Supabase-backed local server for browser acceptance:

```powershell
powershell -ExecutionPolicy Bypass -File database\postgresql\Invoke-IbemsSupabase.ps1 -Action serve-background -Port 8083 -CredentialDialog
```

## Security baseline

- Use a dedicated staging project before production.
- Rotate any credential exposed in screenshots, chat, source, or logs.
- Keep database credentials server-side.
- Prefer a restricted application database role over the owner role.
- Require SSL.
- Back up before every conversion rehearsal.
- Keep application authorization enforced even if PostgreSQL RLS is later added.
- Add RLS only as defense in depth after application behavior is stable.

## Acceptance gate

Supabase is ready for IBEMS only when:

- All migrations and seeders succeed on a fresh PostgreSQL database.
- All automated tests and IBEMS preflight checks pass.
- All portals pass browser acceptance.
- Concurrent POS and Accounting transactions are tested.
- Financial reconciliation matches the MySQL source exactly.
- Backup and rollback restoration are rehearsed.
- No privileged key is present in browser assets or Git history.

Current verified completion: 7 of 7 gates (100%). This completes staging
acceptance only; no production cutover, push, or deployment is authorized.

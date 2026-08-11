# Supabase Production Cutover Runbook

Date prepared: 2026-08-11

## Status

Supabase staging acceptance is complete at 7 of 7 gates. Production cutover is
not authorized and must not reuse the staging launcher unchanged. The current
launcher is deliberately pinned to staging project `pukjmscgjtmqvhdncjpo` and
uses a staging-only reset guard.

Validated staging evidence:

- PostgreSQL baseline: 26 public tables, version `2026-08-11-baseline`
- Canonical reconciliation: 25 of 25 application tables matched MySQL counts
  and SHA-256 hashes
- Financial reconciliation: 13 of 13 normalized financial tables matched
- Logical backup restoration: 25 of 25 tables matched the pre-rehearsal backup
- Browser acceptance: Admin, Accounting, Store Officer, and User
- Concurrent write acceptance: POS transaction plus Accounting balance update
- PHPUnit: 55 tests and 319 assertions

## Current production blockers

Cutover is a **NO-GO** until every item below has an owner and evidence:

1. A separate production Supabase project, region, connection mode, and project
   reference have not been explicitly approved.
2. A least-privilege application database role has not been created and tested;
   staging currently connects through the project database owner.
3. The deployment host and server-side secret manager have not been identified
   or tested with the `IBEMS_DATABASE_*` runtime variables.
4. A durable off-machine MySQL backup and native PostgreSQL backup method have
   not been selected. PostgreSQL client backup tools are not currently available
   on this workstation.
5. A real write-freeze mechanism has not been selected. A verbal freeze is not
   sufficient; the web application or reverse proxy must reject writes during
   the final export and delta window.
6. Production monitoring, incident ownership, maintenance-window timing, and
   rollback authority have not been assigned.

## Required approvals

Record approval for each boundary separately:

- Publish/merge the branch
- Provision or modify the production Supabase project
- Create production database roles and secrets
- Enable the application write freeze
- Read/export the production MySQL database
- Import or modify production PostgreSQL data
- Switch the deployed application database target
- Roll back to MySQL if a stop condition occurs

No approval implies no action at that boundary.

## Phase 1: Release candidate

1. Review commits from `origin/main` through the chosen release commit.
2. Run CI from a pull request and require all checks to pass.
3. Produce an immutable deployment artifact from that exact commit.
4. Record the commit SHA, dependency lockfile hashes, PHP version, Node version,
   and artifact checksum.
5. Run the complete MySQL test/preflight gate on the deployment host.
6. Confirm that no password, service-role key, transfer JSON, database dump, or
   writable log is present in the artifact.

## Phase 2: Production PostgreSQL preparation

1. Provision a production project separate from staging.
2. Apply `database/postgresql/001_ibems_baseline.sql` to an empty database.
3. Run `database/postgresql/002_verify_baseline.sql` and record the 26-table and
   schema-version result.
4. Create a restricted application role with only the schema/table/sequence
   privileges IBEMS requires. Do not deploy with the project owner credential.
5. Store connection values only in the deployment secret manager using:

   - `IBEMS_DATABASE_HOSTNAME`
   - `IBEMS_DATABASE_NAME`
   - `IBEMS_DATABASE_USERNAME`
   - `IBEMS_DATABASE_PASSWORD`
   - `IBEMS_DATABASE_DRIVER=Postgre`
   - `IBEMS_DATABASE_PORT`
   - `IBEMS_DATABASE_SCHEMA=public`
   - `IBEMS_DATABASE_SSLMODE=require`

6. Validate connectivity from the deployment host without importing data.
7. Confirm platform backup configuration and independently create a restorable,
   encrypted PostgreSQL backup before the cutover import.

## Phase 3: Final MySQL capture

1. Display the maintenance page and technically block all application writes.
2. Record the freeze timestamp and verify POST/write endpoints are unavailable.
3. Create a native, transactionally consistent MySQL backup.
4. Copy the encrypted backup off the application host and verify its checksum.
5. Generate the canonical logical transfer from the frozen MySQL database.
6. Record table counts and hashes without printing row data or credentials.
7. Keep MySQL intact and recoverable; do not drop, overwrite, or migrate it in
   place.

## Phase 4: Import and reconciliation

Production import tooling must be derived from the validated logical transfer
command but must have a production-specific target configuration, explicit
approval flag, backup requirement, and project-reference check. Do not edit the
staging host in `Invoke-IbemsSupabase.ps1` and run it against production.

1. Verify the target project reference and database role out of band.
2. Verify the pre-import PostgreSQL backup and checksum.
3. Import the canonical transfer in one guarded transaction.
4. Reset and verify every PostgreSQL identity sequence.
5. Compare all 25 table counts and SHA-256 hashes.
6. Compare the 13 financial snapshots exactly, including balances, debt,
   transactions, line items, inventory, cash movements, settlements,
   deductions, and audit logs.
7. Run `ibems:preflight` against production.
8. Run read-only browser acceptance for all four portals.
9. Perform one explicitly approved, low-value write rehearsal and reconcile its
   transaction, stock, balance, and audit effects.

## Phase 5: Traffic switch

Only the cutover owner may issue the GO decision.

1. Point the immutable release artifact at the production PostgreSQL secrets.
2. Restart only the required application processes.
3. Verify database identity is `Postgre` and the intended production project.
4. Re-run smoke, auth, data-integrity, and route audits.
5. Remove the maintenance page only after the checks pass.
6. Monitor application errors, connection saturation, write latency, failed
   transactions, debt totals, inventory changes, and audit-log continuity.
7. Keep frozen MySQL read-only throughout the rollback window.

## Immediate rollback triggers

Rollback without further experimentation if any of these occurs:

- Any table count or hash mismatch
- Any balance, debt, transaction, inventory, cash, settlement, deduction, or
  audit discrepancy
- Authentication or authorization failure in any portal
- Duplicate or missing transaction/audit identifiers
- PostgreSQL sequence collision
- Repeated database timeouts or pool exhaustion
- Any write reaching MySQL after the freeze or reaching both databases
- Unclear database identity or unexpected project reference

## Rollback procedure

1. Re-enable the maintenance page and block writes.
2. Record the rollback timestamp and incident reason.
3. Point the prior immutable application release back to the unchanged MySQL
   secrets.
4. Restart only required application processes.
5. Run MySQL smoke, auth, data-integrity, and route audits.
6. Reconcile all writes accepted during the PostgreSQL window before reopening.
7. Reopen traffic only after the rollback owner signs off.
8. Preserve PostgreSQL read-only for investigation; do not delete evidence.

## Completion record

Capture these values during an authorized cutover:

- Release commit and artifact checksum
- Freeze, import, GO/NO-GO, traffic-open, and rollback-window timestamps
- MySQL and PostgreSQL backup locations and checksums
- Source and target counts/hashes for all 25 tables
- Financial snapshot comparison for all 13 tables
- Preflight and browser-acceptance results
- Approvers, operators, monitoring owner, and rollback owner
- Any deviations, incidents, and remediation decisions

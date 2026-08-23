# Render Free Production Deployment

IBEMS runs on Render as a Docker web service in Singapore and continues to use the existing
Supabase PostgreSQL database and Supabase Storage bucket. Render receives only
environment-variable names from `render.yaml`; secret values are entered in the
Render dashboard and are never committed.

The service follows the `production` branch. Automatic deployments are disabled,
so local development and pushes to other branches cannot change the live system.
Releases require an intentional merge or cherry-pick into `production`, followed
by a manual deployment in Render.

## Required secret values

During the initial Blueprint creation, Render prompts for:

- `IBEMS_DATABASE_PASSWORD`: the Supabase staging database password.
- `IBEMS_SUPABASE_SECRET_KEY`: the server-side Supabase service-role key used
  only by the PHP backend for managed image uploads.

Do not paste either value into Git, application logs, issue comments, or chat.

## Automatic hosted bootstrap

Before Apache starts, `ibems:hosted-bootstrap` applies the required idempotent PostgreSQL
scripts:

- `010_salary_grade_profiles.sql`
- `011_render_hosted_sessions.sql`
- `012_dynamic_salary_schedules.sql`
- `014_department_debt_accounts.sql`

The command stops if the verified IBEMS baseline tables are absent. It never
seeds, truncates, drops, or imports application data. Database sessions prevent
login loss when Render replaces its temporary filesystem.

## Free-tier expectations

- The web service sleeps after inactivity and may take about one minute to wake.
- Open the site several minutes before a demonstration.
- Uploaded images are stored in Supabase Storage, not the Render filesystem.
- This free configuration is the capstone production/demo service. It is not a
  paid high-availability payroll deployment.

## Verification after deployment

1. Confirm `/healthz` returns `{"status":"ok","service":"ibems"}`.
2. Sign in through every role portal.
3. Verify Accounting can open the employee list and save a test salary-grade
   profile only with an explicitly approved test employee.
4. Verify store and product images load from Supabase Storage.
5. Let the service sleep, wake it, and confirm the user can sign in again.

# IBEMS Session Handoff

Last updated: 2026-06-30

## Active Project

- Path: `D:\xampp\htdocs\ibems-tailwind-test`
- Framework: CodeIgniter 4.7.2
- Local URL: `http://localhost:8080`
- Database: `ibems_tailwind_test`
- Primary tracker: `docs/SYSTEM_IMPLEMENTATION_CHECKLIST.md`
- Source requirements document: `docs/source-documents/Ocero Group.docx`

## How To Resume In A New Chat

Use this prompt:

```text
Continue the IBEMS project from D:\xampp\htdocs\ibems-tailwind-test.
First read docs/SESSION_HANDOFF.md and docs/SYSTEM_IMPLEMENTATION_CHECKLIST.md.
Use docs/source-documents/Ocero Group.docx as the source requirements document when functionality is unclear.
Phase 3 is complete. Start Phase 4: User Portal polish.
Do not revert existing changes. Inspect current repo state before editing.
```

## How To Run Locally

Start MySQL from XAMPP, then run:

```powershell
cd D:\xampp\htdocs\ibems-tailwind-test
php spark migrate
php spark serve --port 8080
```

Open:

```text
http://localhost:8080
```

Important: `.env` currently uses `app.baseURL = 'http://localhost:8080/'`. Use `localhost:8080`, not a different port, unless `.env` is changed to match.

## Demo Credentials

All seeded demo accounts use password `123456`.

| Portal | Email |
| --- | --- |
| Admin | `admin@ibems.local` |
| Accounting | `accounting@ibems.local` |
| Store - Main | `store.main@ibems.local` |
| Store - Tech | `store.tech@ibems.local` |
| User - Faculty | `maria.santos@ibems.local` |
| User - Staff | `mark.delacruz@ibems.local` |
| User - Student | `jane.estrella@ibems.local` |

## Completed Scope

Phase 1 Store Workflow is complete:

- Daily store opening/closing sessions.
- POS locked unless today's store day is open.
- Store Inventory supports supplier, bin/location, low-stock threshold, duplicate guards, stock-in, and adjustments.
- POS includes stock-aware checkout, debt PIN verification, projected debt/credit messaging, receipt consistency, and payment method loading states.
- Store Reports/History were reconciled with POS receipt data.

Phase 2 Accounting Workflow is complete:

- Debt list/profile flows.
- CSV import preview validation.
- Settlement preview/apply protections.
- Settlement export/print summary.
- Accounting dashboard KPI and alert improvements.

Phase 3 Admin Workflow is complete:

- Admin Dashboard now uses real metrics and operational alerts.
- Store Management supports optional officer assignment, status filtering, and non-redundant card actions.
- Store Details has operational snapshot metrics.
- User Management supports multi-role create/edit/import, credit limit, salary, status, and clearer view/edit behavior.
- Admin Products is now a read-only Product Oversight screen; Store Inventory remains the operational product management surface.
- Admin Audit screen was added for critical audit log review.

## Current Next Phase

Start Phase 4: User Portal.

Checklist items:

- User Dashboard: review debt summary, recent purchases, credit limit, and current balance presentation.
- User History: verify filters, receipt links, and transaction details.
- User Cashbook: confirm whether cashbook route has a complete UI; implement or remove route if incomplete.
- User Receipt: align receipt display with Store receipt and shared `receipt-standard.js`.
- User Portal: add clear debt status messaging for paid, unpaid, partially settled, and over-limit states.

## Validation Habits

Before handoff after edits:

```powershell
php -l app\Controllers\SomeController.php
php -l app\Views\some-view.php
node --check public\assets\js\some-file.js
git diff --check
```

After migrations:

```powershell
php spark migrate
```

For route checks:

```powershell
php spark routes
```

## Notes For Future Agents

- The active project is not `D:\BEMS`; it is `D:\xampp\htdocs\ibems-tailwind-test`.
- The browser should use `http://localhost:8080`.
- Do not use `127.0.0.1:8082` unless `.env` is intentionally changed.
- The app may have a dirty git worktree. Preserve user changes and inspect before editing.
- Screenshots uploaded in the previous chat were temporary diagnostic references. The important source document has been copied into `docs/source-documents/`.

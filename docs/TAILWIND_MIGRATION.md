# Tailwind Migration Branch

This project now has two UI branches so you can switch anytime:

- `bootstrap-polish-2026`:
  Stable Bootstrap-era baseline (your rollback branch).
- `tailwind-2026-migration`:
  Tailwind-enabled UI migration branch.

## What was migrated

- Added Tailwind v4 build tooling (`@tailwindcss/cli`).
- Added Tailwind source file at `resources/css/tailwind.css`.
- Generated compiled stylesheet at `public/assets/css/tailwind.css`.
- Wired Tailwind stylesheet into shared layouts and auth pages:
  - `app/Views/layouts/admin.php`
  - `app/Views/layouts/accounting.php`
  - `app/Views/layouts/store.php`
  - `app/Views/layouts/user.php`
  - `app/Views/auth/login.php`
  - `app/Views/auth/select_role.php`

## Build commands

```bash
npm run build:tailwind
npm run watch:tailwind
```

## Notes

- Existing functional UI remains intact while Tailwind is layered in.
- You can now progressively replace legacy CSS with Tailwind utilities/components page by page.

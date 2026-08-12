# System Administrator Access Audit

This is the route-level classification for every endpoint whose role filter combines `ADMIN` with an operational role. It supplements `ROLE_PERMISSION_MATRIX.md` and must change with `app/Config/Routes.php`.

## Classification rule

- **Read-only inspection:** retained only when the endpoint does not persist business state.
- **Legitimate override:** must be `ADMIN`-only, exceptional, reasoned, and audited. It is intentionally not a mixed-role route.
- **Remove:** the System Administrator must use a separately assigned operational role and deliberately switch portals.

## Retained mixed-role routes: read-only inspection

| Area | Method and routes | Classification | Reason |
| --- | --- | --- | --- |
| Portal dispatcher | `GET dashboard` | Read-only inspection | Chooses the landing page for the active role; it performs no business action. |
| Store pages | `GET store/pos`, `store/dashboard`, `store/history`, `store/inventory`, `store/reports`, `store/staff-records`, `store/settings`, `store/my-stores`, `store/products`, `store/categories` | Read-only inspection | Renders operational state. Every related create, update, delete, POS, inventory, and store-day action remains `STORE_SYSTEM`-only. |
| Store data | `GET store/payment-methods`, `store/day-session/status`, `store/opening-balance/status`, `store/cash-movements`, `store/reports/summary`, `store/debt-customers` | Read-only inspection | Returns current store configuration, status, cash, report, or debt data without persistence. |
| Store records | `GET store/transactions`, `store/staff-transactions`, `store/transactions/(:num)`, `store/receipt/(:num)`, `store/inventory/movements` | Read-only inspection | Transaction, receipt, and stock-movement history only. |
| Accounting pages and data | `GET accounting/dashboard`, `accounting/dashboard/data`, `accounting/debts`, `accounting/debts/data`, `accounting/debts/profile`, `accounting/debts/history`, `accounting/debts/daily-summary` | Read-only inspection | Institution-wide financial oversight without workflow mutation. |
| Accounting workflow status | `GET accounting/deduction-workflow`, `accounting/debt-investigations`, `accounting/settlement/preview`, `accounting/settlement/runs`, `accounting/settlement/runs/(:num)` | Read-only inspection | Shows workflow state, calculations, and history; operational approvals and transitions remain Accounting-only. |
| CSV preview | `POST accounting/debts/preview-csv` | Read-only inspection | POST is required for the temporary file upload, but the controller only parses the file and reads matching users. It does not insert, update, delete, or retain the upload. The actual import remains `ACCOUNTING_OFFICE`-only. |

## Permissions removed

| Routes | Classification | Decision |
| --- | --- | --- |
| All `user/*` routes, including `POST user/debt-pin/set` | Remove | These are self-service routes scoped to the signed-in employee. System Admin inspection already exists under `admin/user-view`; changing a personal debt PIN is not administration. All now require `USER` only. |
| `POST accounting/debt-investigations` | Remove | Opening an investigation changes accounting workflow state and requires `ACCOUNTING_OFFICE`. |
| `POST accounting/debt-investigations/(:num)/recommend` | Remove | Recommending an outcome changes accounting workflow state and requires `ACCOUNTING_OFFICE`. |

All other operational mutations are likewise role-exclusive: Store Officer routes require `STORE_SYSTEM`, Accounting workflow mutations require `ACCOUNTING_OFFICE`, and Store Administrator review routes require `STORE_SUPERVISOR`.

## Legitimate overrides

These are not mixed-role permissions. They are narrow `ADMIN`-only escalation endpoints:

| Route | Classification | Safeguard |
| --- | --- | --- |
| `POST admin/store-day-sessions/(:num)/review` | Legitimate override | Used only when independent assigned-store review cannot be completed; the review note and outcome are audited. |
| `POST store/opening-balance/reset` | Legitimate override | Requires a non-empty override reason and creates an audit event. |

## System governance

The remaining `ADMIN`-only `/admin/*` routes manage users, role assignments, store definitions, officer and supervisor assignments, global products, and audit records. They are institution-level governance, not operational-role bypasses.

## Enforcement

`AdminAccessRouteConfigTest` resolves every route's named policy through `app/Config/Authorization.php` and scans every policy shared with `ADMIN`. A mixed-role non-GET route fails unless it is the explicitly classified non-persistent CSV preview. The same test prevents raw role lists, unknown policies, and `ADMIN` returning to the personal User portal. Any new exception requires a business justification, audit design where applicable, documentation, and a dedicated authorization test before the route is added.

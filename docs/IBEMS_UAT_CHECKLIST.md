# IBEMS UAT Checklist

Last updated: 2026-04-22

Use this checklist before deploy and after major feature merges.

## Legend
- `Pass`: Works as expected
- `Fail`: Defect found
- `N/A`: Not applicable in this test run

## A) Authentication & Role Access

| ID | Test Case | Expected Result | Status | Notes |
|---|---|---|---|---|
| A1 | Login with single-role account | Redirects directly to portal |  |  |
| A2 | Login with multi-role account | Redirects to `/auth/select-role` |  |  |
| A3 | Select ADMIN role | Opens admin dashboard |  |  |
| A4 | Select STORE_SYSTEM role | Opens store portal |  |  |
| A5 | Select ACCOUNTING_OFFICE role | Opens accounting portal |  |  |
| A6 | Access forbidden route with current role | Returns 403/blocked page |  |  |
| A7 | Logout | Session cleared, redirected to login |  |  |

## B) Admin Portal

| ID | Test Case | Expected Result | Status | Notes |
|---|---|---|---|---|
| B1 | Create user (single role) | User created + appears in table |  |  |
| B2 | Create user (multi-role) | User created + multiple role chips shown |  |  |
| B3 | Open user row | View modal opens first (not edit) |  |  |
| B4 | From view modal click Edit | Edit modal opens and saves changes |  |  |
| B5 | Create store + assign officer | Store created and officer linked |  |  |
| B6 | Reassign store officer | New officer gets STORE_SYSTEM access |  |  |

## C) Store Portal

| ID | Test Case | Expected Result | Status | Notes |
|---|---|---|---|---|
| C1 | Load POS products | Products render with categories/search |  |  |
| C2 | Add item to cart by click | Quantity and totals update |  |  |
| C3 | Add item by barcode/QR input | Product added correctly |  |  |
| C4 | Debt customer search | Live result and selection works |  |  |
| C5 | Complete cash transaction | Transaction stored + receipt modal shown |  |  |
| C6 | Complete debt transaction | Debt reflected in balances |  |  |
| C7 | Inventory add/update product | Data persists and table refreshes |  |  |
| C8 | Stock adjust/save | Inventory movement recorded |  |  |
| C9 | History row click receipt | Standard receipt modal opens |  |  |

## D) Accounting Portal

| ID | Test Case | Expected Result | Status | Notes |
|---|---|---|---|---|
| D1 | Debt search/filter | Correct employees shown |  |  |
| D2 | Manual debt deduction | Current debt reduced, log created |  |  |
| D3 | Full debt deduction | Debt set to zero, log created |  |  |
| D4 | Update credit limit | New limit persisted |  |  |
| D5 | Import HR CSV | Valid rows imported/updated |  |  |
| D6 | Settlement preview | Values computed correctly |  |  |
| D7 | Settlement apply (new month) | Run created successfully |  |  |
| D8 | Settlement apply (duplicate month) | Safely blocked (idempotent) |  |  |

## E) Data Integrity & Audit

| ID | Test Case | Expected Result | Status | Notes |
|---|---|---|---|---|
| E1 | Critical writes create audit log | `audit_logs` records exist |  |  |
| E2 | Store officer has STORE_SYSTEM role | Role mapping consistent |  |  |
| E3 | Payment method config updates in POS | Dynamic payment buttons update |  |  |
| E4 | Cash movement records in reports | Cash/eCash summaries update |  |  |

## F) Deployment Checks

| ID | Command | Expected Result | Status | Notes |
|---|---|---|---|---|
| F1 | `php spark migrate:status` | All expected migrations applied | Pass | Verified on April 22, 2026 |
| F2 | `php spark ibems:smoke` | Smoke check passed | Pass | Verified on April 22, 2026 |
| F3 | `php spark ibems:auth-audit` | Auth audit passed | Pass | Verified on April 22, 2026 |

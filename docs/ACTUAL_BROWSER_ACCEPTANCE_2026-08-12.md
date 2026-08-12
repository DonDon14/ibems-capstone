# IBEMS Actual Browser Acceptance

Test date: 2026-08-12  
Environment: Local MySQL application at `http://localhost:8080`  
Branch: `codex/ui-overlap-refinement`

## Result

The presentation checkpoint passed a real, role-by-role browser walkthrough.

- Administrator: dashboard, stores, products, employee records, and audit pages loaded. Employee search filtered Maria Santos from 14 records to one result. The Role dropdown opened with Arrow Down and closed with Escape.
- Store Officer: dashboard, POS catalog, inventory, reports, history, and employee records loaded. Product and employee search controls rendered one magnifier. POS correctly refused a new transaction because the August 11 Main Campus store day is still open.
- Store Supervisor: dashboard and assigned stores loaded, including Main Campus Store. Search and status controls rendered correctly.
- Accounting: dashboard and debt monitoring loaded. Searching Maria Santos returned one employee debt record. Workflow dropdowns rendered one enhanced trigger each.
- Employee: dashboard and transaction history loaded. Credit, debt, and available-credit figures were visible; date controls rendered one calendar trigger each. Opening the latest receipt displayed the same transaction reference as the history row.
- Runtime: no browser console errors were recorded during the walkthrough.

## Financial safety decision

No new sale was forced through Main Campus. The application correctly reported that the previous store day must be independently resolved first. The unresolved close-day cash values were not changed for testing.

## Presentation guidance

Use the existing completed August 11 transaction and its matching employee receipt/history for the transaction demonstration. Present the blocked POS state as evidence that close-day controls are enforced, not as a system failure.

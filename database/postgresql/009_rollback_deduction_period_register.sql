begin;

alter table public.deduction_batch_items
    drop constraint if exists deduction_batch_items_choice_check,
    drop column if exists preparation_reason,
    drop column if exists deduction_choice,
    drop column if exists salary_snapshot,
    drop column if exists period_credits_snapshot,
    drop column if exists period_debits_snapshot,
    drop column if exists opening_debt_snapshot;

commit;

begin;

alter table public.deduction_batch_items
    add column if not exists opening_debt_snapshot numeric(12,2) not null default 0,
    add column if not exists period_debits_snapshot numeric(12,2) not null default 0,
    add column if not exists period_credits_snapshot numeric(12,2) not null default 0,
    add column if not exists salary_snapshot numeric(12,2) not null default 0,
    add column if not exists deduction_choice varchar(20) not null default 'full',
    add column if not exists preparation_reason text null;

alter table public.deduction_batch_items
    drop constraint if exists deduction_batch_items_choice_check;

alter table public.deduction_batch_items
    add constraint deduction_batch_items_choice_check
    check (deduction_choice in ('full', 'partial', 'none'));

commit;

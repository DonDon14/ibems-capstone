-- Manual emergency rollback only. Do not run after case evidence or review activity begins.
begin;

drop table if exists public.store_day_variance_case_handoffs;
drop table if exists public.store_day_variance_case_attachments;
drop table if exists public.store_day_variance_case_events;
drop table if exists public.store_day_variance_cases;
delete from public.ibems_schema_meta where version = '2026-08-13-variance-governance';

commit;

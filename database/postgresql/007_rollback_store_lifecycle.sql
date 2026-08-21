-- Manual emergency rollback only. Do not run after lifecycle history is in operational use.
begin;

drop table if exists public.store_supervisor_assignment_history;
alter table public.stores drop column if exists reactivated_by;
alter table public.stores drop column if exists reactivated_at;
alter table public.stores drop column if exists deactivation_reason;
alter table public.stores drop column if exists deactivated_by;
alter table public.stores drop column if exists deactivated_at;
delete from public.ibems_schema_meta where version = '2026-08-13-store-lifecycle';

commit;

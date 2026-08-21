begin read only;

select version, applied_at, description
from public.ibems_schema_meta
where version = '2026-08-13-store-lifecycle';

select column_name, data_type, is_nullable
from information_schema.columns
where table_schema = 'public'
  and table_name = 'stores'
  and column_name in (
      'deactivated_at', 'deactivated_by', 'deactivation_reason',
      'reactivated_at', 'reactivated_by'
  )
order by ordinal_position;

select
    (select count(*) from public.store_supervisors) as current_mappings,
    (select count(*) from public.store_supervisor_assignment_history where ended_at is null) as active_history_rows,
    (select count(*) from public.store_supervisor_assignment_history where ended_at < assigned_at) as invalid_date_ranges;

rollback;

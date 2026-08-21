begin read only;

select version, applied_at, description
from public.ibems_schema_meta
where version = '2026-08-13-variance-governance';

select
    (select count(*) from public.store_day_sessions where coalesce(review_status, 'not_required') <> 'not_required') as reviewable_sessions,
    (select count(*) from public.store_day_variance_cases) as variance_cases,
    (select count(*) from public.store_day_variance_case_events) as variance_events,
    (select count(*) from public.store_day_variance_cases where status <> 'resolved') as unresolved_cases;

select
    sum(case when sds.id is null or store.id is null then 1 else 0 end) as orphan_cases,
    sum(case when variance_case.status = 'resolved' and (variance_case.resolved_at is null or variance_case.disposition is null) then 1 else 0 end) as incomplete_resolutions
from public.store_day_variance_cases variance_case
left join public.store_day_sessions sds on sds.id = variance_case.store_day_session_id
left join public.stores store on store.id = variance_case.store_id;

select case_ref, store_id, store_day_session_id, status, opened_at, disposition
from public.store_day_variance_cases
order by id;

rollback;

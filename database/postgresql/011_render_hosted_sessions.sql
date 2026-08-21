begin;

create table if not exists public.ci_sessions (
    id varchar(128) primary key,
    ip_address inet not null,
    "timestamp" timestamptz not null default current_timestamp,
    data bytea not null default '\x'
);

create index if not exists idx_ci_sessions_timestamp
    on public.ci_sessions ("timestamp");

insert into public.ibems_schema_meta (version, description)
values ('2026-08-21-render-hosting', 'Render-hosted database session support')
on conflict (version) do nothing;

commit;

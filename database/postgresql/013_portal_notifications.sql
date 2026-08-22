begin;

alter table public.notifications alter column txn_id drop not null;
alter table public.notifications add column if not exists audit_log_id bigint;
alter table public.notifications add column if not exists notification_type varchar(80) not null default 'activity';
alter table public.notifications add column if not exists title varchar(180) not null default 'IBEMS activity';
alter table public.notifications add column if not exists message text;
alter table public.notifications add column if not exists link_url varchar(500);
alter table public.notifications add column if not exists icon varchar(80) not null default 'bi bi-bell';
alter table public.notifications add column if not exists severity varchar(20) not null default 'info';
alter table public.notifications add column if not exists read_at timestamp without time zone;
alter table public.notifications add column if not exists email_status varchar(20) not null default 'pending';
alter table public.notifications add column if not exists email_attempts integer not null default 0;
alter table public.notifications add column if not exists email_next_attempt_at timestamp without time zone;
alter table public.notifications add column if not exists email_last_attempt_at timestamp without time zone;
alter table public.notifications add column if not exists dedupe_key varchar(190);
alter table public.notifications add column if not exists created_at timestamp without time zone;

create index if not exists notifications_user_created_idx on public.notifications(user_id, created_at);
create index if not exists notifications_email_queue_idx on public.notifications(email_status, email_next_attempt_at);
create unique index if not exists notifications_dedupe_key_uidx on public.notifications(dedupe_key);

commit;

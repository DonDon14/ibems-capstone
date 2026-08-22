select column_name, data_type, is_nullable
from information_schema.columns
where table_schema = 'public'
  and table_name = 'notifications'
  and column_name in ('audit_log_id', 'read_at', 'email_status', 'email_attempts', 'dedupe_key', 'created_at')
order by column_name;

select indexname
from pg_indexes
where schemaname = 'public'
  and tablename = 'notifications'
  and indexname in ('notifications_user_created_idx', 'notifications_email_queue_idx', 'notifications_dedupe_key_uidx')
order by indexname;

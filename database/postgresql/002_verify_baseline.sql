select
    (
        select count(*)
        from information_schema.tables
        where table_schema = 'public'
          and table_type = 'BASE TABLE'
    ) as public_table_count,
    (
        select version
        from public.ibems_schema_meta
        order by applied_at desc
        limit 1
    ) as baseline_version;

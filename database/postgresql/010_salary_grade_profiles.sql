begin;

alter table public.users add column if not exists employment_type varchar(20);
alter table public.users add column if not exists salary_grade varchar(30);
alter table public.users add column if not exists salary_step smallint;
alter table public.users add column if not exists salary_effective_date date;

-- A lower salary may put an employee temporarily over limit. Existing debt is
-- preserved and new debt is blocked by the application until capacity returns.
do $$
declare
    constraint_name text;
begin
    for constraint_name in
        select c.conname
        from pg_constraint c
        where c.conrelid = 'public.balances'::regclass
          and c.contype = 'c'
          and pg_get_constraintdef(c.oid) like '%current_debt%credit_limit%'
    loop
        execute format('alter table public.balances drop constraint %I', constraint_name);
    end loop;
end $$;

do $$
begin
    if not exists (select 1 from pg_constraint where conname = 'users_employment_type_check') then
        alter table public.users add constraint users_employment_type_check
            check (employment_type is null or employment_type in ('plantilla', 'cos', 'part_time'));
    end if;
    if not exists (select 1 from pg_constraint where conname = 'users_salary_step_check') then
        alter table public.users add constraint users_salary_step_check
            check (salary_step is null or salary_step between 1 and 8);
    end if;
end $$;

commit;

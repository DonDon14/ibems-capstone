begin;

create table if not exists public.salary_schedules (
    id bigserial primary key,
    code varchar(40) not null unique,
    name varchar(180) not null,
    effective_from date not null,
    effective_to date,
    is_active boolean not null default true,
    created_at timestamp without time zone default current_timestamp
);

create table if not exists public.salary_schedule_rates (
    id bigserial primary key,
    schedule_id bigint not null references public.salary_schedules(id) on update cascade on delete cascade,
    salary_grade smallint not null check (salary_grade between 1 and 33),
    salary_step smallint not null check (salary_step between 1 and 8),
    monthly_salary numeric(12,2) not null check (monthly_salary > 0),
    unique (schedule_id, salary_grade, salary_step)
);

alter table public.users add column if not exists salary_schedule_id bigint;
alter table public.balances add column if not exists credit_rate numeric(6,4) not null default 0.2500;

insert into public.salary_schedules (code, name, effective_from, is_active)
values ('PH-NG-2026-T3', 'Philippine National Government 2026 - Third Tranche', date '2026-01-01', true)
on conflict (code) do update set
    name = excluded.name,
    effective_from = excluded.effective_from,
    is_active = true;

with schedule as (
    select id from public.salary_schedules where code = 'PH-NG-2026-T3'
), step_rates(step, salaries) as (
    values
        (1, array[14634,15522,16486,17506,18581,19716,20914,22423,24329,26917,31705,33947,36125,38764,42178,45694,49562,53818,59153,66052,73303,81796,91306,102603,116643,131807,148940,167129,187531,210718,300961,356237,449157]::numeric[]),
        (2, array[14730,15636,16610,17636,18720,19862,21069,22627,24523,27131,31820,34069,36283,39141,42594,46152,50066,54371,59966,66970,74337,82963,92622,104209,118469,133870,151273,169752,190482,214038,306691,363257,462329]::numeric[]),
        (3, array[14849,15752,16732,17767,18858,20009,21224,22832,24720,27347,32109,34357,36599,39523,43015,46615,50576,54933,60793,67904,75388,84151,93962,105841,120326,135968,153644,172418,193480,217207,312532,370418]::numeric[]),
        (4, array[14968,15869,16856,17898,18998,20158,21382,23038,24917,27565,32401,34648,36919,39910,43442,47084,51092,55499,61632,68853,76456,85356,95330,107500,122212,138100,155906,174797,196528,220425,318182,377359]::numeric[]),
        (5, array[15089,15986,16982,18031,19137,20307,21539,23246,25117,27786,32697,34943,37244,40300,43874,47559,51614,56075,62486,69818,77542,86582,96823,109185,124131,140268,158353,177545,199624,223691,323938,384805]::numeric[]),
        (6, array[15211,16103,17106,18163,19280,20456,21699,23456,25318,28007,32998,35242,37572,40696,44310,48040,52144,56657,63353,70772,78645,87746,98341,110898,126079,142469,160235,180339,202005,227224,329989,392400]::numeric[]),
        (7, array[15333,16223,17234,18298,19423,20609,21859,23668,25521,28230,33302,35544,37904,41097,44753,48528,52678,57246,64236,71727,79692,89011,99883,112533,128061,144707,162752,182660,205191,230595,336092,400150]::numeric[]),
        (8, array[15456,16342,17360,18433,19565,20761,22022,23883,25725,28456,33611,35850,38241,41503,45202,49020,53221,57842,65132,72671,80831,90295,101318,114301,130073,146983,165310,185537,208430,234240,342310,408055]::numeric[])
), rates as (
    select step_rates.step, salary.grade::smallint as salary_grade, salary.amount
    from step_rates
    cross join lateral unnest(step_rates.salaries) with ordinality as salary(amount, grade)
)
insert into public.salary_schedule_rates (schedule_id, salary_grade, salary_step, monthly_salary)
select schedule.id, rates.salary_grade, rates.step, rates.amount
from schedule cross join rates
on conflict (schedule_id, salary_grade, salary_step) do update
set monthly_salary = excluded.monthly_salary;

-- Existing salaries and credit limits predate schedule metadata and may be
-- approved financial records. Leave them unchanged until an administrator
-- explicitly configures the employee's schedule, grade, and step.

commit;

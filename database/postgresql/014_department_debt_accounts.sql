begin;

create table if not exists public.departments (
    id bigserial primary key,
    code varchar(40) not null unique,
    name varchar(160) not null unique,
    head_user_id bigint references public.users(id) on update cascade on delete set null,
    is_active boolean not null default true,
    status_reason varchar(500),
    created_by bigint references public.users(id) on update cascade on delete set null,
    updated_by bigint references public.users(id) on update cascade on delete set null,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);

create table if not exists public.department_delegates (
    id bigserial primary key,
    department_id bigint not null references public.departments(id) on update cascade on delete cascade,
    user_id bigint not null references public.users(id) on update cascade on delete cascade,
    is_active boolean not null default true,
    effective_from date not null,
    effective_until date,
    created_by bigint references public.users(id) on update cascade on delete set null,
    created_at timestamp without time zone,
    updated_at timestamp without time zone,
    unique (department_id, user_id),
    check (effective_until is null or effective_until >= effective_from)
);
create index if not exists department_delegates_user_active_idx on public.department_delegates(user_id, is_active);

create table if not exists public.department_authorization_pins (
    user_id bigint primary key references public.users(id) on update cascade on delete cascade,
    pin_hash varchar(255) not null,
    failed_attempts integer not null default 0,
    window_started_at timestamp without time zone,
    locked_until timestamp without time zone,
    last_failed_at timestamp without time zone,
    last_success_at timestamp without time zone,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);

create table if not exists public.department_debt_periods (
    id bigserial primary key,
    department_id bigint not null references public.departments(id) on update cascade on delete cascade,
    period_month date not null,
    allocation_amount numeric(12,2) not null default 0 check (allocation_amount >= 0),
    used_amount numeric(12,2) not null default 0 check (used_amount >= 0),
    outstanding_amount numeric(12,2) not null default 0 check (outstanding_amount >= 0),
    status varchar(20) not null default 'open' check (status in ('open', 'closed', 'suspended')),
    notes varchar(500),
    configured_by bigint references public.users(id) on update cascade on delete set null,
    created_at timestamp without time zone,
    updated_at timestamp without time zone,
    unique (department_id, period_month),
    check (used_amount <= allocation_amount)
);
create index if not exists department_debt_period_month_status_idx on public.department_debt_periods(period_month, status);

create table if not exists public.department_debt_entries (
    id bigserial primary key,
    department_id bigint not null references public.departments(id) on update cascade on delete cascade,
    period_id bigint not null references public.department_debt_periods(id) on update cascade on delete cascade,
    entry_type varchar(40) not null,
    direction varchar(12) not null,
    amount numeric(12,2) not null,
    allocation_before numeric(12,2) not null default 0,
    allocation_after numeric(12,2) not null default 0,
    used_before numeric(12,2) not null default 0,
    used_after numeric(12,2) not null default 0,
    outstanding_before numeric(12,2) not null default 0,
    outstanding_after numeric(12,2) not null default 0,
    transaction_id bigint references public.transactions(id) on update cascade on delete set null,
    requester_user_id bigint references public.users(id) on update cascade on delete set null,
    requester_name varchar(160),
    approved_by_user_id bigint references public.users(id) on update cascade on delete set null,
    actor_id bigint references public.users(id) on update cascade on delete set null,
    reference_no varchar(120),
    remarks varchar(500),
    meta_json text,
    created_at timestamp without time zone
);
create index if not exists department_debt_entries_department_created_idx on public.department_debt_entries(department_id, created_at);
create index if not exists department_debt_entries_period_idx on public.department_debt_entries(period_id);
create index if not exists department_debt_entries_transaction_idx on public.department_debt_entries(transaction_id);

create table if not exists public.department_debt_transactions (
    transaction_id bigint primary key references public.transactions(id) on update cascade on delete cascade,
    department_id bigint not null references public.departments(id) on update cascade on delete cascade,
    period_id bigint not null references public.department_debt_periods(id) on update cascade on delete cascade,
    entry_id bigint not null references public.department_debt_entries(id) on update cascade on delete cascade,
    debt_amount numeric(12,2) not null check (debt_amount > 0),
    requester_user_id bigint references public.users(id) on update cascade on delete set null,
    requester_name varchar(160),
    approved_by_user_id bigint not null references public.users(id) on update cascade on delete restrict,
    created_at timestamp without time zone
);
create index if not exists department_debt_transactions_department_period_idx on public.department_debt_transactions(department_id, period_id);

commit;

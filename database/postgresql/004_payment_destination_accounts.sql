begin;

create table if not exists public.payment_destination_accounts (
    id bigserial primary key,
    store_id bigint not null references public.stores(id) on delete restrict,
    payment_method_id bigint not null references public.store_payment_methods(id) on delete restrict,
    account_name varchar(120) not null,
    account_number varchar(120) not null,
    image_url varchar(500),
    sort_order integer not null default 0,
    is_active boolean not null default true,
    created_at timestamp without time zone,
    updated_at timestamp without time zone,
    unique (store_id, payment_method_id, account_number)
);

alter table public.transaction_payments add column if not exists destination_account_id bigint references public.payment_destination_accounts(id) on delete set null;
alter table public.transaction_payments add column if not exists destination_account_name varchar(120);
alter table public.transaction_payments add column if not exists destination_account_number varchar(120);

create table if not exists public.store_day_payment_account_balances (
    id bigserial primary key,
    store_day_session_id bigint not null references public.store_day_sessions(id) on delete cascade,
    destination_account_id bigint not null references public.payment_destination_accounts(id) on delete restrict,
    account_name_snapshot varchar(120) not null,
    account_number_snapshot varchar(120) not null,
    opening_balance numeric(12,2) not null default 0,
    expected_balance numeric(12,2),
    counted_balance numeric(12,2),
    variance numeric(12,2),
    created_at timestamp without time zone,
    updated_at timestamp without time zone,
    unique (store_day_session_id, destination_account_id)
);

create index if not exists payment_destination_accounts_active_idx on public.payment_destination_accounts(store_id, payment_method_id, is_active);
commit;

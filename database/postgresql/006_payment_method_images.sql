begin;
alter table public.store_payment_methods add column if not exists image_url varchar(500);
commit;

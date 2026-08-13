begin;
update public.store_payment_methods
set is_active = false, updated_at = current_timestamp
where code in ('gcash', 'card', 'bank_transfer', 'advance_payment', 'other')
  and not exists (
      select 1 from public.payment_destination_accounts a
      where a.payment_method_id = store_payment_methods.id and a.is_active = true
  );
commit;

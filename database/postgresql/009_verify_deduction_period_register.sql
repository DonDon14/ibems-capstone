select column_name, data_type, is_nullable
from information_schema.columns
where table_schema = 'public'
  and table_name = 'deduction_batch_items'
  and column_name in (
      'opening_debt_snapshot', 'period_debits_snapshot', 'period_credits_snapshot',
      'salary_snapshot', 'deduction_choice', 'preparation_reason'
  )
order by column_name;

# IBEMS Entity Relationship Diagram

```mermaid
erDiagram
    USERS {
        int id PK
        varchar employee_id UK
        varchar name
        varchar email UK
        varchar password_hash
        varchar role
        varchar user_type
        varchar qr_token UK
        decimal base_salary
        tinyint is_active
        int failed_login_attempts
        datetime locked_until
        datetime created_at
        datetime updated_at
    }

    STORES {
        int id PK
        varchar store_name
        int officer_id FK
        tinyint is_active
        datetime created_at
        datetime updated_at
    }

    BALANCES {
        int user_id PK, FK
        decimal credit_limit
        decimal current_debt
        datetime updated_at
    }

    PRODUCTS {
        int id PK
        int store_id FK
        varchar sku
        varchar name
        varchar category
        varchar image_path
        decimal price
        int stock_qty
        tinyint is_active
        datetime created_at
        datetime updated_at
    }

    TRANSACTIONS {
        bigint id PK
        varchar client_txn_id UK
        int user_id FK
        varchar customer_type
        int store_id FK
        decimal amount
        varchar payment_method
        varchar other_payment_label
        varchar walkin_note
        varchar status
        varchar reference_no UK
        datetime created_at
        datetime synced_at
    }

    TRANSACTION_ITEMS {
        bigint id PK
        bigint transaction_id FK
        int product_id FK
        int qty
        decimal unit_price
        decimal line_total
    }

    INVENTORY_MOVEMENTS {
        bigint id PK
        int product_id FK
        int store_id FK
        varchar type
        int qty
        varchar reason
        bigint txn_id FK
        datetime created_at
    }

    SALARY_IMPORT_BATCHES {
        bigint id PK
        varchar filename
        int imported_by FK
        datetime imported_at
        int total_rows
        int valid_rows
        int invalid_rows
    }

    SALARY_IMPORT_ROWS {
        bigint id PK
        bigint batch_id FK
        varchar employee_id
        varchar name
        varchar email
        decimal monthly_salary
        varchar status
        varchar error_msg
    }

    SETTLEMENT_RUNS {
        bigint id PK
        varchar run_month
        int run_by FK
        datetime run_at
        int total_accounts
        decimal total_debt_before
        varchar notes
    }

    NOTIFICATIONS {
        bigint id PK
        int user_id FK
        bigint txn_id FK
        varchar channel
        varchar status
        datetime sent_at
        varchar error_msg
    }

    AUDIT_LOGS {
        bigint id PK
        int actor_id FK
        varchar action
        varchar entity
        varchar entity_id
        text payload_json
        datetime created_at
    }

    APP_SETTINGS {
        int id PK
        varchar setting_key UK
        text setting_value
        int updated_by FK
        datetime updated_at
    }

    USERS ||--o{ STORES : "officer_id"
    USERS ||--|| BALANCES : "user_id"
    STORES ||--o{ PRODUCTS : "store_id"
    USERS ||--o{ TRANSACTIONS : "user_id"
    STORES ||--o{ TRANSACTIONS : "store_id"
    TRANSACTIONS ||--o{ TRANSACTION_ITEMS : "transaction_id"
    PRODUCTS ||--o{ TRANSACTION_ITEMS : "product_id"
    PRODUCTS ||--o{ INVENTORY_MOVEMENTS : "product_id"
    STORES ||--o{ INVENTORY_MOVEMENTS : "store_id"
    TRANSACTIONS ||--o{ INVENTORY_MOVEMENTS : "txn_id"
    USERS ||--o{ SALARY_IMPORT_BATCHES : "imported_by"
    SALARY_IMPORT_BATCHES ||--o{ SALARY_IMPORT_ROWS : "batch_id"
    USERS ||--o{ SETTLEMENT_RUNS : "run_by"
    USERS ||--o{ NOTIFICATIONS : "user_id"
    TRANSACTIONS ||--o{ NOTIFICATIONS : "txn_id"
    USERS ||--o{ AUDIT_LOGS : "actor_id"
    USERS ||--o{ APP_SETTINGS : "updated_by"
```

## Notes

- `products` has a composite unique key on `store_id` and `sku`.
- `transactions.user_id` is nullable for walk-in customers.
- `stores.officer_id`, `transactions.user_id`, `salary_import_batches.imported_by`, `settlement_runs.run_by`, `notifications.user_id`, `notifications.txn_id`, `audit_logs.actor_id`, and `app_settings.updated_by` are nullable and use `SET NULL` on delete.
- `balances.user_id` is both the primary key and a foreign key to `users.id`, making it a one-to-one account balance record.

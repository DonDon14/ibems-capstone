CREATE TABLE IF NOT EXISTS user_purchase_cards (
    user_id BIGINT PRIMARY KEY REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    is_locked BOOLEAN NOT NULL DEFAULT TRUE,
    unlocked_until TIMESTAMP NULL,
    last_unlocked_at TIMESTAMP NULL,
    last_locked_at TIMESTAMP NULL,
    last_transaction_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

CREATE INDEX IF NOT EXISTS idx_user_purchase_cards_lock_state
    ON user_purchase_cards (is_locked, unlocked_until);

-- YouthSync: online payment gateway (PayMongo hosted checkout) - additive migration.
--
-- Non-destructive: only ADD COLUMN (all NULL-able), ADD KEY and CREATE TABLE.
-- Nothing is dropped, renamed, truncated or rewritten. Existing rows keep their values.
--
-- The API applies the same changes automatically and idempotently on the first request
-- (api/src/Infra/SchemaPatches.php::applyPaymentGateway), so running this file by hand is
-- optional. If you do run it, run it once; MariaDB 10.3+ accepts IF NOT EXISTS below.
--
-- Table roles:
--   payment_transactions  online checkout attempts (one row per PayMongo checkout session).
--                         Written only by the server. A row reaches status 'paid' only after
--                         a signature-verified webhook AND an independent server-side fetch of
--                         the checkout session (amount in centavos + currency PHP checked).
--   payments              the existing MANUAL ledger the System Administrator records.
--                         Unchanged. Online payments are NOT copied into it (no duplicates).
--   payment_webhook_events provider event ids already handled (idempotency for retries).

ALTER TABLE payment_transactions
    ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL DEFAULT NULL AFTER organization_id,
    ADD COLUMN IF NOT EXISTS amount_centavos INT UNSIGNED NULL DEFAULT NULL AFTER amount,
    ADD COLUMN IF NOT EXISTS requested_method VARCHAR(32) NULL DEFAULT NULL AFTER provider_reference,
    ADD COLUMN IF NOT EXISTS method VARCHAR(32) NULL DEFAULT NULL AFTER requested_method,
    ADD COLUMN IF NOT EXISTS provider_payment_id VARCHAR(64) NULL DEFAULT NULL AFTER method,
    ADD COLUMN IF NOT EXISTS provider_payment_intent_id VARCHAR(64) NULL DEFAULT NULL AFTER provider_payment_id,
    ADD COLUMN IF NOT EXISTS provider_status VARCHAR(32) NULL DEFAULT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS livemode TINYINT(1) NULL DEFAULT NULL AFTER provider_status,
    ADD COLUMN IF NOT EXISTS failure_code VARCHAR(64) NULL DEFAULT NULL AFTER livemode,
    ADD COLUMN IF NOT EXISTS failure_message VARCHAR(255) NULL DEFAULT NULL AFTER failure_code,
    ADD COLUMN IF NOT EXISTS activation_note VARCHAR(255) NULL DEFAULT NULL AFTER failure_message,
    ADD COLUMN IF NOT EXISTS provider_checked_at DATETIME NULL DEFAULT NULL AFTER verified_at,
    ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL DEFAULT NULL AFTER provider_checked_at,
    ADD COLUMN IF NOT EXISTS applied_at DATETIME NULL DEFAULT NULL AFTER paid_at;

ALTER TABLE payment_transactions
    ADD UNIQUE KEY IF NOT EXISTS uq_payment_provider_reference (provider, provider_reference),
    ADD UNIQUE KEY IF NOT EXISTS uq_payment_provider_payment (provider, provider_payment_id),
    ADD KEY IF NOT EXISTS idx_payment_intent (provider_payment_intent_id),
    ADD KEY IF NOT EXISTS idx_payment_created (created_at);

CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(32) NOT NULL,
    event_id VARCHAR(64) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    livemode TINYINT(1) NULL DEFAULT NULL,
    resource_id VARCHAR(64) NULL DEFAULT NULL,
    transaction_id INT UNSIGNED NULL DEFAULT NULL,
    result VARCHAR(32) NOT NULL DEFAULT 'received',
    attempts INT UNSIGNED NOT NULL DEFAULT 1,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_provider_event (provider, event_id),
    KEY idx_webhook_transaction (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

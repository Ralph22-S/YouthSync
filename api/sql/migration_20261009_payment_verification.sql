-- Secure payment verification (2026-10-09). ADDITIVE ONLY: nothing is dropped, renamed or rewritten.
-- Applied automatically by SchemaPatches::applyPaymentVerification on the first API request;
-- this file is for manual runs (MariaDB 10.x: ADD COLUMN IF NOT EXISTS). Existing rows are NOT backfilled:
-- old manual-ledger rows read as source='manual' and unverified.

ALTER TABLE payment_transactions
    ADD COLUMN IF NOT EXISTS verified_via VARCHAR(32) NULL DEFAULT NULL AFTER verified_at,
    ADD COLUMN IF NOT EXISTS refunded_centavos INT UNSIGNED NULL DEFAULT NULL AFTER applied_at,
    ADD COLUMN IF NOT EXISTS refunded_at DATETIME NULL DEFAULT NULL AFTER refunded_centavos,
    ADD COLUMN IF NOT EXISTS review_required TINYINT(1) NOT NULL DEFAULT 0 AFTER refunded_at,
    ADD COLUMN IF NOT EXISTS review_reason VARCHAR(255) NULL DEFAULT NULL AFTER review_required;

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS source VARCHAR(16) NOT NULL DEFAULT 'manual' AFTER organization_id,
    ADD COLUMN IF NOT EXISTS manual_verified_at DATETIME NULL DEFAULT NULL AFTER recorded_by,
    ADD COLUMN IF NOT EXISTS manual_verified_by INT UNSIGNED NULL DEFAULT NULL AFTER manual_verified_at,
    ADD COLUMN IF NOT EXISTS manual_verification_note VARCHAR(255) NULL DEFAULT NULL AFTER manual_verified_by;

<?php
declare(strict_types=1);

namespace YouthSync\Infra;

use PDO;

final class SchemaPatches
{
    private static bool $applied = false;

    public static function apply(PDO $pdo): void
    {
        if (self::$applied) {
            return;
        }
        self::$applied = true;

        if (!self::tableExists($pdo, 'sms_deliveries')) {
            $pdo->exec(
                'CREATE TABLE sms_deliveries (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    organization_id INT UNSIGNED NOT NULL,
                    notification_id INT UNSIGNED NULL DEFAULT NULL,
                    event_code VARCHAR(64) NOT NULL,
                    event_id INT UNSIGNED NOT NULL,
                    recipient VARCHAR(20) NOT NULL,
                    provider VARCHAR(32) NOT NULL DEFAULT \'semaphore\',
                    status VARCHAR(16) NOT NULL DEFAULT \'pending\',
                    provider_reference VARCHAR(128) NULL DEFAULT NULL,
                    error_message VARCHAR(255) NULL DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    sent_at DATETIME NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_sms_event_recipient (organization_id, event_code, event_id, recipient),
                    KEY idx_sms_status (status),
                    CONSTRAINT fk_sms_organization FOREIGN KEY (organization_id)
                        REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        if (!self::tableExists($pdo, 'email_deliveries')) {
            $pdo->exec(
                'CREATE TABLE email_deliveries (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    organization_id INT UNSIGNED NOT NULL,
                    notification_id INT UNSIGNED NULL DEFAULT NULL,
                    event_code VARCHAR(64) NOT NULL,
                    event_id INT UNSIGNED NOT NULL,
                    recipient_email VARCHAR(190) NOT NULL,
                    provider VARCHAR(32) NOT NULL DEFAULT \'brevo\',
                    status VARCHAR(16) NOT NULL DEFAULT \'pending\',
                    provider_reference VARCHAR(128) NULL DEFAULT NULL,
                    error_message VARCHAR(255) NULL DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    sent_at DATETIME NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_email_event_recipient (organization_id, event_code, event_id, recipient_email),
                    KEY idx_email_status (status),
                    CONSTRAINT fk_email_organization FOREIGN KEY (organization_id)
                        REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }


        // Older installs may still have DEFAULT 'resend'. Code inserts 'brevo' explicitly;
        // align the column default only - never rewrite existing row provider values.
        self::alignEmailProviderDefault($pdo);
        self::applyAdminPatches($pdo);
        self::applyPasswordResetTokens($pdo);
        self::applyYouthFiles($pdo);
        self::applySharedRequirements($pdo);
        self::applyApplicationRequirementFiles($pdo);
        self::applyRegistrationCodes($pdo);
        self::applyPaymentGateway($pdo);
        self::applyPaymentVerification($pdo);
        self::applyScheduledJobs($pdo);
        self::applyYouthSignups($pdo);
    }

    private static function applyYouthSignups(PDO $pdo): void
    {
        if (self::tableExists($pdo, 'youth_signups')) {
            return;
        }
        $pdo->exec(
            'CREATE TABLE youth_signups (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                token_hash CHAR(64) NOT NULL,
                organization_id INT UNSIGNED NOT NULL,
                email VARCHAR(190) NOT NULL,
                contact VARCHAR(20) NOT NULL,
                code_hash CHAR(64) NOT NULL,
                payload TEXT NOT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_youth_signups_token (token_hash),
                KEY idx_youth_signups_email (email, consumed_at),
                CONSTRAINT fk_youth_signups_organization FOREIGN KEY (organization_id)
                    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Job definitions for the CLI scheduler and POST /cron/run.
     * Existing rows are not rewritten. New installs receive the table only.
     */
    private static function applyScheduledJobs(PDO $pdo): void
    {
        if (self::tableExists($pdo, 'scheduled_jobs')) {
            return;
        }
        $pdo->exec(
            'CREATE TABLE scheduled_jobs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(64) NOT NULL,
                name VARCHAR(160) NOT NULL,
                interval_minutes INT UNSIGNED NOT NULL DEFAULT 1440,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                next_run_at DATETIME NOT NULL,
                last_run_at DATETIME NULL DEFAULT NULL,
                last_status VARCHAR(16) NULL DEFAULT NULL,
                last_message VARCHAR(255) NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_scheduled_jobs_code (code),
                KEY idx_scheduled_jobs_due (enabled, next_run_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Secure payment verification. Additive only (nullable columns or NOT NULL with a
     * default): how a provider verification happened, refund tracking and an admin-review
     * flag on payment_transactions; source + manual-verification fields on the manual
     * ledger. Existing rows are not backfilled: old manual rows read as source=manual,
     * unverified. Same statements: api/sql/migration_20261009_payment_verification.sql.
     */
    private static function applyPaymentVerification(PDO $pdo): void
    {
        if (self::tableExists($pdo, 'payment_transactions') && self::columnExists($pdo, 'payment_transactions', 'applied_at')) {
            $columns = [
                'verified_via' => 'VARCHAR(32) NULL DEFAULT NULL AFTER verified_at',
                'refunded_centavos' => 'INT UNSIGNED NULL DEFAULT NULL AFTER applied_at',
                'refunded_at' => 'DATETIME NULL DEFAULT NULL AFTER refunded_centavos',
                'review_required' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER refunded_at',
                'review_reason' => 'VARCHAR(255) NULL DEFAULT NULL AFTER review_required',
            ];
            foreach ($columns as $column => $definition) {
                if (!self::columnExists($pdo, 'payment_transactions', $column)) {
                    $pdo->exec("ALTER TABLE payment_transactions ADD COLUMN {$column} {$definition}");
                }
            }
        }
        if (self::tableExists($pdo, 'payments')) {
            $columns = [
                'source' => "VARCHAR(16) NOT NULL DEFAULT 'manual' AFTER organization_id",
                'manual_verified_at' => 'DATETIME NULL DEFAULT NULL AFTER recorded_by',
                'manual_verified_by' => 'INT UNSIGNED NULL DEFAULT NULL AFTER manual_verified_at',
                'manual_verification_note' => 'VARCHAR(255) NULL DEFAULT NULL AFTER manual_verified_by',
            ];
            foreach ($columns as $column => $definition) {
                if (!self::columnExists($pdo, 'payments', $column)) {
                    $pdo->exec("ALTER TABLE payments ADD COLUMN {$column} {$definition}");
                }
            }
        }
    }

    /**
     * Online payments (PayMongo hosted checkout). Additive only: nullable
     * columns and unique keys on payment_transactions (which had no PHP
     * writer before this), plus a webhook event ledger keyed by the provider
     * event id. Nothing is dropped, renamed or rewritten. The same statements
     * are in api/sql/migration_20261009_payment_gateway.sql for manual runs.
     */
    private static function applyPaymentGateway(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'payment_transactions')) {
            return;
        }
        $columns = [
            'user_id' => 'INT UNSIGNED NULL DEFAULT NULL AFTER organization_id',
            'amount_centavos' => 'INT UNSIGNED NULL DEFAULT NULL AFTER amount',
            'requested_method' => 'VARCHAR(32) NULL DEFAULT NULL AFTER provider_reference',
            'method' => 'VARCHAR(32) NULL DEFAULT NULL AFTER requested_method',
            'provider_payment_id' => 'VARCHAR(64) NULL DEFAULT NULL AFTER method',
            'provider_payment_intent_id' => 'VARCHAR(64) NULL DEFAULT NULL AFTER provider_payment_id',
            'provider_status' => 'VARCHAR(32) NULL DEFAULT NULL AFTER status',
            'livemode' => 'TINYINT(1) NULL DEFAULT NULL AFTER provider_status',
            'failure_code' => 'VARCHAR(64) NULL DEFAULT NULL AFTER livemode',
            'failure_message' => 'VARCHAR(255) NULL DEFAULT NULL AFTER failure_code',
            'activation_note' => 'VARCHAR(255) NULL DEFAULT NULL AFTER failure_message',
            'provider_checked_at' => 'DATETIME NULL DEFAULT NULL AFTER verified_at',
            'paid_at' => 'DATETIME NULL DEFAULT NULL AFTER provider_checked_at',
            'applied_at' => 'DATETIME NULL DEFAULT NULL AFTER paid_at',
        ];
        foreach ($columns as $column => $definition) {
            if (!self::columnExists($pdo, 'payment_transactions', $column)) {
                $pdo->exec("ALTER TABLE payment_transactions ADD COLUMN {$column} {$definition}");
            }
        }
        $indexes = [
            'uq_payment_provider_reference' => 'UNIQUE KEY uq_payment_provider_reference (provider, provider_reference)',
            'uq_payment_provider_payment' => 'UNIQUE KEY uq_payment_provider_payment (provider, provider_payment_id)',
            'idx_payment_intent' => 'KEY idx_payment_intent (provider_payment_intent_id)',
            'idx_payment_created' => 'KEY idx_payment_created (created_at)',
        ];
        foreach ($indexes as $name => $definition) {
            if (!self::indexExists($pdo, 'payment_transactions', $name)) {
                $pdo->exec("ALTER TABLE payment_transactions ADD {$definition}");
            }
        }

        if (!self::tableExists($pdo, 'payment_webhook_events')) {
            $pdo->exec(
                'CREATE TABLE payment_webhook_events (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    provider VARCHAR(32) NOT NULL,
                    event_id VARCHAR(64) NOT NULL,
                    event_type VARCHAR(64) NOT NULL,
                    livemode TINYINT(1) NULL DEFAULT NULL,
                    resource_id VARCHAR(64) NULL DEFAULT NULL,
                    transaction_id INT UNSIGNED NULL DEFAULT NULL,
                    result VARCHAR(32) NOT NULL DEFAULT \'received\',
                    attempts INT UNSIGNED NOT NULL DEFAULT 1,
                    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    processed_at DATETIME NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_webhook_provider_event (provider, event_id),
                    KEY idx_webhook_transaction (transaction_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
    }

    /**
     * Stable poster code for youth self-registration. Separate from attendance session codes.
     */
    private static function applyRegistrationCodes(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'programs')) {
            return;
        }
        if (!self::columnExists($pdo, 'programs', 'registration_code')) {
            $pdo->exec(
                'ALTER TABLE programs ADD COLUMN registration_code VARCHAR(16) NULL DEFAULT NULL AFTER name'
            );
        }
        $missing = $pdo->query(
            "SELECT id FROM programs WHERE registration_code IS NULL OR registration_code = ''"
        )->fetchAll(PDO::FETCH_COLUMN);
        $taken = $pdo->prepare('SELECT id FROM programs WHERE registration_code = :code LIMIT 1');
        $assign = $pdo->prepare(
            "UPDATE programs SET registration_code = :code WHERE id = :id AND (registration_code IS NULL OR registration_code = '')"
        );
        foreach ($missing as $id) {
            for ($attempt = 0; $attempt < 8; $attempt++) {
                $code = 'REG-' . strtoupper(bin2hex(random_bytes(4)));
                $taken->execute(['code' => $code]);
                if ($taken->fetch() !== false) {
                    continue;
                }
                $assign->execute(['code' => $code, 'id' => (int) $id]);
                break;
            }
        }
        if (!self::indexExists($pdo, 'programs', 'uq_programs_registration_code')) {
            $pdo->exec(
                'ALTER TABLE programs ADD UNIQUE KEY uq_programs_registration_code (registration_code)'
            );
        }
    }

    /**
     * application_submissions already stores file metadata. Older databases
     * receive those columns only when they are missing. File bytes stay on disk.
     */
    private static function applyApplicationRequirementFiles(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'application_submissions')) {
            return;
        }
        $columns = [
            'file_name' => "VARCHAR(190) NOT NULL DEFAULT ''",
            'file_type' => "VARCHAR(80) NOT NULL DEFAULT ''",
            'file_size' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'file_ref' => "VARCHAR(64) NOT NULL DEFAULT ''",
        ];
        foreach ($columns as $name => $definition) {
            if (!self::columnExists($pdo, 'application_submissions', $name)) {
                $pdo->exec("ALTER TABLE application_submissions ADD COLUMN {$name} {$definition}");
            }
        }
    }

    /**
     * Lets one requirement row belong to an assistance program or a program/event.
     * Existing assistance rows keep assistance_id. New program and event rows use programs.id.
     */
    private static function applySharedRequirements(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'assistance_requirements') || !self::tableExists($pdo, 'programs')) {
            return;
        }
        if (!self::columnExists($pdo, 'assistance_requirements', 'program_id')) {
            $pdo->exec(
                'ALTER TABLE assistance_requirements
                 ADD COLUMN program_id INT UNSIGNED NULL DEFAULT NULL AFTER assistance_id'
            );
        }
        if (!self::indexExists($pdo, 'assistance_requirements', 'idx_assist_req_org_activity')) {
            $pdo->exec(
                'ALTER TABLE assistance_requirements
                 ADD KEY idx_assist_req_org_activity (organization_id, program_id)'
            );
        }
        if (!self::constraintExists($pdo, 'assistance_requirements', 'fk_assist_req_activity')) {
            $pdo->exec(
                'ALTER TABLE assistance_requirements
                 ADD CONSTRAINT fk_assist_req_activity
                 FOREIGN KEY (program_id) REFERENCES programs (id)
                 ON UPDATE CASCADE ON DELETE RESTRICT'
            );
        }
        if (self::columnExists($pdo, 'assistance_requirements', 'assistance_id')
            && !self::columnNullable($pdo, 'assistance_requirements', 'assistance_id')) {
            $pdo->exec(
                'ALTER TABLE assistance_requirements
                 MODIFY assistance_id INT UNSIGNED NULL DEFAULT NULL'
            );
        }

        if (!self::tableExists($pdo, 'applications')) {
            return;
        }
        if (!self::columnExists($pdo, 'applications', 'program_id')) {
            $pdo->exec(
                'ALTER TABLE applications
                 ADD COLUMN program_id INT UNSIGNED NULL DEFAULT NULL AFTER assistance_id'
            );
        }
        if (!self::indexExists($pdo, 'applications', 'idx_applications_org_program')) {
            $pdo->exec(
                'ALTER TABLE applications
                 ADD KEY idx_applications_org_program (organization_id, program_id)'
            );
        }
        if (!self::indexExists($pdo, 'applications', 'uq_applications_org_program_youth')) {
            $pdo->exec(
                'ALTER TABLE applications
                 ADD UNIQUE KEY uq_applications_org_program_youth (organization_id, program_id, youth_id)'
            );
        }
        if (!self::constraintExists($pdo, 'applications', 'fk_applications_program')) {
            $pdo->exec(
                'ALTER TABLE applications
                 ADD CONSTRAINT fk_applications_program
                 FOREIGN KEY (program_id) REFERENCES programs (id)
                 ON UPDATE CASCADE ON DELETE RESTRICT'
            );
        }
        if (self::columnExists($pdo, 'applications', 'assistance_id')
            && !self::columnNullable($pdo, 'applications', 'assistance_id')) {
            $pdo->exec(
                'ALTER TABLE applications
                 MODIFY assistance_id INT UNSIGNED NULL DEFAULT NULL'
            );
        }
    }

    /**
     * File metadata for a youth profile photo or SK supporting document.
     * Bytes stay on disk. This never drops or rewrites existing youth rows.
     */
    private static function applyYouthFiles(PDO $pdo): void
    {
        if (self::tableExists($pdo, 'youth_files')) {
            return;
        }
        $pdo->exec(
            'CREATE TABLE youth_files (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                organization_id INT UNSIGNED NOT NULL,
                youth_id INT UNSIGNED NOT NULL,
                kind VARCHAR(16) NOT NULL,
                storage_name VARCHAR(64) NOT NULL,
                original_name VARCHAR(160) NOT NULL,
                mime VARCHAR(80) NOT NULL,
                size_bytes INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_youth_file_kind (youth_id, kind),
                UNIQUE KEY uq_youth_file_storage (storage_name),
                KEY idx_youth_files_org (organization_id),
                CONSTRAINT fk_youth_files_organization FOREIGN KEY (organization_id)
                    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT fk_youth_files_youth FOREIGN KEY (youth_id)
                    REFERENCES youth (id) ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Additive System Administrator schema. Never drops or truncates.
     * Failures here must not take down SK Official requests.
     */
    private static function applyPasswordResetTokens(PDO $pdo): void
    {
        if (self::tableExists($pdo, 'password_reset_tokens')) {
            return;
        }
        $pdo->exec(
            'CREATE TABLE password_reset_tokens (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_password_reset_token_hash (token_hash),
                KEY idx_password_reset_user (user_id, used_at),
                CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private static function applyAdminPatches(PDO $pdo): void
    {
        try {
            self::applyAdminPatchesInner($pdo);
        } catch (\Throwable) {
            return;
        }
    }

    private static function applyAdminPatchesInner(PDO $pdo): void
    {
        $statusType = self::columnType($pdo, 'organizations', 'status');
        if (is_string($statusType) && !str_contains(strtolower($statusType), 'suspended')) {
            $pdo->exec(
                "ALTER TABLE organizations MODIFY status
                 ENUM('pending', 'active', 'suspended', 'rejected', 'inactive')
                 NOT NULL DEFAULT 'pending'"
            );
        }

        $columns = [
            'status_note' => "ALTER TABLE organizations ADD COLUMN status_note VARCHAR(500) NOT NULL DEFAULT '' AFTER status",
            'approved_at' => 'ALTER TABLE organizations ADD COLUMN approved_at DATETIME NULL DEFAULT NULL AFTER status_note',
            'started_on' => 'ALTER TABLE organizations ADD COLUMN started_on DATE NULL DEFAULT NULL AFTER cycle',
        ];
        foreach ($columns as $column => $sql) {
            if (!self::columnExists($pdo, 'organizations', $column)) {
                $pdo->exec($sql);
            }
        }

        if (!self::tableExists($pdo, 'activity_logs')) {
            $pdo->exec(
                'CREATE TABLE activity_logs (
                  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  organization_id INT UNSIGNED NULL DEFAULT NULL,
                  user_id INT UNSIGNED NULL DEFAULT NULL,
                  youth_id INT UNSIGNED NULL DEFAULT NULL,
                  actor_name VARCHAR(160) NOT NULL DEFAULT \'System\',
                  actor_role VARCHAR(32) NOT NULL DEFAULT \'system\',
                  action VARCHAR(32) NOT NULL,
                  category VARCHAR(32) NOT NULL DEFAULT \'general\',
                  description VARCHAR(500) NOT NULL,
                  entity_type VARCHAR(64) NOT NULL DEFAULT \'\',
                  entity_id INT UNSIGNED NULL DEFAULT NULL,
                  ip VARCHAR(45) NOT NULL DEFAULT \'\',
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  KEY idx_activity_logs_org_created (organization_id, created_at),
                  KEY idx_activity_logs_action (action),
                  KEY idx_activity_logs_category (category),
                  CONSTRAINT fk_activity_logs_organization FOREIGN KEY (organization_id)
                    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE CASCADE,
                  CONSTRAINT fk_activity_logs_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        if (!self::tableExists($pdo, 'payments')) {
            $pdo->exec(
                'CREATE TABLE payments (
                  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  organization_id INT UNSIGNED NOT NULL,
                  reference VARCHAR(64) NOT NULL,
                  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                  plan_code ENUM(\'free\', \'basic\', \'premium\') NOT NULL DEFAULT \'basic\',
                  cycle VARCHAR(32) NOT NULL DEFAULT \'monthly\',
                  method VARCHAR(32) NOT NULL DEFAULT \'gcash\',
                  status ENUM(\'pending\', \'paid\', \'failed\', \'refunded\') NOT NULL DEFAULT \'pending\',
                  paid_at DATETIME NULL DEFAULT NULL,
                  note VARCHAR(255) NOT NULL DEFAULT \'\',
                  recorded_by INT UNSIGNED NULL DEFAULT NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  UNIQUE KEY uq_payments_reference (reference),
                  KEY idx_payments_org_created (organization_id, created_at),
                  KEY idx_payments_status (status),
                  CONSTRAINT fk_payments_organization FOREIGN KEY (organization_id)
                    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE CASCADE,
                  CONSTRAINT fk_payments_recorded_by FOREIGN KEY (recorded_by)
                    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'SYSTEM_ADMIN' LIMIT 1")->fetchColumn();
        if ($roleId < 1) {
            try {
                $pdo->prepare(
                    "INSERT INTO roles (code, name) VALUES ('SYSTEM_ADMIN', 'System Administrator')"
                )->execute();
            } catch (\Throwable) {
                return;
            }
            $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'SYSTEM_ADMIN' LIMIT 1")->fetchColumn();
        }
        if ($roleId < 1) {
            return;
        }

        $adminEmail = strtolower(function_exists('youthsync_env')
            ? youthsync_env('ADMIN_EMAIL', 'admin@skmms.test')
            : 'admin@skmms.test');
        if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $exists = $pdo->prepare('SELECT u.id, r.code AS role_code FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.email = :email LIMIT 1');
        $exists->execute(['email' => $adminEmail]);
        $existing = $exists->fetch();
        if ($existing !== false) {
            return;
        }

        $production = function_exists('youthsync_env')
            && strtolower(youthsync_env('APP_ENV', 'development')) === 'production';
        $adminPassword = function_exists('youthsync_env') ? youthsync_env('ADMIN_PASSWORD', '') : '';
        if ($adminPassword === '') {
            if ($production) {
                return;
            }
            $adminPassword = 'YouthSync1!';
        }

        $pdo->prepare(
            'INSERT INTO users (email, password_hash, first_name, last_name, role_id, status, must_change_password)
             VALUES (:email, :hash, :fn, :ln, :role, :status, 0)'
        )->execute([
            'email' => $adminEmail,
            'hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
            'fn' => 'System',
            'ln' => 'Administrator',
            'role' => $roleId,
            'status' => 'active',
        ]);
    }


    /**
     * Align email_deliveries.provider DEFAULT to brevo without touching row values.
     */
    private static function alignEmailProviderDefault(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'email_deliveries') || !self::columnExists($pdo, 'email_deliveries', 'provider')) {
            return;
        }
        $stmt = $pdo->prepare(
            'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $stmt->execute(['table' => 'email_deliveries', 'column' => 'provider']);
        $default = $stmt->fetchColumn();
        if (!is_string($default) || strtolower(trim($default, "'\"")) === 'brevo') {
            return;
        }
        $pdo->exec(
            "ALTER TABLE email_deliveries
             MODIFY provider VARCHAR(32) NOT NULL DEFAULT 'brevo'"
        );
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function columnNullable(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);
        return strtoupper((string) $stmt->fetchColumn()) === 'YES';
    }

    private static function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index_name'
        );
        $stmt->execute(['table' => $table, 'index_name' => $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function constraintExists(PDO $pdo, string $table, string $constraint): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = :constraint_name'
        );
        $stmt->execute(['table' => $table, 'constraint_name' => $constraint]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function columnType(PDO $pdo, string $table, string $column): ?string
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);
        $type = $stmt->fetchColumn();
        return is_string($type) ? $type : null;
    }
}

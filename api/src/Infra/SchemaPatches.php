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
}

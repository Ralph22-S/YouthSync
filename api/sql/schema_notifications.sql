-- YouthSync Phase 7 — Notifications (SK Official inbox)
-- Non-destructive. Does not drop Phase 1–6 tables or data.
-- Outbox SMS/email and activity_logs are not part of this phase.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL DEFAULT NULL,
  youth_id INT UNSIGNED NULL DEFAULT NULL,
  type VARCHAR(32) NOT NULL DEFAULT 'system',
  title VARCHAR(190) NOT NULL,
  message VARCHAR(2000) NOT NULL DEFAULT '',
  link VARCHAR(255) NOT NULL DEFAULT '',
  related VARCHAR(190) NOT NULL DEFAULT '',
  related_entity_type VARCHAR(64) NOT NULL DEFAULT '',
  related_entity_id INT UNSIGNED NULL DEFAULT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_org_inbox (organization_id, youth_id, user_id, is_read),
  KEY idx_notifications_org_created (organization_id, created_at),
  CONSTRAINT fk_notifications_organization FOREIGN KEY (organization_id)
    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_notifications_youth FOREIGN KEY (youth_id)
    REFERENCES youth (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

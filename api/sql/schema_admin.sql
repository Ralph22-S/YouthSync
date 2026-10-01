-- YouthSync — System Administrator (organization management)
-- Non-destructive. Creates only the tables the admin side needs and never
-- drops or empties anything that earlier phases created.
--
-- organizations.status is widened and organizations.status_note / started_on /
-- approved_at are added by import_admin.php, because altering an existing
-- column has to be guarded rather than replayed blindly.

SET NAMES utf8mb4;

-- Every recorded action inside an organization: sign-ins, youth records added,
-- activities published, assistance awarded, reports generated.
-- organization_id is NULL for deployment-wide events such as an admin sign-in.
CREATE TABLE IF NOT EXISTS activity_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NULL DEFAULT NULL,
  user_id INT UNSIGNED NULL DEFAULT NULL,
  youth_id INT UNSIGNED NULL DEFAULT NULL,
  actor_name VARCHAR(160) NOT NULL DEFAULT 'System',
  actor_role VARCHAR(32) NOT NULL DEFAULT 'system',
  action VARCHAR(32) NOT NULL,
  category VARCHAR(32) NOT NULL DEFAULT 'general',
  description VARCHAR(500) NOT NULL,
  entity_type VARCHAR(64) NOT NULL DEFAULT '',
  entity_id INT UNSIGNED NULL DEFAULT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activity_logs_org_created (organization_id, created_at),
  KEY idx_activity_logs_action (action),
  KEY idx_activity_logs_category (category),
  CONSTRAINT fk_activity_logs_organization FOREIGN KEY (organization_id)
    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_activity_logs_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subscription payments. `reference` is the transaction number shown in the UI
-- and is unique across the deployment so it can be searched on directly.
CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  reference VARCHAR(64) NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  plan_code ENUM('free', 'basic', 'premium') NOT NULL DEFAULT 'basic',
  cycle VARCHAR(32) NOT NULL DEFAULT 'monthly',
  method VARCHAR(32) NOT NULL DEFAULT 'gcash',
  status ENUM('pending', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
  paid_at DATETIME NULL DEFAULT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

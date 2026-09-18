-- YouthSync Phase 3 — Programs & Events
-- Non-destructive. Does not drop Phase 1/2 tables or data.
-- Programs and events are one activity row distinguished by `kind`.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS programs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  kind VARCHAR(16) NOT NULL DEFAULT 'program',
  name VARCHAR(160) NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT '',
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  scheduled_on DATE NOT NULL,
  starts_at VARCHAR(8) NOT NULL DEFAULT '08:00',
  ends_at VARCHAR(8) NOT NULL DEFAULT '15:00',
  location VARCHAR(190) NOT NULL DEFAULT '',
  description TEXT NULL,
  max_participants INT UNSIGNED NULL DEFAULT NULL,
  registration_deadline DATE NULL DEFAULT NULL,
  tag_interests JSON NULL,
  tag_skills JSON NULL,
  tag_activities JSON NULL,
  requires_studying TINYINT(1) NOT NULL DEFAULT 0,
  min_age TINYINT UNSIGNED NULL DEFAULT NULL,
  max_age TINYINT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_programs_org_kind (organization_id, kind),
  KEY idx_programs_org_status (organization_id, status),
  KEY idx_programs_org_scheduled (organization_id, scheduled_on),
  KEY idx_programs_org_name_date (organization_id, name, scheduled_on),
  CONSTRAINT fk_programs_organization FOREIGN KEY (organization_id)
    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

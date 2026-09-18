-- YouthSync Phase 6 — Applications (SK Official)
-- Non-destructive. Does not drop Phase 1–5 tables or data.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS applications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  assistance_id INT UNSIGNED NOT NULL,
  youth_id INT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  remarks VARCHAR(1000) NOT NULL DEFAULT '',
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL DEFAULT NULL,
  reviewed_by INT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_applications_org_assistance_youth (organization_id, assistance_id, youth_id),
  KEY idx_applications_org_status (organization_id, status),
  KEY idx_applications_org_assistance (organization_id, assistance_id),
  KEY idx_applications_org_youth (organization_id, youth_id),
  CONSTRAINT fk_applications_organization FOREIGN KEY (organization_id)
    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_applications_assistance FOREIGN KEY (assistance_id)
    REFERENCES assistance_programs (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_applications_youth FOREIGN KEY (youth_id)
    REFERENCES youth (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_applications_reviewer FOREIGN KEY (reviewed_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_submissions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  application_id INT UNSIGNED NOT NULL,
  requirement_id INT UNSIGNED NOT NULL,
  file_name VARCHAR(190) NOT NULL DEFAULT '',
  file_type VARCHAR(80) NOT NULL DEFAULT '',
  file_size INT UNSIGNED NOT NULL DEFAULT 0,
  file_ref VARCHAR(64) NOT NULL DEFAULT '',
  status VARCHAR(32) NOT NULL DEFAULT 'missing',
  remarks VARCHAR(500) NOT NULL DEFAULT '',
  reviewed_at DATETIME NULL DEFAULT NULL,
  reviewed_by INT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_submission_application_requirement (application_id, requirement_id),
  KEY idx_submissions_org_application (organization_id, application_id),
  CONSTRAINT fk_submissions_organization FOREIGN KEY (organization_id)
    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_submissions_application FOREIGN KEY (application_id)
    REFERENCES applications (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_submissions_requirement FOREIGN KEY (requirement_id)
    REFERENCES assistance_requirements (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_submissions_reviewer FOREIGN KEY (reviewed_by)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

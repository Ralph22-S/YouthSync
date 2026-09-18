-- YouthSync Phase 5 — Assistance (SK Official)
-- Non-destructive. Does not drop Phase 1–4 tables or data.
-- System assistance types are seeded by import_phase5.php.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS assistance_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NULL DEFAULT NULL,
  name VARCHAR(160) NOT NULL,
  category VARCHAR(32) NOT NULL DEFAULT 'other',
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  requirements_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_assistance_types_org (organization_id, is_system),
  CONSTRAINT fk_assistance_types_organization FOREIGN KEY (organization_id)
    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assistance_programs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  type_id INT UNSIGNED NULL DEFAULT NULL,
  name VARCHAR(160) NOT NULL,
  category VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  slots INT UNSIGNED NULL DEFAULT NULL,
  amount DECIMAL(12,2) NULL DEFAULT NULL,
  description TEXT NULL,
  requirements_text TEXT NULL,
  deadline DATE NULL DEFAULT NULL,
  requires_studying TINYINT(1) NOT NULL DEFAULT 0,
  tag_interests JSON NULL,
  tag_skills JSON NULL,
  tag_activities JSON NULL,
  min_age TINYINT UNSIGNED NULL DEFAULT NULL,
  max_age TINYINT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_assistance_org_status (organization_id, status),
  KEY idx_assistance_org_category (organization_id, category),
  KEY idx_assistance_org_name (organization_id, name),
  CONSTRAINT fk_assistance_organization FOREIGN KEY (organization_id)
    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_assistance_type FOREIGN KEY (type_id)
    REFERENCES assistance_types (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assistance_requirements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  assistance_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  required TINYINT(1) NOT NULL DEFAULT 1,
  accepts VARCHAR(64) NOT NULL DEFAULT 'image/*,application/pdf',
  from_type TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_assist_req_org_program (organization_id, assistance_id),
  CONSTRAINT fk_assist_req_organization FOREIGN KEY (organization_id)
    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_assist_req_program FOREIGN KEY (assistance_id)
    REFERENCES assistance_programs (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS beneficiaries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  assistance_id INT UNSIGNED NOT NULL,
  youth_id INT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'applied',
  awarded_on DATE NULL DEFAULT NULL,
  remarks VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_beneficiary_org_program_youth (organization_id, assistance_id, youth_id),
  KEY idx_beneficiaries_org_program (organization_id, assistance_id),
  KEY idx_beneficiaries_org_youth (organization_id, youth_id),
  CONSTRAINT fk_beneficiaries_organization FOREIGN KEY (organization_id)
    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_beneficiaries_assistance FOREIGN KEY (assistance_id)
    REFERENCES assistance_programs (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_beneficiaries_youth FOREIGN KEY (youth_id)
    REFERENCES youth (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

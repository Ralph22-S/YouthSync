-- YouthSync Phase 4 — Attendance & QR (SK Official)
-- Non-destructive. Does not drop Phase 1–3 tables or data.
-- organizations.qr_uses is added by import_attendance.php when missing.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_qr_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  program_id INT UNSIGNED NOT NULL,
  public_code VARCHAR(32) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  created_by INT UNSIGNED NULL DEFAULT NULL,
  expires_at DATETIME NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_qr_public_code (public_code),
  UNIQUE KEY uq_attendance_qr_token_hash (token_hash),
  KEY idx_attendance_qr_org_program (organization_id, program_id, status),
  KEY idx_attendance_qr_expires (expires_at),
  CONSTRAINT fk_attendance_qr_organization FOREIGN KEY (organization_id)
    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_attendance_qr_program FOREIGN KEY (program_id)
    REFERENCES programs (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  program_id INT UNSIGNED NOT NULL,
  youth_id INT UNSIGNED NOT NULL,
  session_id INT UNSIGNED NULL DEFAULT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  source VARCHAR(16) NOT NULL DEFAULT 'qr',
  scanned_at DATETIME NULL DEFAULT NULL,
  confirmed_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_org_program_youth (organization_id, program_id, youth_id),
  KEY idx_attendance_org_program (organization_id, program_id),
  KEY idx_attendance_org_youth (organization_id, youth_id),
  KEY idx_attendance_status (organization_id, status),
  CONSTRAINT fk_attendance_organization FOREIGN KEY (organization_id)
    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_attendance_program FOREIGN KEY (program_id)
    REFERENCES programs (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_attendance_youth FOREIGN KEY (youth_id)
    REFERENCES youth (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_attendance_session FOREIGN KEY (session_id)
    REFERENCES attendance_qr_tokens (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

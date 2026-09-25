-- YouthSync Phase 1 schema
-- Tables: roles, users, organizations, organization_users
-- Engine: InnoDB, charset utf8mb4

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS roles (
  id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(32) NOT NULL,
  name VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organizations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  barangay VARCHAR(120) NOT NULL,
  municipality VARCHAR(120) NOT NULL,
  province VARCHAR(120) NOT NULL DEFAULT 'Laguna',
  chairperson VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL,
  contact VARCHAR(20) NOT NULL,
  status ENUM('active', 'pending', 'inactive') NOT NULL DEFAULT 'pending',
  plan ENUM('free', 'basic', 'premium') NOT NULL DEFAULT 'free',
  sub_status VARCHAR(32) NOT NULL DEFAULT 'free',
  cycle VARCHAR(32) NOT NULL DEFAULT 'none',
  expires_at DATE NULL DEFAULT NULL,
  youth_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_organizations_email (email),
  KEY idx_organizations_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  role_id TINYINT UNSIGNED NOT NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_id (role_id),
  KEY idx_users_status (status),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organization_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  organization_id INT UNSIGNED NOT NULL,
  is_owner TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_organization_users_user_org (user_id, organization_id),
  KEY idx_organization_users_org (organization_id),
  KEY idx_organization_users_user_status (user_id, status),
  CONSTRAINT fk_organization_users_user FOREIGN KEY (user_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_organization_users_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS youth (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  code VARCHAR(20) NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  middle_name VARCHAR(80) NOT NULL DEFAULT '',
  last_name VARCHAR(80) NOT NULL,
  birth_date DATE NOT NULL,
  gender VARCHAR(32) NOT NULL DEFAULT 'Prefer not to say',
  address VARCHAR(255) NOT NULL,
  contact VARCHAR(20) NOT NULL DEFAULT '',
  email VARCHAR(190) NULL DEFAULT NULL,
  civil_status VARCHAR(32) NOT NULL DEFAULT 'Single',
  education_status VARCHAR(64) NOT NULL DEFAULT 'Not Currently Studying',
  education VARCHAR(64) NOT NULL DEFAULT 'Senior High School',
  school VARCHAR(160) NOT NULL DEFAULT '',
  course VARCHAR(160) NOT NULL DEFAULT '',
  year_level VARCHAR(64) NOT NULL DEFAULT '',
  strand VARCHAR(64) NOT NULL DEFAULT '',
  studying TINYINT(1) NOT NULL DEFAULT 0,
  employment VARCHAR(32) NOT NULL DEFAULT 'Unemployed',
  occupation VARCHAR(120) NOT NULL DEFAULT '',
  guardian_name VARCHAR(160) NOT NULL DEFAULT '',
  guardian_employment VARCHAR(32) NULL DEFAULT NULL,
  guardian_occupation VARCHAR(120) NOT NULL DEFAULT '',
  family_income DECIMAL(12,2) NOT NULL DEFAULT 0,
  family_members INT UNSIGNED NOT NULL DEFAULT 0,
  skills JSON NULL,
  interests JSON NULL,
  preferred_activities JSON NULL,
  previous_scholarship TINYINT(1) NOT NULL DEFAULT 0,
  previous_assistance TINYINT(1) NOT NULL DEFAULT 0,
  previous_participation TINYINT(1) NOT NULL DEFAULT 0,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  self_registered TINYINT(1) NOT NULL DEFAULT 0,
  priority_level VARCHAR(16) NOT NULL DEFAULT 'Medium',
  priority_score INT NOT NULL DEFAULT 0,
  priority_reasons JSON NULL,
  added_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_youth_org_code (organization_id, code),
  KEY idx_youth_org_archived (organization_id, archived),
  KEY idx_youth_org_created (organization_id, created_at),
  KEY idx_youth_org_name_birth (organization_id, last_name, first_name, birth_date),
  KEY idx_youth_priority (organization_id, priority_level),
  KEY idx_youth_employment (organization_id, employment),
  CONSTRAINT fk_youth_organization FOREIGN KEY (organization_id)
    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS sms_deliveries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id INT UNSIGNED NOT NULL,
  notification_id INT UNSIGNED NULL DEFAULT NULL,
  event_code VARCHAR(64) NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  recipient VARCHAR(20) NOT NULL,
  provider VARCHAR(32) NOT NULL DEFAULT 'semaphore',
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  provider_reference VARCHAR(128) NULL DEFAULT NULL,
  error_message VARCHAR(255) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sms_event_recipient (organization_id, event_code, event_id, recipient),
  KEY idx_sms_status (status),
  CONSTRAINT fk_sms_organization FOREIGN KEY (organization_id)
    REFERENCES organizations (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

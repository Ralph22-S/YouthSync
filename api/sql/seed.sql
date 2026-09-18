-- YouthSync Phase 1 seed
-- Demo password for all seeded accounts: YouthSync1!
-- Hashes were produced with PHP password_hash() (PASSWORD_DEFAULT / bcrypt).
-- Do not use this password outside local development.

SET NAMES utf8mb4;

INSERT IGNORE INTO roles (id, code, name) VALUES
  (1, 'SK_OFFICIAL', 'SK Official'),
  (2, 'YOUTH', 'Youth (no SK login)');

INSERT IGNORE INTO organizations (
  id, name, barangay, municipality, province, chairperson, email, contact,
  status, plan, sub_status, cycle, expires_at, youth_count
) VALUES
  (1, 'SK Ibaba del Norte, Paete', 'Ibaba del Norte', 'Paete', 'Laguna',
   'Ariel B. Baldemor', 'sk.ibabadelnorte@demo.example', '09171000001',
   'active', 'premium', 'trial', 'trial', DATE_ADD(CURDATE(), INTERVAL 2 DAY), 0),
  (2, 'SK Bagumbayan, Pagsanjan', 'Bagumbayan', 'Pagsanjan', 'Laguna',
   'Nica R. Villanueva', 'sk.bagumbayan@demo.example', '09171000002',
   'active', 'basic', 'active', 'monthly', DATE_ADD(CURDATE(), INTERVAL 18 DAY), 0),
  (4, 'SK Maytalang I, Lumban', 'Maytalang I', 'Lumban', 'Laguna',
   'Kim P. Quesada', 'sk.maytalang@demo.example', '09171000004',
   'active', 'free', 'free', 'none', NULL, 20),
  (5, 'SK San Antonio, Kalayaan', 'San Antonio', 'Kalayaan', 'Laguna',
   'Renz L. Adeva', 'sk.sanantonio@demo.example', '09171000005',
   'pending', 'free', 'free', 'none', NULL, 0),
  (6, 'SK Dorado, Pakil', 'Dorado', 'Pakil', 'Laguna',
   'Aileen M. Palma', 'sk.dorado@demo.example', '09171000006',
   'inactive', 'basic', 'expired', 'monthly', DATE_ADD(CURDATE(), INTERVAL -10 DAY), 0);

-- Same bcrypt hash for local demo password YouthSync1!
INSERT IGNORE INTO users (
  id, email, password_hash, first_name, last_name, role_id, status, must_change_password
) VALUES
  (1, 'sk1@demo.test', '$2y$10$YMy4gXn1l/RAwlNKOuEJ4OsDXS2FEaCzlZi5RsBfGN.fsrEdakgvC', 'Ariel', 'Baldemor', 1, 'active', 0),
  (2, 'sk2@demo.test', '$2y$10$YMy4gXn1l/RAwlNKOuEJ4OsDXS2FEaCzlZi5RsBfGN.fsrEdakgvC', 'Nica', 'Villanueva', 1, 'active', 0),
  (4, 'sk4@demo.test', '$2y$10$YMy4gXn1l/RAwlNKOuEJ4OsDXS2FEaCzlZi5RsBfGN.fsrEdakgvC', 'Kim', 'Quesada', 1, 'active', 0),
  (10, 'inactive.sk@demo.test', '$2y$10$YMy4gXn1l/RAwlNKOuEJ4OsDXS2FEaCzlZi5RsBfGN.fsrEdakgvC', 'Inactive', 'Official', 1, 'inactive', 0),
  (11, 'pending.sk@demo.test', '$2y$10$YMy4gXn1l/RAwlNKOuEJ4OsDXS2FEaCzlZi5RsBfGN.fsrEdakgvC', 'Renz', 'Adeva', 1, 'active', 0),
  (12, 'inactive.org.sk@demo.test', '$2y$10$YMy4gXn1l/RAwlNKOuEJ4OsDXS2FEaCzlZi5RsBfGN.fsrEdakgvC', 'Aileen', 'Palma', 1, 'active', 0),
  (13, 'orphan.sk@demo.test', '$2y$10$YMy4gXn1l/RAwlNKOuEJ4OsDXS2FEaCzlZi5RsBfGN.fsrEdakgvC', 'No', 'Membership', 1, 'active', 0),
  (14, 'youth1@demo.test', '$2y$10$YMy4gXn1l/RAwlNKOuEJ4OsDXS2FEaCzlZi5RsBfGN.fsrEdakgvC', 'Jona', 'Herbilla', 2, 'active', 0);

INSERT IGNORE INTO organization_users (user_id, organization_id, is_owner, status) VALUES
  (1, 1, 1, 'active'),
  (2, 2, 1, 'active'),
  (4, 4, 1, 'active'),
  (10, 1, 0, 'active'),
  (11, 5, 1, 'active'),
  (12, 6, 1, 'active');

-- Mess Meal Sheet - MySQL schema
-- Import this once (phpMyAdmin > Import, or: mysql -u root -p < schema.sql)

CREATE DATABASE IF NOT EXISTS mess_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE mess_db;

CREATE TABLE IF NOT EXISTS members (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(60)  NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_member_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meals (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id  INT UNSIGNED NOT NULL,
  meal_date  DATE         NOT NULL,
  morning    TINYINT(1)   NOT NULL DEFAULT 0,
  noon       TINYINT(1)   NOT NULL DEFAULT 0,
  night      TINYINT(1)   NOT NULL DEFAULT 0,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_member_date (member_id, meal_date),
  KEY idx_meal_date (meal_date),
  CONSTRAINT fk_meals_member FOREIGN KEY (member_id)
    REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meal_changes (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id      INT UNSIGNED    NOT NULL,
  meal_date      DATE            NOT NULL,
  old_morning    TINYINT(1)      NOT NULL DEFAULT 0,
  old_noon       TINYINT(1)      NOT NULL DEFAULT 0,
  old_night      TINYINT(1)      NOT NULL DEFAULT 0,
  new_morning    TINYINT(1)      NOT NULL DEFAULT 0,
  new_noon       TINYINT(1)      NOT NULL DEFAULT 0,
  new_night      TINYINT(1)      NOT NULL DEFAULT 0,
  source         VARCHAR(20)     NOT NULL DEFAULT 'member',
  locked_override TINYINT(1)     NOT NULL DEFAULT 0,
  ip             VARCHAR(45)     NULL,
  user_agent     VARCHAR(190)    NULL,
  changed_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_change_date (meal_date, changed_at),
  KEY idx_change_member (member_id, meal_date),
  CONSTRAINT fk_changes_member FOREIGN KEY (member_id)
    REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bazar (
  id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  entry_date DATE          NOT NULL,
  member_id  INT UNSIGNED  NULL,
  item       VARCHAR(190)  NOT NULL,
  cost       DECIMAL(12,2) NOT NULL DEFAULT 0,
  file_path  VARCHAR(255)  NULL,
  file_kind  VARCHAR(20)   NULL,
  file_name  VARCHAR(190)  NULL,
  created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bazar_date (entry_date),
  CONSTRAINT fk_bazar_member FOREIGN KEY (member_id)
    REFERENCES members (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deposits (
  id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  entry_date DATE          NOT NULL,
  member_id  INT UNSIGNED  NULL,
  amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
  file_path  VARCHAR(255)  NULL,
  file_kind  VARCHAR(20)   NULL,
  file_name  VARCHAR(190)  NULL,
  created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_deposit_date (entry_date),
  CONSTRAINT fk_deposit_member FOREIGN KEY (member_id)
    REFERENCES members (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO members (name, sort_order) VALUES
  ('Zahid', 1), ('Sojib', 2), ('Ajharul', 3), ('Rakib', 4),
  ('Siyam', 5), ('Akib', 6), ('Saif', 7);

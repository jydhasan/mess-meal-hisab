-- Add the admin/backdate meal audit log to an existing mess_db installation.
-- Import this once in phpMyAdmin, or run:
--   mysql -u root -p mess_db < migrate_meal_lock.sql

CREATE TABLE IF NOT EXISTS meal_changes (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id       INT UNSIGNED    NOT NULL,
  meal_date       DATE            NOT NULL,
  old_morning     TINYINT(1)      NOT NULL DEFAULT 0,
  old_noon        TINYINT(1)      NOT NULL DEFAULT 0,
  old_night       TINYINT(1)      NOT NULL DEFAULT 0,
  new_morning     TINYINT(1)      NOT NULL DEFAULT 0,
  new_noon        TINYINT(1)      NOT NULL DEFAULT 0,
  new_night       TINYINT(1)      NOT NULL DEFAULT 0,
  source          VARCHAR(20)     NOT NULL DEFAULT 'member',
  locked_override TINYINT(1)      NOT NULL DEFAULT 0,
  ip              VARCHAR(45)     NULL,
  user_agent      VARCHAR(190)    NULL,
  changed_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_change_date (meal_date, changed_at),
  KEY idx_change_member (member_id, meal_date),
  CONSTRAINT fk_changes_member FOREIGN KEY (member_id)
    REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

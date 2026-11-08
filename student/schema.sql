-- Student security update — database changes
-- security.php creates these tables automatically the first time a page runs,
-- so running this file is optional. It is here for reference / manual setup.

CREATE TABLE IF NOT EXISTS student_security_answers (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    slot         TINYINT UNSIGNED NOT NULL,              -- 1, 2 or 3
    question_key VARCHAR(40) NOT NULL,                   -- key from sq_questions() in security.php
    answer_hash  VARCHAR(255) NOT NULL,                  -- password_hash() of the normalised answer
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_slot (user_id, slot),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind         VARCHAR(16)  NOT NULL,                  -- login | reset | sqchange
    identifier   VARCHAR(100) NOT NULL,                  -- lower-case username (or user id)
    ip           VARCHAR(45)  NOT NULL,
    success      TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at DATETIME     NOT NULL,
    KEY idx_ident (kind, identifier, attempted_at),
    KEY idx_ip (kind, ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Strongly recommended: stop duplicate accounts being created by two simultaneous sign-ups.
-- (Run only if these indexes do not already exist; fix any duplicate rows first.)
-- ALTER TABLE users ADD UNIQUE KEY uq_users_username (username);
-- ALTER TABLE users ADD UNIQUE KEY uq_users_email (email);

-- The old email-token table is no longer used by the student portal. Drop it only if
-- no other portal (dean / admin / teacher) still uses password_resets:
-- DROP TABLE IF EXISTS password_resets;

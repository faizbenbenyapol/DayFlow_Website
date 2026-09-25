-- =====================================================
-- Migration: Two-factor authentication (TOTP)
--
-- The account already supports Google sign-in and remembered devices, but a
-- password on its own was the only barrier for a normal login. This adds an
-- authenticator-app second step.
--
-- The shared secret is stored encrypted with the app key (appEncrypt), so a
-- leaked database dump alone does not let anyone generate valid codes.
-- Recovery codes are stored only as hashes and are single-use.
-- =====================================================

CREATE TABLE IF NOT EXISTS `user_two_factor` (
    `user_id`      INT UNSIGNED NOT NULL,
    `secret`       TEXT         NOT NULL COMMENT 'base32 TOTP secret, encrypted with the app key',
    `is_enabled`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '0 = set up but not confirmed yet',
    `confirmed_at` TIMESTAMP    NULL DEFAULT NULL,
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `user_two_factor_ibfk_1` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_recovery_codes` (
    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`   INT UNSIGNED NOT NULL,
    `code_hash` CHAR(64)     NOT NULL COMMENT 'sha256 of the normalised code',
    `used_at`   TIMESTAMP    NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_recovery_user_code` (`user_id`, `code_hash`),
    KEY `idx_recovery_user` (`user_id`, `used_at`),
    CONSTRAINT `user_recovery_codes_ibfk_1` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Migration: Web Push subscriptions
--
-- Telegram notifications work but need a bot token per user. Web Push reaches
-- the browser (and an installed PWA) with nothing to set up beyond granting
-- permission once.
--
-- One row per browser: a person with a phone and a laptop has two.
-- =====================================================

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `endpoint`    VARCHAR(500) NOT NULL COMMENT 'push service URL for this browser',
    `p256dh`      VARCHAR(255) NOT NULL COMMENT 'client public key',
    `auth`        VARCHAR(255) NOT NULL COMMENT 'client auth secret',
    `user_agent`  VARCHAR(255) DEFAULT NULL COMMENT 'so the user can tell devices apart',
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` TIMESTAMP   NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    -- The endpoint is the browser's identity; re-subscribing must update the
    -- existing row rather than pile up duplicates. Hashed because the full
    -- endpoint URL is longer than an index key may be.
    UNIQUE KEY `uq_push_endpoint` (`user_id`, `endpoint`(255)),
    KEY `idx_push_user` (`user_id`),
    CONSTRAINT `push_subscriptions_ibfk_1` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  URL Shortener with Analytics -- Database Schema
--  Engine  : MySQL 8.x
--  Charset : utf8mb4 / utf8mb4_unicode_ci
--  Created : 2026-09-20
-- ============================================================

CREATE DATABASE IF NOT EXISTS `url_shortener`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `url_shortener`;

-- ------------------------------------------------------------
-- 1. USERS
--    Stores registered accounts.  Guest links use user_id = NULL
--    in the `urls` table, so this table is optional for basic use.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(50)         NOT NULL,
    `email`         VARCHAR(255)        NOT NULL,
    `password_hash` VARCHAR(255)        NOT NULL,
    `created_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`),
    UNIQUE KEY `uq_users_email`    (`email`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Registered user accounts';


-- ------------------------------------------------------------
-- 2. URLS
--    Stores both guest (user_id IS NULL) and authenticated links.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `urls` (
    `id`            BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `user_id`       BIGINT UNSIGNED              DEFAULT NULL,
    `original_url`  TEXT                NOT NULL,
    `short_code`    VARCHAR(20)         NOT NULL,
    `created_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expiry_date`   DATETIME                     DEFAULT NULL,
    `is_active`     TINYINT(1)          NOT NULL DEFAULT 1,

    PRIMARY KEY (`id`),
    UNIQUE KEY  `uq_urls_short_code` (`short_code`),
    KEY         `idx_urls_user_id`   (`user_id`),
    KEY         `idx_urls_is_active` (`is_active`),
    KEY         `idx_urls_expiry`    (`expiry_date`),

    CONSTRAINT `fk_urls_user`
        FOREIGN KEY (`user_id`)
        REFERENCES  `users` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Shortened URLs (guest and authenticated)';


-- ------------------------------------------------------------
-- 3. CLICKS
--    One row per redirect event; the analytics backbone.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clicks` (
    `id`            BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `url_id`        BIGINT UNSIGNED     NOT NULL,
    `clicked_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_address`    VARCHAR(45)                  DEFAULT NULL,
    `user_agent`    TEXT                         DEFAULT NULL,
    `referrer`      TEXT                         DEFAULT NULL,
    `country`       VARCHAR(2)                   DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `idx_clicks_url_id`    (`url_id`),
    KEY `idx_clicks_clicked_at`(`clicked_at`),
    KEY `idx_clicks_country`   (`country`),

    CONSTRAINT `fk_clicks_url`
        FOREIGN KEY (`url_id`)
        REFERENCES  `urls` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Click / redirect analytics events';


-- ------------------------------------------------------------
-- Optional seed: replace hash with real bcrypt output from PHP
-- password_hash("secret", PASSWORD_BCRYPT)
-- ------------------------------------------------------------
-- INSERT INTO `users` (`username`, `email`, `password_hash`) VALUES
-- ('admin', 'admin@example.com', '$2y$12$REPLACE_WITH_REAL_HASH');

-- ============================================================
-- UniFi Access Manager – Initial schema
-- MySQL 8 / MariaDB 10.6+, utf8mb4, InnoDB
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- --- Roles -----------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(32)  NOT NULL,
    label       VARCHAR(64)  NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Local app users ------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(64)  NOT NULL,
    email         VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(32)  NOT NULL DEFAULT 'readonly',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at DATETIME     NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    CONSTRAINT fk_users_role FOREIGN KEY (role) REFERENCES roles (slug) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Login rate limiting --------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier  VARCHAR(255)    NOT NULL,
    ip_address  VARCHAR(45)     NOT NULL,
    success     TINYINT(1)      NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attempts_identifier_time (identifier, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- UniFi connections (one per controller / location) --------
CREATE TABLE IF NOT EXISTS unifi_connections (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(128) NOT NULL,
    host         VARCHAR(255) NOT NULL,
    port         SMALLINT UNSIGNED NOT NULL DEFAULT 12445,
    api_token_enc TEXT        NOT NULL,
    verify_ssl   TINYINT(1)   NOT NULL DEFAULT 0,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_connections_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Cached UniFi users (Personen) ----------------------------
CREATE TABLE IF NOT EXISTS unifi_users (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    connection_id        INT UNSIGNED NOT NULL,
    unifi_id             VARCHAR(64)  NOT NULL,
    first_name           VARCHAR(128) NULL,
    last_name            VARCHAR(128) NULL,
    full_name            VARCHAR(255) NULL,
    email                VARCHAR(255) NULL,
    employee_number      VARCHAR(64)  NULL,
    status               VARCHAR(32)  NULL,
    onboard_time         INT          NULL,
    access_policy_ids_json JSON       NULL,
    raw_json             JSON         NULL,
    last_synced_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_conn_unifi (connection_id, unifi_id),
    KEY idx_users_fullname (full_name),
    KEY idx_users_email (email),
    KEY idx_users_status (status),
    KEY idx_users_employee (employee_number),
    CONSTRAINT fk_unifi_users_conn FOREIGN KEY (connection_id) REFERENCES unifi_connections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Cached UniFi credentials (RFID-Karten) -------------------
CREATE TABLE IF NOT EXISTS unifi_credentials (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    connection_id INT UNSIGNED NOT NULL,
    unifi_token   VARCHAR(128) NOT NULL,
    display_id    VARCHAR(128) NULL,
    status        VARCHAR(32)  NULL,
    alias         VARCHAR(128) NULL,
    card_type     VARCHAR(64)  NULL,
    user_unifi_id VARCHAR(64)  NULL,
    raw_json      JSON         NULL,
    last_synced_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_credentials_conn_token (connection_id, unifi_token),
    KEY idx_credentials_status (status),
    KEY idx_credentials_user (user_unifi_id),
    KEY idx_credentials_display (display_id),
    CONSTRAINT fk_unifi_credentials_conn FOREIGN KEY (connection_id) REFERENCES unifi_connections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Cached UniFi access policies (Zutrittsgruppen) -----------
CREATE TABLE IF NOT EXISTS unifi_access_groups (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    connection_id  INT UNSIGNED NOT NULL,
    unifi_id       VARCHAR(64)  NOT NULL,
    name           VARCHAR(255) NOT NULL,
    schedule_id    VARCHAR(64)  NULL,
    resources_json JSON         NULL,
    raw_json       JSON         NULL,
    last_synced_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_groups_conn_unifi (connection_id, unifi_id),
    KEY idx_groups_name (name),
    CONSTRAINT fk_unifi_groups_conn FOREIGN KEY (connection_id) REFERENCES unifi_connections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Cached UniFi doors (Türen) -------------------------------
CREATE TABLE IF NOT EXISTS unifi_doors (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    connection_id INT UNSIGNED NOT NULL,
    unifi_id      VARCHAR(64)  NOT NULL,
    name          VARCHAR(255) NOT NULL,
    full_name     VARCHAR(255) NULL,
    floor_id      VARCHAR(64)  NULL,
    door_type     VARCHAR(64)  NULL,
    lock_status   VARCHAR(32)  NULL,
    raw_json      JSON         NULL,
    last_synced_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_doors_conn_unifi (connection_id, unifi_id),
    KEY idx_doors_name (name),
    CONSTRAINT fk_unifi_doors_conn FOREIGN KEY (connection_id) REFERENCES unifi_connections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Audit log -------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NULL,
    username     VARCHAR(64)  NULL,
    action       VARCHAR(128) NOT NULL,
    entity_type  VARCHAR(64)  NULL,
    entity_id    VARCHAR(128) NULL,
    entity_label VARCHAR(255) NULL,
    result       VARCHAR(16)  NOT NULL DEFAULT 'success',
    ip_address   VARCHAR(45)  NULL,
    details      JSON         NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_created (created_at),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Synchronisation log --------------------------------------
CREATE TABLE IF NOT EXISTS sync_logs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    connection_id INT UNSIGNED NULL,
    status        VARCHAR(16)  NOT NULL,
    message       VARCHAR(512) NULL,
    stats         JSON         NULL,
    started_at    DATETIME     NULL,
    finished_at   DATETIME     NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sync_created (created_at),
    CONSTRAINT fk_sync_connection FOREIGN KEY (connection_id) REFERENCES unifi_connections (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Application settings --------------------------------------
CREATE TABLE IF NOT EXISTS app_settings (
    `key`      VARCHAR(128) NOT NULL,
    `value`    TEXT         NULL,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

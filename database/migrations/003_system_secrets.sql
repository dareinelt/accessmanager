-- ============================================================
-- UniFi Access Manager – Systemgeheimnisse & sysadmin-Rolle
-- Vertrauliche Systemdaten (AD, DNs, API-Endpunkte) werden
-- verschlüsselt abgelegt und sind nur für sysadmins sichtbar.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Neue Rolle "sysadmin" (höchste Berechtigungsstufe).
INSERT IGNORE INTO roles (slug, label) VALUES ('sysadmin', 'Systemadministrator');

-- --- Verschlüsselte Systemgeheimnisse -------------------------
CREATE TABLE IF NOT EXISTS system_secrets (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key`       VARCHAR(128) NOT NULL,
    label       VARCHAR(255) NOT NULL,
    category    VARCHAR(64)  NOT NULL DEFAULT 'other',
    value_enc   TEXT         NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_secrets_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Active Directory (AD) Integration
--
-- 1. ad_group_mappings  Zuordnung AD-Gruppe (DN) <-> UniFi Access
--                       Zutrittsgruppe (unifi_access_groups.unifi_id).
-- 2. unifi_users        AD-Metadaten an der (cached) Person:
--                         ad_identifier     objectSid / samAccountName / UPN
--                         ad_member_of_json Mitgliedschaften (memberOf)
--                         ad_synced_at      Zeitpunkt der letzten AD-Sync
--
-- Die Migration muss idempotent sein, da `bin/cli.php migrate` bei jedem
-- Containerstart alle Dateien erneut ausfuehrt (kein Migrationstracking).
-- ============================================================

SET NAMES utf8mb4;

-- --- AD-Gruppe <-> Zutrittsgruppe ----------------------------
CREATE TABLE IF NOT EXISTS ad_group_mappings (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    connection_id   INT UNSIGNED NOT NULL,
    ad_group_dn     VARCHAR(1024) NOT NULL,
    ad_group_name   VARCHAR(255)  NULL,
    access_group_id VARCHAR(64)   NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Praefix-Index: MySQL 8 begrenzt Schluessel auf 3072 Bytes (utf8mb4).
    UNIQUE KEY uq_ad_mappings_conn_dn (connection_id, ad_group_dn(700)),
    KEY idx_ad_mappings_access_group (connection_id, access_group_id),
    CONSTRAINT fk_ad_mappings_conn FOREIGN KEY (connection_id) REFERENCES unifi_connections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- AD-Metadaten an cached Personen -------------------------
-- Portabel fuer MariaDB und MySQL 8: MySQL kennt weder
-- `ADD COLUMN IF NOT EXISTS` noch `ADD INDEX IF NOT EXISTS`. Daher wird
-- jede Aenderung nur ausgefuehrt, wenn information_schema sie noch nicht kennt.
SET @uam_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unifi_users' AND COLUMN_NAME = 'ad_identifier') = 0,
    'ALTER TABLE unifi_users ADD COLUMN ad_identifier VARCHAR(255) NULL AFTER raw_json',
    'DO 0');
PREPARE uam_stmt FROM @uam_sql;
EXECUTE uam_stmt;
DEALLOCATE PREPARE uam_stmt;

SET @uam_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unifi_users' AND COLUMN_NAME = 'ad_member_of_json') = 0,
    'ALTER TABLE unifi_users ADD COLUMN ad_member_of_json JSON NULL AFTER ad_identifier',
    'DO 0');
PREPARE uam_stmt FROM @uam_sql;
EXECUTE uam_stmt;
DEALLOCATE PREPARE uam_stmt;

SET @uam_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unifi_users' AND COLUMN_NAME = 'ad_synced_at') = 0,
    'ALTER TABLE unifi_users ADD COLUMN ad_synced_at DATETIME NULL AFTER ad_member_of_json',
    'DO 0');
PREPARE uam_stmt FROM @uam_sql;
EXECUTE uam_stmt;
DEALLOCATE PREPARE uam_stmt;

SET @uam_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unifi_users' AND INDEX_NAME = 'idx_users_ad_identifier') = 0,
    'ALTER TABLE unifi_users ADD INDEX idx_users_ad_identifier (ad_identifier)',
    'DO 0');
PREPARE uam_stmt FROM @uam_sql;
EXECUTE uam_stmt;
DEALLOCATE PREPARE uam_stmt;

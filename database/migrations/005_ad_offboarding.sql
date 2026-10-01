-- ============================================================
-- UniFi Access Manager - AD-Offboarding
--
-- unifi_users.ad_deactivated_at: Zeitpunkt, zu dem der AD-Sync die Person
-- in UniFi deaktiviert hat, weil ihr AD-Konto deaktiviert oder geloescht
-- wurde. Nur so markierte Personen werden automatisch wieder aktiviert,
-- sobald das AD-Konto zurueckkehrt.
--
-- Idempotent und portabel fuer MariaDB und MySQL 8.
-- ============================================================

SET @uam_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unifi_users' AND COLUMN_NAME = 'ad_deactivated_at') = 0,
    'ALTER TABLE unifi_users ADD COLUMN ad_deactivated_at DATETIME NULL AFTER ad_synced_at',
    'DO 0');
PREPARE uam_stmt FROM @uam_sql;
EXECUTE uam_stmt;
DEALLOCATE PREPARE uam_stmt;

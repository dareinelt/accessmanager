-- ============================================================
-- UniFi Access Manager - Security hardening
-- Index for the per-IP login rate limit.
-- ============================================================

-- Portabel fuer MariaDB und MySQL 8 (kein `ADD INDEX IF NOT EXISTS`).
SET @uam_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'idx_attempts_ip_time') = 0,
    'ALTER TABLE login_attempts ADD INDEX idx_attempts_ip_time (ip_address, attempted_at)',
    'DO 0');
PREPARE uam_stmt FROM @uam_sql;
EXECUTE uam_stmt;
DEALLOCATE PREPARE uam_stmt;

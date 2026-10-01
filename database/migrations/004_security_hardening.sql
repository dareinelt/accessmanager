-- ============================================================
-- UniFi Access Manager - Security hardening
-- Index for the per-IP login rate limit.
-- ============================================================

ALTER TABLE login_attempts
    ADD INDEX IF NOT EXISTS idx_attempts_ip_time (ip_address, attempted_at);

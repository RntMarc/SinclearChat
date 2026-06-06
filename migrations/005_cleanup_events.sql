-- =============================================================================
-- Migration 005: Cleanup Events
-- =============================================================================
-- Periodische Aufräumarbeiten für v2-Tabellen.
-- =============================================================================

SET GLOBAL event_scheduler = ON;

-- SSE Event History (15 Minuten Retention) ------------------------------------
DROP EVENT IF EXISTS cleanup_sse_events;
CREATE EVENT cleanup_sse_events
ON SCHEDULE EVERY 15 MINUTE
STARTS CURRENT_TIMESTAMP
DO
    DELETE FROM sse_events WHERE created_at < NOW() - INTERVAL 15 MINUTE;

-- JTI Blacklist (nur expired entries entfernen) -------------------------------
DROP EVENT IF EXISTS cleanup_jti_blacklist;
CREATE EVENT cleanup_jti_blacklist
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
DO
    DELETE FROM jti_blacklist WHERE expires_at < NOW();

-- Alte Read-Receipts (90 Tage) -------------------------------------------------
DROP EVENT IF EXISTS cleanup_old_read_receipts;
CREATE EVENT cleanup_old_read_receipts
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
    DELETE FROM chat_read_receipts WHERE last_read_at < NOW() - INTERVAL 90 DAY;

-- =============================================================================
-- Migration 004: API v2 Schema
-- =============================================================================
-- Fügt alle Tabellen, Spalten und Constraints hinzu, die für die parallele
-- API v2 (JWT/RS256) benötigt werden, ohne die bestehende API v1 zu brechen.
-- =============================================================================

-- OAuth2 / PKCE ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auth_codes (
    code VARCHAR(64) NOT NULL PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL,
    code_challenge VARCHAR(128) NOT NULL,
    code_challenge_method VARCHAR(10) NOT NULL DEFAULT 'S256',
    redirect_uri VARCHAR(500) DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auth_codes_user (user_id),
    INDEX idx_auth_codes_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Refresh Token Families + Reuse-Detection ------------------------------------
CREATE TABLE IF NOT EXISTS refresh_token_families (
    id BINARY(16) NOT NULL PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME DEFAULT NULL,
    revoked_reason ENUM('logout', 'reuse_detected', 'expired', 'security') DEFAULT NULL,
    INDEX idx_families_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id BINARY(16) NOT NULL PRIMARY KEY,
    family_id BINARY(16) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    parent_id BINARY(16) DEFAULT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_refresh_user (user_id),
    INDEX idx_refresh_family (family_id),
    INDEX idx_refresh_hash (token_hash),
    CONSTRAINT fk_refresh_family FOREIGN KEY (family_id) REFERENCES refresh_token_families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- JTI Blacklist ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jti_blacklist (
    jti VARCHAR(64) NOT NULL PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_jti_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Direct Chats (kanonische Resource) -------------------------------------------
CREATE TABLE IF NOT EXISTS direct_chats (
    id BINARY(16) NOT NULL PRIMARY KEY,
    user_a_id VARCHAR(255) NOT NULL,
    user_b_id VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_message_at TIMESTAMP(6) DEFAULT NULL,
    UNIQUE KEY uniq_direct_pair (user_a_id, user_b_id),
    CONSTRAINT chk_pair_order CHECK (user_a_id < user_b_id),
    INDEX idx_direct_user_a (user_a_id),
    INDEX idx_direct_user_b (user_b_id),
    INDEX idx_direct_last_msg (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ChatMessages: Erweiterungen für v2 -------------------------------------------
ALTER TABLE ChatMessages
    ADD COLUMN direct_chat_id BINARY(16) DEFAULT NULL AFTER chat_type,
    ADD COLUMN attachment_upload_id BINARY(16) DEFAULT NULL AFTER attachment_body;

ALTER TABLE ChatMessages
    ADD INDEX idx_messages_direct (direct_chat_id, created_at),
    ADD INDEX idx_messages_upload (attachment_upload_id);

-- Uploads (Storage-abstrahiert) -----------------------------------------------
CREATE TABLE IF NOT EXISTS uploads (
    id BINARY(16) NOT NULL PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    storage_driver VARCHAR(50) NOT NULL DEFAULT 'local',
    thumbnail_path VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_uploads_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ChatMessages
    ADD CONSTRAINT fk_messages_direct FOREIGN KEY (direct_chat_id) REFERENCES direct_chats(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_messages_upload FOREIGN KEY (attachment_upload_id) REFERENCES uploads(id) ON DELETE SET NULL;

-- User Profiles (Cache) --------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_profiles (
    user_id VARCHAR(255) NOT NULL PRIMARY KEY,
    display_name VARCHAR(255) NOT NULL,
    avatar LONGTEXT DEFAULT NULL,
    status_message VARCHAR(500) DEFAULT NULL,
    token_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Read Receipts ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_read_receipts (
    user_id VARCHAR(255) NOT NULL,
    chat_id VARCHAR(255) NOT NULL,
    chat_type ENUM('direct', 'group') NOT NULL,
    last_read_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (user_id, chat_id, chat_type),
    INDEX idx_receipts_user (user_id, last_read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User Presence (reduziert: online/offline + last_seen) -----------------------
CREATE TABLE IF NOT EXISTS user_presence (
    user_id VARCHAR(255) NOT NULL PRIMARY KEY,
    status ENUM('online', 'offline') NOT NULL DEFAULT 'offline',
    last_seen_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Devices (Push-Notifications) -------------------------------------------------
CREATE TABLE IF NOT EXISTS user_devices (
    id BINARY(16) NOT NULL PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL,
    platform VARCHAR(50) NOT NULL,
    device_id VARCHAR(255) NOT NULL,
    push_token TEXT NOT NULL,
    app_version VARCHAR(50) DEFAULT NULL,
    last_access_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_platform_device (user_id, platform, device_id),
    INDEX idx_devices_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SSE Event History (für Last-Event-ID) ---------------------------------------
CREATE TABLE IF NOT EXISTS sse_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(255) DEFAULT NULL,
    event_type VARCHAR(50) NOT NULL,
    payload JSON NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_sse_user_id (user_id, id),
    INDEX idx_sse_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Group Roles ------------------------------------------------------------------
ALTER TABLE ChatRoomMembers
    ADD COLUMN role ENUM('owner', 'admin', 'member') NOT NULL DEFAULT 'member' AFTER user_id;

-- ChatRooms.avatar für Gruppenavatars -----------------------------------------
ALTER TABLE ChatRooms
    ADD COLUMN avatar LONGTEXT DEFAULT NULL AFTER description;

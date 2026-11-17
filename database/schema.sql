-- DimdianChatbot — clean schema for the website ↔ WhatsApp bridge.
-- Requires MySQL 8.0+ or a compatible MariaDB release.
-- Run once against an empty database. All timestamps are stored in UTC.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE guests (
    id CHAR(36) NOT NULL,
    display_name VARCHAR(100) NULL,
    phone VARCHAR(32) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_guests_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conversations (
    id CHAR(36) NOT NULL,
    guest_id CHAR(36) NOT NULL,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    last_message_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_conversations_guest_created (guest_id, created_at),
    KEY idx_conversations_status_updated (status, updated_at),
    CONSTRAINT fk_conversations_guest
        FOREIGN KEY (guest_id) REFERENCES guests (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guest_sessions (
    id CHAR(36) NOT NULL,
    guest_id CHAR(36) NOT NULL,
    conversation_id CHAR(36) NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    last_seen_at DATETIME NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_guest_sessions_token_hash (token_hash),
    KEY idx_guest_sessions_guest (guest_id, created_at),
    KEY idx_guest_sessions_expiry (expires_at, revoked_at),
    CONSTRAINT fk_guest_sessions_guest
        FOREIGN KEY (guest_id) REFERENCES guests (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_guest_sessions_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    id CHAR(36) NOT NULL,
    conversation_id CHAR(36) NOT NULL,
    sender_type ENUM('visitor', 'business', 'system') NOT NULL,
    body TEXT NOT NULL,
    provider_message_id VARCHAR(191) NULL,
    delivery_status ENUM('queued', 'sent', 'delivered', 'read', 'failed', 'received') NOT NULL DEFAULT 'received',
    sent_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_messages_provider_message_id (provider_message_id),
    KEY idx_messages_conversation_order (conversation_id, sent_at, id),
    CONSTRAINT fk_messages_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE webhook_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_event_id VARCHAR(191) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    received_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    processing_status ENUM('received', 'processed', 'ignored', 'failed') NOT NULL DEFAULT 'received',
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_events_provider_id (provider_event_id),
    KEY idx_webhook_events_status_received (processing_status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_started_at DATETIME NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket_key),
    KEY idx_rate_limits_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

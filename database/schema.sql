-- DimdianChatbot — clean schema for the website ↔ WhatsApp bridge.
-- Requires MySQL 8.0+ or a compatible MariaDB release.
-- Run once against an empty database. All timestamps are stored in UTC.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE tenants (
    id CHAR(36) NOT NULL,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenants_slug (slug)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tenants (id, name, slug, status, created_at, updated_at)
VALUES ('00000000-0000-4000-8000-000000000001', 'Default business', 'default', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP());

CREATE TABLE tenant_widgets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id CHAR(36) NOT NULL,
    public_widget_id VARCHAR(128) NOT NULL,
    allowed_origins JSON NOT NULL,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_widgets_public_id (public_widget_id),
    KEY idx_tenant_widgets_tenant_status (tenant_id, status),
    CONSTRAINT fk_tenant_widgets_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guests (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    display_name VARCHAR(100) NULL,
    phone VARCHAR(32) NULL,
    phone_normalized VARCHAR(32) NULL,
    whatsapp_opt_in_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_guests_tenant_id (tenant_id, id),
    KEY idx_guests_tenant_phone (tenant_id, phone),
    KEY idx_guests_phone (phone),
    KEY idx_guests_phone_normalized (phone_normalized),
    CONSTRAINT fk_guests_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conversations (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    guest_id CHAR(36) NOT NULL,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    last_message_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_conversations_tenant_id (tenant_id, id),
    KEY idx_conversations_tenant_guest_created (tenant_id, guest_id, created_at),
    KEY idx_conversations_guest_created (guest_id, created_at),
    KEY idx_conversations_status_updated (status, updated_at),
    CONSTRAINT fk_conversations_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_conversations_guest
        FOREIGN KEY (tenant_id, guest_id) REFERENCES guests (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guest_sessions (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    guest_id CHAR(36) NOT NULL,
    conversation_id CHAR(36) NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    last_seen_at DATETIME NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_guest_sessions_tenant_id (tenant_id, id),
    UNIQUE KEY uq_guest_sessions_token_hash (token_hash),
    KEY idx_guest_sessions_guest (guest_id, created_at),
    KEY idx_guest_sessions_expiry (expires_at, revoked_at),
    CONSTRAINT fk_guest_sessions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_guest_sessions_guest
        FOREIGN KEY (tenant_id, guest_id) REFERENCES guests (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_guest_sessions_conversation
        FOREIGN KEY (tenant_id, conversation_id) REFERENCES conversations (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    sequence_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    conversation_id CHAR(36) NOT NULL,
    client_message_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    sender_type ENUM('visitor', 'business', 'system') NOT NULL,
    body TEXT NOT NULL,
    provider_message_id VARCHAR(191) NULL,
    delivery_status ENUM('queued', 'sent', 'delivered', 'read', 'failed', 'received', 'web_only') NOT NULL DEFAULT 'received',
    sent_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_messages_tenant_id (tenant_id, id),
    UNIQUE KEY uq_messages_sequence_id (sequence_id),
    UNIQUE KEY uq_messages_provider_message_id (provider_message_id),
    UNIQUE KEY uq_messages_client_message (tenant_id, conversation_id, client_message_id),
    KEY idx_messages_conversation_order (conversation_id, sequence_id),
    CONSTRAINT fk_messages_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_messages_conversation
        FOREIGN KEY (tenant_id, conversation_id) REFERENCES conversations (tenant_id, id)
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
    tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_started_at DATETIME NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (tenant_id, bucket_key),
    KEY idx_rate_limits_window (window_started_at),
    CONSTRAINT fk_rate_limits_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE message_outbox (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    message_id CHAR(36) NOT NULL,
    recipient VARCHAR(32) NOT NULL,
    payload_text TEXT NOT NULL,
    status ENUM('pending', 'processing', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    locked_at DATETIME NULL,
    lock_token CHAR(36) NULL,
    provider_message_id VARCHAR(191) NULL,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_message_outbox_tenant_id (tenant_id, id),
    UNIQUE KEY uq_message_outbox_message (tenant_id, message_id),
    KEY idx_message_outbox_due (status, available_at, created_at),
    KEY idx_message_outbox_lock (status, locked_at),
    CONSTRAINT fk_message_outbox_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_message_outbox_message
        FOREIGN KEY (tenant_id, message_id) REFERENCES messages (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE message_status_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    provider_message_id VARCHAR(191) NOT NULL,
    status ENUM('sent', 'delivered', 'read', 'failed') NOT NULL,
    event_timestamp BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_message_status_event (tenant_id, provider_message_id, status, event_timestamp),
    KEY idx_message_status_provider (provider_message_id, event_timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auditable consent lifecycle and unambiguous WhatsApp identity routing.
CREATE TABLE guest_consent_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    guest_id CHAR(36) NOT NULL,
    channel VARCHAR(24) NOT NULL,
    action ENUM('opt_in','opt_out') NOT NULL,
    source VARCHAR(40) NOT NULL,
    policy_version VARCHAR(32) NOT NULL DEFAULT '2026-11',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_consent_guest_created (guest_id, created_at),
    KEY idx_consent_channel_action (channel, action, created_at),
    CONSTRAINT fk_consent_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
      ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_consent_guest FOREIGN KEY (tenant_id, guest_id) REFERENCES guests(tenant_id, id)
      ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_identity_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    phone_normalized VARCHAR(32) NOT NULL,
    guest_id CHAR(36) NOT NULL,
    conversation_id CHAR(36) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    active_phone VARCHAR(32) GENERATED ALWAYS AS (CASE WHEN is_active = 1 THEN phone_normalized ELSE NULL END) STORED,
    created_at DATETIME NOT NULL,
    deactivated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_active_phone (tenant_id, active_phone),
    KEY idx_whatsapp_link_conversation (conversation_id, is_active),
    CONSTRAINT fk_whatsapp_link_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
      ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_whatsapp_link_guest FOREIGN KEY (tenant_id, guest_id) REFERENCES guests(tenant_id, id)
      ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_whatsapp_link_conversation FOREIGN KEY (tenant_id, conversation_id) REFERENCES conversations(tenant_id, id)
      ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verified webhook payloads are encrypted before they are acknowledged to Meta.
CREATE TABLE webhook_inbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_ciphertext LONGBLOB NOT NULL,
    payload_nonce VARBINARY(12) NOT NULL,
    payload_tag VARBINARY(16) NOT NULL,
    status ENUM('pending','processing','processed','failed') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    locked_at DATETIME NULL,
    lock_token CHAR(36) NULL,
    last_error VARCHAR(500) NULL,
    received_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_inbox_payload (payload_sha256),
    KEY idx_webhook_inbox_due (status, available_at, received_at),
    KEY idx_webhook_inbox_lock (status, locked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meta_signup_states (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    state_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_meta_signup_state (state_sha256),
    KEY idx_meta_signup_expiry (expires_at, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meta_connections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    waba_id VARCHAR(80) NOT NULL,
    phone_number_id VARCHAR(80) NOT NULL,
    access_token_ciphertext LONGBLOB NOT NULL,
    access_token_nonce VARBINARY(12) NOT NULL,
    access_token_tag VARBINARY(16) NOT NULL,
    token_expires_at DATETIME NULL,
    status ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
    connected_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_meta_phone_number (phone_number_id),
    KEY idx_meta_connection_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

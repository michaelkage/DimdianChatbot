-- ChatBridge multi-tenant foundation.
-- Apply manually to an existing database after taking a verified backup.
-- This migration does not run automatically and does not touch production by itself.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE tenants (
    id CHAR(36) NOT NULL,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    status ENUM('active', 'suspended', 'closed') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenants_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_sites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id CHAR(36) NOT NULL,
    origin VARCHAR(255) NOT NULL,
    status ENUM('pending', 'verified', 'disabled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_sites_origin (origin),
    KEY idx_tenant_sites_tenant (tenant_id, status),
    CONSTRAINT fk_tenant_sites_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE channel_connections (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    provider VARCHAR(40) NOT NULL,
    business_account_id VARCHAR(191) NULL,
    phone_number_id VARCHAR(191) NULL,
    display_phone_number VARCHAR(64) NULL,
    connection_status ENUM('not_connected', 'signup_started', 'authorization_received', 'account_discovered', 'credentials_exchanged', 'webhook_subscribed', 'number_verified', 'connection_tested', 'connected', 'needs_action', 'disconnected', 'revoked', 'failed') NOT NULL DEFAULT 'not_connected',
    credential_ciphertext MEDIUMTEXT NULL,
    credential_key_version VARCHAR(32) NULL,
    token_expires_at DATETIME NULL,
    last_error_code VARCHAR(100) NULL,
    last_error_message VARCHAR(500) NULL,
    connected_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_channel_phone (provider, phone_number_id),
    UNIQUE KEY uq_channel_waba (provider, business_account_id),
    KEY idx_channel_tenant_status (tenant_id, connection_status),
    CONSTRAINT fk_channel_connections_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve existing single-business data under a deterministic legacy tenant.
INSERT INTO tenants (id, name, slug, status, created_at, updated_at)
VALUES ('00000000-0000-4000-8000-000000000001', 'Legacy ChatBridge site', 'legacy-default', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE name = VALUES(name);

ALTER TABLE guests
    ADD COLUMN tenant_id CHAR(36) NULL AFTER id,
    ADD KEY idx_guests_tenant_phone (tenant_id, phone_normalized);

ALTER TABLE conversations
    ADD COLUMN tenant_id CHAR(36) NULL AFTER id,
    ADD KEY idx_conversations_tenant_status (tenant_id, status, updated_at);

ALTER TABLE guest_sessions
    ADD COLUMN tenant_id CHAR(36) NULL AFTER id,
    ADD KEY idx_guest_sessions_tenant_conversation (tenant_id, conversation_id);

ALTER TABLE messages
    ADD COLUMN tenant_id CHAR(36) NULL AFTER id,
    ADD KEY idx_messages_tenant_conversation (tenant_id, conversation_id, sequence_id);

ALTER TABLE webhook_events
    ADD COLUMN tenant_id CHAR(36) NULL AFTER id,
    ADD KEY idx_webhook_events_tenant_status (tenant_id, processing_status, received_at);

ALTER TABLE message_outbox
    ADD COLUMN tenant_id CHAR(36) NULL AFTER id,
    ADD KEY idx_message_outbox_tenant_due (tenant_id, status, available_at, created_at);

ALTER TABLE message_status_events
    ADD COLUMN tenant_id CHAR(36) NULL AFTER id,
    ADD KEY idx_message_status_tenant_provider (tenant_id, provider_message_id, event_timestamp);

-- Backfill ownership from each conversation's guest. Existing rows are never reassigned to a new tenant.
UPDATE guests SET tenant_id = '00000000-0000-4000-8000-000000000001' WHERE tenant_id IS NULL;
UPDATE conversations c
INNER JOIN guests g ON g.id = c.guest_id
SET c.tenant_id = g.tenant_id
WHERE c.tenant_id IS NULL;
UPDATE guest_sessions gs
INNER JOIN conversations c ON c.id = gs.conversation_id
SET gs.tenant_id = c.tenant_id
WHERE gs.tenant_id IS NULL;
UPDATE messages m
INNER JOIN conversations c ON c.id = m.conversation_id
SET m.tenant_id = c.tenant_id
WHERE m.tenant_id IS NULL;
UPDATE message_outbox o
INNER JOIN messages m ON m.id = o.message_id
SET o.tenant_id = m.tenant_id
WHERE o.tenant_id IS NULL;
UPDATE message_status_events se
INNER JOIN messages m ON m.provider_message_id = se.provider_message_id
SET se.tenant_id = m.tenant_id
WHERE se.tenant_id IS NULL;
-- Status callbacks may arrive before the corresponding message (the integration
-- suite explicitly covers this race). Preserve those uncorrelated events under
-- the legacy tenant so the NOT NULL conversion is safe.
UPDATE message_status_events
SET tenant_id = '00000000-0000-4000-8000-000000000001'
WHERE tenant_id IS NULL;

-- Historical webhook events are assigned to the legacy tenant. Future webhook
-- ingestion must resolve phone_number_id to a channel connection before writes.
UPDATE webhook_events
SET tenant_id = '00000000-0000-4000-8000-000000000001'
WHERE tenant_id IS NULL;

ALTER TABLE guests
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_guests_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE conversations
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD UNIQUE KEY uq_conversations_tenant_id (tenant_id, id),
    ADD CONSTRAINT fk_conversations_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE guest_sessions
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_guest_sessions_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE messages
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD UNIQUE KEY uq_messages_tenant_id (tenant_id, id),
    DROP INDEX uq_messages_provider_message_id,
    ADD UNIQUE KEY uq_messages_tenant_provider_id (tenant_id, provider_message_id),
    ADD CONSTRAINT fk_messages_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE webhook_events
    MODIFY tenant_id CHAR(36) NOT NULL,
    DROP INDEX uq_webhook_events_provider_id,
    ADD UNIQUE KEY uq_webhook_events_tenant_provider_id (tenant_id, provider_event_id),
    ADD CONSTRAINT fk_webhook_events_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE message_outbox
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_message_outbox_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE message_status_events
    MODIFY tenant_id CHAR(36) NOT NULL,
    DROP INDEX uq_message_status_event,
    ADD UNIQUE KEY uq_message_status_tenant_event (tenant_id, provider_message_id, status, event_timestamp),
    ADD CONSTRAINT fk_message_status_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE;

-- Enforce tenant-consistent parent relationships at the database layer.
ALTER TABLE guests ADD UNIQUE KEY uq_guests_tenant_id (tenant_id, id);
ALTER TABLE conversations
    ADD CONSTRAINT fk_conversations_tenant_guest
        FOREIGN KEY (tenant_id, guest_id) REFERENCES guests (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE guest_sessions
    ADD CONSTRAINT fk_guest_sessions_tenant_conversation
        FOREIGN KEY (tenant_id, conversation_id) REFERENCES conversations (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE messages
    ADD CONSTRAINT fk_messages_tenant_conversation
        FOREIGN KEY (tenant_id, conversation_id) REFERENCES conversations (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE;

-- Existing single-site installation: register allowed origins manually as verified
-- tenant_sites rows after reviewing config.php. Never auto-trust a request Origin.

-- Coexistence contact and history synchronization.
-- Apply after migrations 004–006.

CREATE TABLE channel_sync_jobs (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    channel_connection_id CHAR(36) NOT NULL,
    sync_type ENUM('smb_app_state_sync', 'history') NOT NULL,
    status ENUM('pending', 'submitting', 'requested', 'receiving', 'completed', 'failed', 'expired') NOT NULL DEFAULT 'pending',
    request_id VARCHAR(191) NULL,
    attempted_at DATETIME NULL,
    requested_at DATETIME NULL,
    completed_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_channel_sync_type (tenant_id, channel_connection_id, sync_type),
    KEY idx_channel_sync_due (tenant_id, status, created_at),
    CONSTRAINT fk_channel_sync_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_channel_sync_tenant_connection FOREIGN KEY (tenant_id, channel_connection_id)
        REFERENCES channel_connections (tenant_id, id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id CHAR(36) NOT NULL,
    channel_connection_id CHAR(36) NOT NULL,
    phone_normalized VARCHAR(32) NOT NULL,
    display_name VARCHAR(191) NULL,
    first_name VARCHAR(100) NULL,
    status ENUM('active', 'removed') NOT NULL DEFAULT 'active',
    last_synced_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_contact (tenant_id, channel_connection_id, phone_normalized),
    KEY idx_whatsapp_contacts_tenant_name (tenant_id, display_name),
    CONSTRAINT fk_whatsapp_contacts_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_whatsapp_contacts_tenant_connection FOREIGN KEY (tenant_id, channel_connection_id)
        REFERENCES channel_connections (tenant_id, id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coexistence_history_payloads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id CHAR(36) NOT NULL,
    channel_connection_id CHAR(36) NOT NULL,
    payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_json MEDIUMTEXT NOT NULL,
    received_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_coex_history_payload (tenant_id, channel_connection_id, payload_sha256),
    KEY idx_coex_history_tenant_time (tenant_id, received_at),
    CONSTRAINT fk_coex_history_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_coex_history_tenant_connection FOREIGN KEY (tenant_id, channel_connection_id)
        REFERENCES channel_connections (tenant_id, id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

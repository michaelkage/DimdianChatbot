-- Tenant admin credentials and Meta connection audit trail.
-- Apply after migrations 004 and 005.

CREATE TABLE tenant_api_keys (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    label VARCHAR(120) NOT NULL,
    key_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    role ENUM('owner', 'admin', 'support') NOT NULL DEFAULT 'admin',
    status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_api_key_hash (key_sha256),
    KEY idx_tenant_api_keys_tenant (tenant_id, status, role),
    CONSTRAINT fk_tenant_api_keys_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE channel_connection_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id CHAR(36) NOT NULL,
    channel_connection_id CHAR(36) NULL,
    event_type VARCHAR(100) NOT NULL,
    event_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_channel_events_tenant_time (tenant_id, created_at),
    KEY idx_channel_events_connection (tenant_id, channel_connection_id, created_at),
    CONSTRAINT fk_channel_events_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_channel_events_connection FOREIGN KEY (channel_connection_id)
        REFERENCES channel_connections (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

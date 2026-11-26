-- Tenant ownership for provider-facing persistence.
-- Existing single-tenant rows are assigned to the deterministic legacy tenant.
-- Runtime repositories continue to use that tenant until tenant context is threaded
-- through the webhook, Meta signup and worker paths in subsequent migrations.
SET time_zone = '+00:00';

ALTER TABLE webhook_events
    ADD COLUMN tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001' AFTER id,
    DROP INDEX uq_webhook_events_provider_id,
    ADD UNIQUE KEY uq_webhook_events_tenant_provider (tenant_id, provider_event_id),
    ADD KEY idx_webhook_events_tenant_status_received (tenant_id, processing_status, received_at),
    ADD CONSTRAINT fk_webhook_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE webhook_inbox
    ADD COLUMN tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001' AFTER id,
    DROP INDEX uq_webhook_inbox_payload,
    ADD UNIQUE KEY uq_webhook_inbox_tenant_payload (tenant_id, payload_sha256),
    ADD KEY idx_webhook_inbox_tenant_due (tenant_id, status, available_at, received_at),
    ADD CONSTRAINT fk_webhook_inbox_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE meta_signup_states
    ADD COLUMN tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001' AFTER id,
    ADD KEY idx_meta_signup_tenant_expiry (tenant_id, expires_at, used_at),
    ADD CONSTRAINT fk_meta_signup_states_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE meta_connections
    ADD COLUMN tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001' AFTER id,
    ADD KEY idx_meta_connections_tenant_status (tenant_id, status, updated_at),
    ADD CONSTRAINT fk_meta_connections_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;

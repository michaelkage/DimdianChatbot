-- Tenant ownership for provider-facing persistence.
-- Existing single-tenant rows are assigned to the deterministic legacy tenant.
-- Runtime repositories continue to use that tenant until tenant context is threaded
-- through the webhook, Meta signup and worker paths in subsequent migrations.
ALTER TABLE guest_consent_events
    ADD COLUMN tenant_id UUID NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001';
ALTER TABLE guest_consent_events
    DROP CONSTRAINT guest_consent_events_guest_id_fkey,
    ADD CONSTRAINT fk_consent_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_consent_guest FOREIGN KEY (tenant_id, guest_id) REFERENCES guests(tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
CREATE INDEX idx_consent_tenant_guest_created
    ON guest_consent_events(tenant_id, guest_id, created_at);
CREATE INDEX idx_consent_tenant_channel_action
    ON guest_consent_events(tenant_id, channel, action, created_at);

ALTER TABLE whatsapp_identity_links
    ADD COLUMN tenant_id UUID NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001';
ALTER TABLE whatsapp_identity_links
    DROP CONSTRAINT whatsapp_identity_links_guest_id_fkey,
    DROP CONSTRAINT whatsapp_identity_links_conversation_id_fkey,
    DROP CONSTRAINT whatsapp_identity_links_active_phone_key,
    ADD CONSTRAINT uq_whatsapp_active_phone UNIQUE (tenant_id, active_phone),
    ADD CONSTRAINT fk_whatsapp_identity_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_whatsapp_link_guest FOREIGN KEY (tenant_id, guest_id) REFERENCES guests(tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_whatsapp_link_conversation FOREIGN KEY (tenant_id, conversation_id) REFERENCES conversations(tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
CREATE INDEX idx_whatsapp_tenant_conversation
    ON whatsapp_identity_links(tenant_id, conversation_id, is_active);

ALTER TABLE webhook_events
    ADD COLUMN tenant_id UUID NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001';
ALTER TABLE webhook_events
    DROP CONSTRAINT webhook_events_provider_event_id_key,
    ADD CONSTRAINT uq_webhook_events_tenant_provider UNIQUE (tenant_id, provider_event_id),
    ADD CONSTRAINT fk_webhook_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
CREATE INDEX idx_webhook_events_tenant_status_received
    ON webhook_events(tenant_id, processing_status, received_at);

ALTER TABLE webhook_inbox
    ADD COLUMN tenant_id UUID NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001';
ALTER TABLE webhook_inbox
    DROP CONSTRAINT webhook_inbox_payload_sha256_key,
    ADD CONSTRAINT uq_webhook_inbox_tenant_payload UNIQUE (tenant_id, payload_sha256),
    ADD CONSTRAINT fk_webhook_inbox_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
CREATE INDEX idx_webhook_inbox_tenant_due
    ON webhook_inbox(tenant_id, status, available_at, received_at);

ALTER TABLE meta_signup_states
    ADD COLUMN tenant_id UUID NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    ADD CONSTRAINT fk_meta_signup_states_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
CREATE INDEX idx_meta_signup_tenant_expiry
    ON meta_signup_states(tenant_id, expires_at, used_at);

ALTER TABLE meta_connections
    ADD COLUMN tenant_id UUID NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    ADD CONSTRAINT fk_meta_connections_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
CREATE INDEX idx_meta_connections_tenant_status
    ON meta_connections(tenant_id, status, updated_at);

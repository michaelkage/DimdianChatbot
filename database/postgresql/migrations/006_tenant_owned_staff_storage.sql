-- Tenant ownership and composite referential integrity for optional staff inbox tables.
-- Apply after migrations 005 and importing database/postgresql/optional/staff-inbox.sql.
-- Existing rows are assigned to the legacy default tenant. Repository SQL remains
-- installation-wide until the follow-up tenant-context enforcement change.

ALTER TABLE staff_identities
    ADD COLUMN tenant_id UUID NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    DROP CONSTRAINT staff_identities_auth_provider_external_subject_key,
    ADD CONSTRAINT uq_staff_tenant_id UNIQUE (tenant_id, id),
    ADD CONSTRAINT uq_staff_tenant_external_identity UNIQUE (tenant_id, auth_provider, external_subject),
    ADD CONSTRAINT fk_staff_identity_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
CREATE INDEX idx_staff_tenant_status_role ON staff_identities(tenant_id, status, role);

ALTER TABLE staff_conversation_assignments
    ADD COLUMN tenant_id UUID NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    DROP CONSTRAINT staff_conversation_assignments_conversation_id_fkey,
    DROP CONSTRAINT staff_conversation_assignments_staff_identity_id_fkey,
    DROP CONSTRAINT staff_conversation_assignments_assigned_by_staff_id_fkey,
    DROP CONSTRAINT staff_conversation_assignments_active_conversation_id_key,
    ADD CONSTRAINT uq_staff_active_conversation UNIQUE (tenant_id, active_conversation_id),
    ADD CONSTRAINT fk_staff_assignment_conversation_tenant
        FOREIGN KEY (tenant_id, conversation_id) REFERENCES conversations (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_staff_assignment_identity_tenant
        FOREIGN KEY (tenant_id, staff_identity_id) REFERENCES staff_identities (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_staff_assignment_actor_tenant
        FOREIGN KEY (tenant_id, assigned_by_staff_id) REFERENCES staff_identities (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_staff_assignment_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
CREATE INDEX idx_staff_assignment_tenant_conversation ON staff_conversation_assignments(tenant_id, conversation_id, assigned_at);

ALTER TABLE staff_internal_notes
    ADD COLUMN tenant_id UUID NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    DROP CONSTRAINT staff_internal_notes_conversation_id_fkey,
    DROP CONSTRAINT staff_internal_notes_author_staff_id_fkey,
    ADD CONSTRAINT fk_staff_note_conversation_tenant
        FOREIGN KEY (tenant_id, conversation_id) REFERENCES conversations (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_staff_note_author_tenant
        FOREIGN KEY (tenant_id, author_staff_id) REFERENCES staff_identities (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_staff_note_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
CREATE INDEX idx_staff_notes_tenant_conversation ON staff_internal_notes(tenant_id, conversation_id, created_at, id);

ALTER TABLE staff_message_authors
    ADD COLUMN tenant_id UUID NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    DROP CONSTRAINT staff_message_authors_message_id_fkey,
    DROP CONSTRAINT staff_message_authors_staff_identity_id_fkey,
    ADD CONSTRAINT fk_staff_message_author_message_tenant
        FOREIGN KEY (tenant_id, message_id) REFERENCES messages (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_staff_message_author_identity_tenant
        FOREIGN KEY (tenant_id, staff_identity_id) REFERENCES staff_identities (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_staff_message_author_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
CREATE INDEX idx_staff_message_author_tenant ON staff_message_authors(tenant_id, staff_identity_id, message_id);

ALTER TABLE staff_audit_events
    ADD COLUMN tenant_id UUID NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    DROP CONSTRAINT staff_audit_events_actor_staff_id_fkey,
    DROP CONSTRAINT staff_audit_events_conversation_id_fkey,
    DROP CONSTRAINT staff_audit_events_target_staff_id_fkey,
    ADD CONSTRAINT fk_staff_audit_actor_tenant
        FOREIGN KEY (tenant_id, actor_staff_id) REFERENCES staff_identities (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_staff_audit_conversation_tenant
        FOREIGN KEY (tenant_id, conversation_id) REFERENCES conversations (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_staff_audit_target_tenant
        FOREIGN KEY (tenant_id, target_staff_id) REFERENCES staff_identities (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_staff_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
CREATE INDEX idx_staff_audit_tenant_conversation ON staff_audit_events(tenant_id, conversation_id, id);
CREATE INDEX idx_staff_audit_tenant_actor ON staff_audit_events(tenant_id, actor_staff_id, id);

ALTER TABLE staff_auth_nonces
    ADD COLUMN tenant_id UUID NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001',
    DROP CONSTRAINT staff_auth_nonces_pkey,
    ADD CONSTRAINT staff_auth_nonces_pkey PRIMARY KEY (tenant_id, nonce_sha256),
    ADD CONSTRAINT fk_staff_auth_nonce_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
CREATE INDEX idx_staff_auth_nonce_tenant_expiry ON staff_auth_nonces(tenant_id, expires_at);

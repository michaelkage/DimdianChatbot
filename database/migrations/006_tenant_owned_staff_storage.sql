-- Tenant ownership and composite referential integrity for optional staff inbox tables.
-- Apply only after migrations 005 and after importing database/optional/staff-inbox.sql.
-- Existing rows are assigned to the legacy default tenant. This migration does NOT
-- make staff repository queries tenant-isolated; shared-database mode remains disabled.

ALTER TABLE staff_identities
    ADD COLUMN tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001' AFTER id,
    DROP INDEX uq_staff_external_identity,
    ADD UNIQUE KEY uq_staff_tenant_id (tenant_id, id),
    ADD UNIQUE KEY uq_staff_tenant_external_identity (tenant_id, auth_provider, external_subject),
    ADD KEY idx_staff_tenant_status_role (tenant_id, status, role),
    ADD CONSTRAINT fk_staff_identity_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE staff_conversation_assignments
    ADD COLUMN tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001' AFTER id,
    DROP FOREIGN KEY fk_staff_assignment_conversation,
    DROP FOREIGN KEY fk_staff_assignment_identity,
    DROP FOREIGN KEY fk_staff_assignment_actor,
    DROP INDEX uq_staff_active_conversation,
    ADD UNIQUE KEY uq_staff_active_conversation (tenant_id, active_conversation_id),
    ADD KEY idx_staff_assignment_tenant_conversation (tenant_id, conversation_id, assigned_at),
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

ALTER TABLE staff_internal_notes
    ADD COLUMN tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001' AFTER id,
    DROP FOREIGN KEY fk_staff_note_conversation,
    DROP FOREIGN KEY fk_staff_note_author,
    ADD KEY idx_staff_notes_tenant_conversation (tenant_id, conversation_id, created_at, id),
    ADD CONSTRAINT fk_staff_note_conversation_tenant
        FOREIGN KEY (tenant_id, conversation_id) REFERENCES conversations (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_staff_note_author_tenant
        FOREIGN KEY (tenant_id, author_staff_id) REFERENCES staff_identities (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_staff_note_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE staff_message_authors
    ADD COLUMN tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001' AFTER message_id,
    DROP FOREIGN KEY fk_staff_message_author_message,
    DROP FOREIGN KEY fk_staff_message_author_identity,
    ADD KEY idx_staff_message_author_tenant (tenant_id, staff_identity_id, message_id),
    ADD CONSTRAINT fk_staff_message_author_message_tenant
        FOREIGN KEY (tenant_id, message_id) REFERENCES messages (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_staff_message_author_identity_tenant
        FOREIGN KEY (tenant_id, staff_identity_id) REFERENCES staff_identities (tenant_id, id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT fk_staff_message_author_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE staff_audit_events
    ADD COLUMN tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001' AFTER id,
    DROP FOREIGN KEY fk_staff_audit_actor,
    DROP FOREIGN KEY fk_staff_audit_conversation,
    DROP FOREIGN KEY fk_staff_audit_target,
    ADD KEY idx_staff_audit_tenant_conversation (tenant_id, conversation_id, id),
    ADD KEY idx_staff_audit_tenant_actor (tenant_id, actor_staff_id, id),
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

ALTER TABLE staff_auth_nonces
    ADD COLUMN tenant_id CHAR(36) NOT NULL DEFAULT '00000000-0000-4000-8000-000000000001' FIRST,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (tenant_id, nonce_sha256),
    ADD KEY idx_staff_auth_nonce_tenant_expiry (tenant_id, expires_at),
    ADD CONSTRAINT fk_staff_auth_nonce_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE RESTRICT ON UPDATE CASCADE;

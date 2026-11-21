-- Optional Staff Inbox persistence for a single-business ChatBridge installation.
-- Import this file only when staff_inbox.enabled is deliberately enabled.
-- The default WhatsApp-first deployment does not need these tables.
-- Tenant isolation is currently provided by one database/deployment per business.
-- This schema is NOT sufficient for a shared multi-tenant database.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS staff_identities (
    id CHAR(36) NOT NULL,
    auth_provider VARCHAR(40) NOT NULL,
    external_subject VARCHAR(191) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    email VARCHAR(254) NULL,
    role ENUM('owner', 'admin', 'agent') NOT NULL DEFAULT 'agent',
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_external_identity (auth_provider, external_subject),
    KEY idx_staff_status_role (status, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_conversation_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id CHAR(36) NOT NULL,
    staff_identity_id CHAR(36) NOT NULL,
    assigned_by_staff_id CHAR(36) NOT NULL,
    assigned_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    active_conversation_id CHAR(36)
        GENERATED ALWAYS AS (CASE WHEN ended_at IS NULL THEN conversation_id ELSE NULL END) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_active_conversation (active_conversation_id),
    KEY idx_staff_assignment_agent (staff_identity_id, ended_at, assigned_at),
    KEY idx_staff_assignment_history (conversation_id, assigned_at),
    CONSTRAINT fk_staff_assignment_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_staff_assignment_identity
        FOREIGN KEY (staff_identity_id) REFERENCES staff_identities (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_staff_assignment_actor
        FOREIGN KEY (assigned_by_staff_id) REFERENCES staff_identities (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notes are a separate private resource. They are never inserted into messages
-- and therefore cannot be returned by the visitor message-polling endpoint.
CREATE TABLE IF NOT EXISTS staff_internal_notes (
    sequence_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id CHAR(36) NOT NULL,
    conversation_id CHAR(36) NOT NULL,
    author_staff_id CHAR(36) NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_note_sequence (sequence_id),
    KEY idx_staff_notes_conversation (conversation_id, created_at, id),
    CONSTRAINT fk_staff_note_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_staff_note_author
        FOREIGN KEY (author_staff_id) REFERENCES staff_identities (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff replies are ordinary business messages visible to the visitor.
-- This link records which authenticated staff identity authored each reply.
CREATE TABLE IF NOT EXISTS staff_message_authors (
    message_id CHAR(36) NOT NULL,
    staff_identity_id CHAR(36) NOT NULL,
    PRIMARY KEY (message_id),
    KEY idx_staff_message_author (staff_identity_id, message_id),
    CONSTRAINT fk_staff_message_author_message
        FOREIGN KEY (message_id) REFERENCES messages (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_staff_message_author_identity
        FOREIGN KEY (staff_identity_id) REFERENCES staff_identities (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only by application contract. Metadata must never contain message or note bodies.
CREATE TABLE IF NOT EXISTS staff_audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_staff_id CHAR(36) NOT NULL,
    conversation_id CHAR(36) NULL,
    event_type VARCHAR(64) NOT NULL,
    target_staff_id CHAR(36) NULL,
    metadata_json TEXT NULL,
    request_id VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_staff_audit_conversation (conversation_id, id),
    KEY idx_staff_audit_actor (actor_staff_id, id),
    KEY idx_staff_audit_type_created (event_type, created_at),
    CONSTRAINT fk_staff_audit_actor
        FOREIGN KEY (actor_staff_id) REFERENCES staff_identities (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_staff_audit_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_staff_audit_target
        FOREIGN KEY (target_staff_id) REFERENCES staff_identities (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

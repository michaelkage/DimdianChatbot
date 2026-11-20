-- Tenant-scoped AI configuration, knowledge and human handover.
-- Apply only after migration 004_multi_tenant_foundation.sql.

CREATE TABLE tenant_ai_settings (
    tenant_id CHAR(36) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    provider VARCHAR(60) NOT NULL DEFAULT 'openai_compatible',
    model VARCHAR(120) NOT NULL DEFAULT '',
    base_url VARCHAR(500) NOT NULL DEFAULT '',
    api_key_ciphertext MEDIUMTEXT NULL,
    api_key_key_version VARCHAR(32) NULL,
    max_output_tokens INT UNSIGNED NOT NULL DEFAULT 500,
    monthly_token_budget BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_tenant_ai_settings_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE knowledge_sources (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    source_type ENUM('faq', 'policy', 'product', 'page', 'document') NOT NULL DEFAULT 'faq',
    title VARCHAR(240) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    content_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_knowledge_tenant_hash (tenant_id, content_sha256),
    KEY idx_knowledge_tenant_status (tenant_id, status, updated_at),
    FULLTEXT KEY ft_knowledge_title_content (title, content),
    CONSTRAINT fk_knowledge_sources_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_jobs (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    conversation_id CHAR(36) NOT NULL,
    source_message_id CHAR(36) NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    locked_at DATETIME NULL,
    lock_token CHAR(36) NULL,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ai_job_source_message (tenant_id, source_message_id),
    KEY idx_ai_jobs_due (tenant_id, status, available_at, created_at),
    CONSTRAINT fk_ai_jobs_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_ai_jobs_tenant_conversation FOREIGN KEY (tenant_id, conversation_id)
        REFERENCES conversations (tenant_id, id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_ai_jobs_tenant_message FOREIGN KEY (tenant_id, source_message_id)
        REFERENCES messages (tenant_id, id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conversation_handoffs (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    conversation_id CHAR(36) NOT NULL,
    reason ENUM('visitor_requested', 'low_knowledge', 'ai_error', 'sensitive_topic', 'staff_requested', 'budget_exhausted') NOT NULL,
    status ENUM('open', 'assigned', 'resolved', 'cancelled') NOT NULL DEFAULT 'open',
    assigned_to VARCHAR(191) NULL,
    created_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    active_conversation_id CHAR(36) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_handoffs_one_active (tenant_id, active_conversation_id),
    KEY idx_handoffs_tenant_status (tenant_id, status, created_at),
    KEY idx_handoffs_tenant_conversation (tenant_id, conversation_id, status),
    CONSTRAINT fk_handoffs_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_handoffs_conversation FOREIGN KEY (conversation_id)
        REFERENCES conversations (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_usage_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id CHAR(36) NOT NULL,
    provider VARCHAR(60) NOT NULL,
    model VARCHAR(120) NOT NULL,
    input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ai_usage_tenant_period (tenant_id, created_at),
    CONSTRAINT fk_ai_usage_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


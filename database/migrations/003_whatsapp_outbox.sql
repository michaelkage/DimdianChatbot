-- Durable WhatsApp notification outbox and guest phone correlation.
-- Apply once after the original schema and migration 002.
ALTER TABLE guests
    ADD COLUMN phone_normalized VARCHAR(32) NULL AFTER phone,
    ADD COLUMN whatsapp_opt_in_at DATETIME NULL AFTER phone_normalized,
    ADD KEY idx_guests_phone_normalized (phone_normalized);

ALTER TABLE messages
    MODIFY COLUMN delivery_status ENUM('queued', 'sent', 'delivered', 'read', 'failed', 'received', 'web_only') NOT NULL DEFAULT 'received';

CREATE TABLE message_outbox (
    id CHAR(36) NOT NULL,
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
    UNIQUE KEY uq_message_outbox_message (message_id),
    KEY idx_message_outbox_due (status, available_at, created_at),
    KEY idx_message_outbox_lock (status, locked_at),
    CONSTRAINT fk_message_outbox_message
        FOREIGN KEY (message_id) REFERENCES messages (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

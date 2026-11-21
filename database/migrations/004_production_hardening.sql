-- Dimdian production hardening: consent, exact identity links, durable webhook inbox and Meta connection storage.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS guest_consent_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, guest_id CHAR(36) NOT NULL,
 channel VARCHAR(24) NOT NULL, action ENUM('opt_in','opt_out') NOT NULL,
 source VARCHAR(40) NOT NULL, policy_version VARCHAR(32) NOT NULL DEFAULT '2026-11',
 created_at DATETIME NOT NULL, PRIMARY KEY(id),
 KEY idx_consent_guest_created(guest_id,created_at), KEY idx_consent_channel_action(channel,action,created_at),
 CONSTRAINT fk_consent_guest FOREIGN KEY(guest_id) REFERENCES guests(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_identity_links (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, phone_normalized VARCHAR(32) NOT NULL,
 guest_id CHAR(36) NOT NULL, conversation_id CHAR(36) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
 active_phone VARCHAR(32) GENERATED ALWAYS AS (CASE WHEN is_active=1 THEN phone_normalized ELSE NULL END) STORED,
 created_at DATETIME NOT NULL, deactivated_at DATETIME NULL, PRIMARY KEY(id),
 UNIQUE KEY uq_whatsapp_active_phone(active_phone), KEY idx_whatsapp_link_conversation(conversation_id,is_active),
 CONSTRAINT fk_whatsapp_link_guest FOREIGN KEY(guest_id) REFERENCES guests(id) ON DELETE RESTRICT ON UPDATE CASCADE,
 CONSTRAINT fk_whatsapp_link_conversation FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_inbox (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_ciphertext LONGBLOB NOT NULL, payload_nonce VARBINARY(12) NOT NULL, payload_tag VARBINARY(16) NOT NULL,
 status ENUM('pending','processing','processed','failed') NOT NULL DEFAULT 'pending',
 attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0, available_at DATETIME NOT NULL, locked_at DATETIME NULL,
 lock_token CHAR(36) NULL, last_error VARCHAR(500) NULL, received_at DATETIME NOT NULL, processed_at DATETIME NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_webhook_inbox_payload(payload_sha256),
 KEY idx_webhook_inbox_due(status,available_at,received_at), KEY idx_webhook_inbox_lock(status,locked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meta_signup_states (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, state_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id),
 UNIQUE KEY uq_meta_signup_state(state_sha256), KEY idx_meta_signup_expiry(expires_at,used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meta_connections (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, waba_id VARCHAR(80) NOT NULL, phone_number_id VARCHAR(80) NOT NULL,
 access_token_ciphertext LONGBLOB NOT NULL, access_token_nonce VARBINARY(12) NOT NULL, access_token_tag VARBINARY(16) NOT NULL,
 token_expires_at DATETIME NULL, status ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
 connected_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id),
 UNIQUE KEY uq_meta_phone_number(phone_number_id), KEY idx_meta_connection_status(status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve the last known consent timestamp for existing opted-in guests.
INSERT INTO guest_consent_events(guest_id,channel,action,source,policy_version,created_at)
SELECT g.id,'whatsapp','opt_in','legacy_import','legacy',g.whatsapp_opt_in_at
FROM guests g
WHERE g.whatsapp_opt_in_at IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM guest_consent_events e
      WHERE e.guest_id = g.id AND e.channel = 'whatsapp'
        AND e.action = 'opt_in' AND e.source = 'legacy_import'
  );

-- Backfill one deterministic active conversation per opted-in phone number.
INSERT IGNORE INTO whatsapp_identity_links(phone_normalized,guest_id,conversation_id,is_active,created_at)
SELECT g.phone_normalized,g.id,c.id,1,UTC_TIMESTAMP()
FROM guests g INNER JOIN conversations c ON c.guest_id=g.id
WHERE g.whatsapp_opt_in_at IS NOT NULL AND g.phone_normalized IS NOT NULL AND g.phone_normalized<>'' AND c.status='open'
AND NOT EXISTS (
 SELECT 1 FROM guests newer_guest
 INNER JOIN conversations newer_conversation ON newer_conversation.guest_id=newer_guest.id
 WHERE newer_guest.phone_normalized=g.phone_normalized AND newer_guest.whatsapp_opt_in_at IS NOT NULL
 AND newer_conversation.status='open'
 AND (newer_conversation.updated_at>c.updated_at OR (newer_conversation.updated_at=c.updated_at AND newer_conversation.id>c.id))
);

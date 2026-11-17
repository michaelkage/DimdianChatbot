-- DimdianChatbot initial schema
-- Target: MySQL 8.0+ / MariaDB 10.5+ with InnoDB and utf8mb4.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS staff_users (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 email VARCHAR(254) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 display_name VARCHAR(120) NOT NULL,
 `role` ENUM('admin','agent') NOT NULL DEFAULT 'agent',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 last_login_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id), UNIQUE KEY uq_staff_email (email), KEY idx_staff_active_role (is_active,`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 guest_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 external_user_id VARCHAR(191) NULL,
 display_name VARCHAR(120) NULL,
 email VARCHAR(254) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id), UNIQUE KEY uq_customer_guest_token_hash (guest_token_hash),
 KEY idx_customer_external_user (external_user_id), KEY idx_customer_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 customer_id BIGINT UNSIGNED NOT NULL,
 status ENUM('open','needs_human','closed') NOT NULL DEFAULT 'open',
 subject VARCHAR(180) NULL,
 context_url VARCHAR(2048) NULL,
 context_sku VARCHAR(120) NULL,
 assigned_staff_id BIGINT UNSIGNED NULL,
 customer_last_read_message_id BIGINT UNSIGNED NULL,
 staff_last_read_message_id BIGINT UNSIGNED NULL,
 last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id), UNIQUE KEY uq_conversation_public_id (public_id),
 KEY idx_conversation_queue (status,last_message_at),
 KEY idx_conversation_customer (customer_id,last_message_at),
 KEY idx_conversation_assignee (assigned_staff_id,status),
 CONSTRAINT fk_conversation_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
 CONSTRAINT fk_conversation_assignee FOREIGN KEY (assigned_staff_id) REFERENCES staff_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 conversation_id BIGINT UNSIGNED NOT NULL,
 sender_type ENUM('customer','staff','assistant','system') NOT NULL,
 sender_id BIGINT UNSIGNED NULL,
 body TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id), KEY idx_message_conversation_id (conversation_id,id), KEY idx_message_created_at (created_at),
 CONSTRAINT fk_message_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 message_id BIGINT UNSIGNED NOT NULL,
 storage_key VARCHAR(255) NOT NULL,
 original_name VARCHAR(255) NOT NULL,
 mime_type VARCHAR(100) NOT NULL,
 size_bytes INT UNSIGNED NOT NULL,
 sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id), UNIQUE KEY uq_attachment_storage_key (storage_key), KEY idx_attachment_message (message_id),
 CONSTRAINT fk_attachment_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limit_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id), KEY idx_rate_limit_bucket_time (bucket_key,occurred_at), KEY idx_rate_limit_occurred_at (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Small backend-managed settings table. AI is deliberately disabled until an administrator opts in.
CREATE TABLE IF NOT EXISTS app_settings (
 setting_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 setting_value VARCHAR(255) NOT NULL,
 updated_by BIGINT UNSIGNED NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (setting_key),
 CONSTRAINT fk_app_settings_updated_by FOREIGN KEY (updated_by) REFERENCES staff_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key,setting_value)
VALUES ('ai_enabled','0')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

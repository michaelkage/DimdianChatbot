-- Additive migration: live catalogue integration, structured vehicle details and rate-limit buckets.
-- Safe to run after database/schema.sql; all objects are idempotent.
CREATE TABLE IF NOT EXISTS rate_limit_buckets (
 bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 window_start BIGINT UNSIGNED NOT NULL,
 hit_count INT UNSIGNED NOT NULL DEFAULT 0,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (bucket_key,window_start),
 KEY idx_rate_limit_bucket_cleanup (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversation_vehicles (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 conversation_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NULL,
 make VARCHAR(100) NOT NULL,
 model VARCHAR(120) NOT NULL,
 model_year SMALLINT UNSIGNED NOT NULL,
 engine VARCHAR(120) NULL,
 transmission VARCHAR(100) NULL,
 trim_level VARCHAR(120) NULL,
 vin_ciphertext TEXT NULL,
 vin_last4 CHAR(4) CHARACTER SET ascii COLLATE ascii_bin NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 KEY idx_vehicle_conversation (conversation_id,id),
 KEY idx_vehicle_product (product_id),
 CONSTRAINT fk_vehicle_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_fitments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 product_id BIGINT UNSIGNED NOT NULL,
 make VARCHAR(100) NOT NULL,
 model VARCHAR(120) NOT NULL,
 year_from SMALLINT UNSIGNED NOT NULL,
 year_to SMALLINT UNSIGNED NOT NULL,
 engine VARCHAR(120) NOT NULL,
 transmission VARCHAR(100) NOT NULL,
 trim_level VARCHAR(120) NULL,
 verification_source VARCHAR(255) NOT NULL,
 verified_by BIGINT UNSIGNED NULL,
 verified_at DATETIME NOT NULL,
 status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 KEY idx_fitment_lookup (product_id,status,make,model,year_from,year_to),
 CONSTRAINT fk_fitment_verified_by FOREIGN KEY (verified_by) REFERENCES staff_users(id) ON DELETE SET NULL,
 CONSTRAINT chk_fitment_year_range CHECK (year_from <= year_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A shopper can explicitly select a live search result as the subject of a vehicle check.
-- One current product context per conversation; vehicle submissions copy the verified ID.
CREATE TABLE IF NOT EXISTS conversation_product_context (
 conversation_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 sku VARCHAR(120) NULL,
 product_name VARCHAR(255) NOT NULL,
 product_permalink VARCHAR(2048) NOT NULL,
 selected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (conversation_id),
 KEY idx_conversation_product_context_product (product_id),
 CONSTRAINT fk_product_context_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

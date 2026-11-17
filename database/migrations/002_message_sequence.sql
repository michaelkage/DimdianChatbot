-- Upgrade databases created from the first schema revision.
-- Adds a stable, monotonically increasing cursor for efficient polling.
-- Run once. Fresh installs should use the updated database/schema.sql instead.

ALTER TABLE messages
    ADD COLUMN sequence_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT AFTER id,
    ADD UNIQUE KEY uq_messages_sequence_id (sequence_id);

ALTER TABLE messages
    DROP INDEX idx_messages_conversation_order,
    ADD KEY idx_messages_conversation_order (conversation_id, sequence_id);

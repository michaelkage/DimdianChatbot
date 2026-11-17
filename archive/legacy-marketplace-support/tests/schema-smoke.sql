-- Disposable CI smoke test. Runs only against the workflow's empty MySQL database.
INSERT INTO staff_users (email,password_hash,display_name,`role`) VALUES ('ci@example.invalid','test-hash','CI Agent','admin');
INSERT INTO customers (guest_token_hash,created_at,last_seen_at) VALUES (REPEAT('a',64),UTC_TIMESTAMP(),UTC_TIMESTAMP());
INSERT INTO conversations (public_id,customer_id,status,context_url,context_sku,last_message_at)
 SELECT REPEAT('b',26),id,'open','https://parts.example.invalid/item/123','SKU-CI',UTC_TIMESTAMP()
 FROM customers WHERE guest_token_hash=REPEAT('a',64);
INSERT INTO messages (conversation_id,sender_type,body,created_at)
 SELECT id,'customer','Schema smoke test',UTC_TIMESTAMP() FROM conversations WHERE public_id=REPEAT('b',26);
INSERT INTO conversation_product_context (conversation_id,product_id,sku,product_name,product_permalink,selected_at)
 SELECT id,42,'SKU-CI','CI test part','https://parts.example.invalid/item/123',UTC_TIMESTAMP()
 FROM conversations WHERE public_id=REPEAT('b',26);
INSERT INTO attachments (message_id,storage_key,original_name,mime_type,size_bytes,sha256)
 SELECT id,'ci-test.jpg','ci-test.jpg','image/jpeg',1,REPEAT('c',64) FROM messages WHERE body='Schema smoke test';
INSERT INTO rate_limit_events (bucket_key,occurred_at) VALUES (REPEAT('d',64),UTC_TIMESTAMP());

-- Exercise the queue query shape and customer delta-message lookup.
SELECT c.public_id,c.status,cu.external_user_id,
 (SELECT m.body FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
 (SELECT m.sender_type FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_sender_type,
 (SELECT MAX(m.id) FROM messages m WHERE m.conversation_id=c.id) AS last_message_id,
 (SELECT COUNT(*) FROM messages m WHERE m.conversation_id=c.id AND m.sender_type='customer' AND m.id>COALESCE(c.staff_last_read_message_id,0)) AS unread_count
FROM conversations c INNER JOIN customers cu ON cu.id=c.customer_id
WHERE c.public_id=REPEAT('b',26);

SELECT m.id,m.sender_type,m.body,m.created_at,a.id AS attachment_id,a.storage_key,a.original_name,a.mime_type,a.size_bytes
FROM messages m LEFT JOIN attachments a ON a.message_id=m.id
WHERE m.conversation_id=(SELECT id FROM conversations WHERE public_id=REPEAT('b',26)) AND m.id>0
ORDER BY m.id ASC LIMIT 100;

-- Exercise the per-customer conversation lock query used by the API.
START TRANSACTION;
SELECT id FROM customers WHERE guest_token_hash=REPEAT('a',64) FOR UPDATE;
SELECT id,public_id,status FROM conversations
WHERE customer_id=(SELECT id FROM customers WHERE guest_token_hash=REPEAT('a',64))
AND status IN ('open','needs_human') ORDER BY last_message_at DESC LIMIT 1 FOR UPDATE;
ROLLBACK;

DELETE FROM conversations WHERE public_id=REPEAT('b',26);
DELETE FROM customers WHERE guest_token_hash=REPEAT('a',64);
DELETE FROM staff_users WHERE email='ci@example.invalid';
DELETE FROM rate_limit_events WHERE bucket_key=REPEAT('d',64);

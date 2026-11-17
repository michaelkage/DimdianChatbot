<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/woocommerce.php';
require_once dirname(__DIR__).'/app/ai_provider.php';
require_once dirname(__DIR__).'/app/ai.php';
require_once dirname(__DIR__).'/app/vehicle.php';
require_once dirname(__DIR__).'/app/storage.php';

function check(bool $ok,string $message): void {
 if(!$ok){fwrite(STDERR,"FAIL: ".$message."\n");exit(1);}
 echo "PASS: ".$message."\n";
}

check(woocommerce_ready(),'catalogue adapter is configured against the isolated read-only WooCommerce fixture');
$privateDirectory=private_upload_directory(false);
check($privateDirectory!==null&&((fileperms($privateDirectory)&0077)===0),'attachment directory is private and not group/world accessible');
$storedKey=db()->query('SELECT storage_key FROM attachments ORDER BY id DESC LIMIT 1')->fetchColumn();
$storedPath=is_string($storedKey)?private_attachment_path($storedKey):null;
check($storedPath!==null&&((fileperms($storedPath)&0077)===0),'stored attachment files are owner-only');
$providerFingerprint=ai_provider_fingerprint();
$originalModel=$GLOBALS['config']['ai']['provider']['model'];
$GLOBALS['config']['ai']['provider']['model']=$originalModel.'-changed';
check($providerFingerprint!==ai_provider_fingerprint(),'changing the provider model invalidates its successful-test fingerprint');
$GLOBALS['config']['ai']['provider']['model']=$originalModel;
check($providerFingerprint===ai_provider_fingerprint(),'restoring the tested provider model restores its fingerprint');
$configError=ai_provider_complete([['role'=>'user','content'=>'TRIGGER_CONFIG']]);
check(($configError['kind']??'')==='configuration_error','provider model/endpoint errors are classified as configuration failures');
$originalStoreUrl=$GLOBALS['config']['woocommerce']['store_url'];
$GLOBALS['config']['woocommerce']['store_url']='https://user:secret@parts.example.invalid';
check(!woocommerce_ready(),'WooCommerce base URLs containing embedded credentials are rejected');
$GLOBALS['config']['woocommerce']['store_url']=$originalStoreUrl;
$GLOBALS['config']['app']['environment']='production';
check(!woocommerce_ready(),'production WooCommerce connections reject plaintext HTTP');
$GLOBALS['config']['app']['environment']='test';
$product=woocommerce_normalize_product([
 'id'=>42,'name'=>'Test Alternator','short_description'=>'<p>Premium <strong>test</strong> alternator</p>','sku'=>'ALT-42','mpn'=>'DENSO-42','price'=>'12500.00',
 'regular_price'=>'15000.00','sale_price'=>'12500.00','stock_status'=>'instock',
 'stock_quantity'=>3,'permalink'=>'https://store.example/product/test-alternator/',
 'images'=>[['src'=>'https://store.example/image.jpg']]
]);
check($product['id']===42&&$product['sku']==='ALT-42'&&$product['mpn']==='DENSO-42'&&$product['price']==='12500.00','WooCommerce product normalization preserves live listing fields');
check($product['description']==='Premium test alternator','live product descriptions are stripped to bounded plain text before display');
check($product['stock_status']==='instock'&&$product['permalink']==='','unsafe product link is omitted when its host is not the configured store');
check(woocommerce_public_permalink('http://127.0.0.1:8083/product/alt-cam-25/')==='http://127.0.0.1:8083/product/alt-cam-25/','configured storefront product links are retained in isolated test mode');
check(woocommerce_public_permalink('https://attacker.example/phishing')==='','external product links are rejected before reaching staff or shoppers');
check(woocommerce_public_image('data:image/png;base64,AAAA')===null,'non-HTTP product image schemes are rejected');
$variableProducts=woocommerce_search_products('name','Variable Assembly');
$draftProducts=woocommerce_search_products('name','Draft Alternator');
check(count($variableProducts)===1&&$variableProducts[0]['id']===103,'published variable products remain discoverable in live search');
check($draftProducts===[],'unpublished products are excluded from public catalogue search');
check(!woocommerce_fitment_product_supported($variableProducts[0]),'variable listings cannot receive parent-level fitment claims');

$sample='TESTVIN1234567890';
$encrypted=vehicle_encrypt_vin($sample);
check($encrypted!==$sample&&vehicle_decrypt_vin($encrypted)===$sample,'vehicle identifier encryption round-trips without storing plaintext');

$fitmentProduct=random_int(100000,900000);
$fitmentInsert=db()->prepare("INSERT INTO product_fitments (product_id,make,model,year_from,year_to,engine,transmission,trim_level,verification_source,verified_at,status) VALUES (:product,'Honda','Civic',2016,2020,'2.0L','CVT','LX','CI verified fixture',UTC_TIMESTAMP(),'verified')");
$fitmentInsert->execute(['product'=>$fitmentProduct]);
$verified=vehicle_find_verified_fitment(db(),$fitmentProduct,'Honda','Civic',2018,'2.0L','CVT','LX');
$wrongEngine=vehicle_find_verified_fitment(db(),$fitmentProduct,'Honda','Civic',2018,'1.5L','CVT','LX');
$missingTrim=vehicle_find_verified_fitment(db(),$fitmentProduct,'Honda','Civic',2018,'2.0L','CVT','');
check(is_array($verified)&&$verified['verification_source']==='CI verified fixture','fitment matcher accepts an exact verified catalogue record');
check($wrongEngine===null,'fitment matcher rejects a different engine instead of guessing');
check($missingTrim===null,'fitment matcher rejects a trim-specific record when customer trim is missing');
db()->prepare('DELETE FROM product_fitments WHERE product_id=:product')->execute(['product'=>$fitmentProduct]);

$eventCount=(int)db()->query("SELECT COUNT(*) FROM messages WHERE sender_type='customer_event' AND body LIKE 'A member of our support team will take over%'")->fetchColumn();
check($eventCount>0,'unresolved vehicle submissions persist as explicit human-handoff events');
$unreadEvents=(int)db()->query("SELECT COUNT(*) FROM messages m INNER JOIN conversations c ON c.id=m.conversation_id WHERE m.sender_type='customer_event' AND m.id>COALESCE(c.staff_last_read_message_id,0)")->fetchColumn();
check($unreadEvents>0,'unresolved vehicle events remain unread until staff marks the conversation read');

$pdo=db();
$staffId=(int)$pdo->query("SELECT id FROM staff_users WHERE email='ci-admin@example.invalid' LIMIT 1")->fetchColumn();
$guestToken=bin2hex(random_bytes(32));$publicId=bin2hex(random_bytes(13));
$pdo->prepare('INSERT INTO customers (guest_token_hash,created_at,last_seen_at) VALUES (:token,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute(['token'=>hash('sha256',$guestToken)]);
$customerId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO conversations (public_id,customer_id,status,last_message_at,created_at,updated_at) VALUES (:public_id,:customer_id,'open',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute(['public_id'=>$publicId,'customer_id'=>$customerId]);
$conversationId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO messages (conversation_id,sender_type,body,created_at) VALUES (:id,'customer_event',:body,UTC_TIMESTAMP())")->execute(['id'=>$conversationId,'body'=>'A member of our support team will take over this vehicle compatibility check.']);
$pdo->prepare("INSERT INTO messages (conversation_id,sender_type,body,created_at) VALUES (:id,'customer','Thanks for the update.',UTC_TIMESTAMP())")->execute(['id'=>$conversationId]);
$pdo->prepare("INSERT INTO app_settings (setting_key,setting_value,updated_by,updated_at) VALUES ('ai_enabled','1',:staff,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value='1',updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()")->execute(['staff'=>$staffId]);
ai_provider_mark_verified($staffId);
ai_process_customer_message($conversationId);
$assistantQuery=$pdo->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id=:id AND sender_type='assistant'");$assistantQuery->execute(['id'=>$conversationId]);$assistantCount=(int)$assistantQuery->fetchColumn();
check($assistantCount===0,'a human vehicle handoff stays sticky after staff reopens the conversation');
$pdo->prepare("UPDATE app_settings SET setting_value='0',updated_at=UTC_TIMESTAMP() WHERE setting_key='ai_enabled'")->execute();
ai_provider_clear_verification($pdo);
$pdo->prepare('DELETE FROM conversations WHERE id=:id')->execute(['id'=>$conversationId]);
$pdo->prepare('DELETE FROM customers WHERE id=:id')->execute(['id'=>$customerId]);

$scope='marketplace-smoke-'.bin2hex(random_bytes(8));
check(rate_limit_exceeded($scope,1,60)===false,'first request is allowed within a fixed window');
check(rate_limit_exceeded($scope,1,60)===true,'subsequent request is rejected at the configured limit');

$tables=db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('rate_limit_buckets','conversation_vehicles','product_fitments')")->fetchColumn();
check((int)$tables===3,'marketplace and hardening migration tables are present');

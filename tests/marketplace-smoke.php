<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/woocommerce.php';
require_once dirname(__DIR__).'/app/vehicle.php';

function check(bool $ok,string $message): void {
 if(!$ok){fwrite(STDERR,"FAIL: ".$message."\n");exit(1);}
 echo "PASS: ".$message."\n";
}

check(!woocommerce_ready(),'catalogue remains disabled until private read-only credentials are configured');
$product=woocommerce_normalize_product([
 'id'=>42,'name'=>'Test Alternator','sku'=>'ALT-42','price'=>'12500.00',
 'regular_price'=>'15000.00','sale_price'=>'12500.00','stock_status'=>'instock',
 'stock_quantity'=>3,'permalink'=>'https://store.example/product/test-alternator/',
 'images'=>[['src'=>'https://store.example/image.jpg']]
]);
check($product['id']===42&&$product['sku']==='ALT-42'&&$product['price']==='12500.00','WooCommerce product normalization preserves live listing fields');
check($product['stock_status']==='instock'&&$product['permalink']==='','unsafe product link is omitted when its host is not the configured store');
check(woocommerce_public_permalink('https://parts.example.invalid/product/brake-pad/')==='https://parts.example.invalid/product/brake-pad/','canonical HTTPS storefront product links are retained');
check(woocommerce_public_permalink('https://attacker.example/phishing')==='','external product links are rejected before reaching staff or shoppers');
check(woocommerce_public_image('http://parts.example.invalid/image.jpg')===null,'non-HTTPS product images are rejected');

$sample='TESTVIN1234567890';
$encrypted=vehicle_encrypt_vin($sample);
check($encrypted!==$sample&&vehicle_decrypt_vin($encrypted)===$sample,'vehicle identifier encryption round-trips without storing plaintext');

$fitmentProduct=random_int(100000,900000);
$fitmentInsert=db()->prepare("INSERT INTO product_fitments (product_id,make,model,year_from,year_to,engine,transmission,verification_source,verified_at,status) VALUES (:product,'Honda','Civic',2016,2020,'2.0L','CVT','CI verified fixture',UTC_TIMESTAMP(),'verified')");
$fitmentInsert->execute(['product'=>$fitmentProduct]);
$verified=vehicle_find_verified_fitment(db(),$fitmentProduct,'Honda','Civic',2018,'2.0L','CVT','LX');
$wrongEngine=vehicle_find_verified_fitment(db(),$fitmentProduct,'Honda','Civic',2018,'1.5L','CVT','LX');
check(is_array($verified)&&$verified['verification_source']==='CI verified fixture','fitment matcher accepts an exact verified catalogue record');
check($wrongEngine===null,'fitment matcher rejects a different engine instead of guessing');
db()->prepare('DELETE FROM product_fitments WHERE product_id=:product')->execute(['product'=>$fitmentProduct]);

$scope='marketplace-smoke-'.bin2hex(random_bytes(8));
check(rate_limit_exceeded($scope,1,60)===false,'first request is allowed within a fixed window');
check(rate_limit_exceeded($scope,1,60)===true,'subsequent request is rejected at the configured limit');

$tables=db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('rate_limit_buckets','conversation_vehicles','product_fitments')")->fetchColumn();
check((int)$tables===3,'marketplace and hardening migration tables are present');

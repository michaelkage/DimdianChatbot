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
check($product['stock_status']==='instock'&&$product['permalink']==='https://store.example/product/test-alternator/','stock status and canonical product link are returned');

$sample='TESTVIN1234567890';
$encrypted=vehicle_encrypt_vin($sample);
check($encrypted!==$sample&&vehicle_decrypt_vin($encrypted)===$sample,'vehicle identifier encryption round-trips without storing plaintext');

$scope='marketplace-smoke-'.bin2hex(random_bytes(8));
check(rate_limit_exceeded($scope,1,60)===false,'first request is allowed within a fixed window');
check(rate_limit_exceeded($scope,1,60)===true,'subsequent request is rejected at the configured limit');

$tables=db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('rate_limit_buckets','conversation_vehicles','product_fitments')")->fetchColumn();
check((int)$tables===3,'marketplace and hardening migration tables are present');

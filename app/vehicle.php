<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/woocommerce.php';
function vehicle_encrypt_vin(string $vin): string {
 $key=(string)app_config('integration.vehicle_data_encryption_key','');
 if(strlen($key)<32||str_starts_with($key,'replace-with-'))throw new RuntimeException('vin_encryption_not_configured');
 if(!function_exists('openssl_encrypt'))throw new RuntimeException('vin_encryption_unavailable');
 $iv=random_bytes(12);$tag='';
 $cipher=openssl_encrypt($vin,'aes-256-gcm',hash('sha256',$key,true),OPENSSL_RAW_DATA,$iv,$tag);
 if($cipher===false)throw new RuntimeException('vin_encryption_failed');
 return base64_encode($iv.$tag.$cipher);
}
function vehicle_decrypt_vin(?string $payload): ?string {
 if(!$payload)return null;
 $key=(string)app_config('integration.vehicle_data_encryption_key','');
 if(strlen($key)<32)return null;
 $raw=base64_decode($payload,true);if($raw===false||strlen($raw)<29)return null;
 $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',$key,true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
 return is_string($plain)?$plain:null;
}

/** Exact-match only: a pending/rejected record or a partial vehicle description is never proof. */
function vehicle_find_verified_fitment(PDO $pdo,int $productId,string $make,string $model,int $year,string $engine,string $transmission,string $trim): ?array {
 if($productId<1||$engine===''||$transmission==='')return null;
 $query=$pdo->prepare("SELECT id,verification_source,verified_at FROM product_fitments WHERE product_id=:pid AND status='verified' AND make=:make AND model=:model AND :year BETWEEN year_from AND year_to AND engine=:engine AND transmission=:transmission AND (trim_level IS NULL OR trim_level=:trim) ORDER BY verified_at DESC LIMIT 1");
 $query->execute(['pid'=>$productId,'make'=>$make,'model'=>$model,'year'=>$year,'engine'=>$engine,'transmission'=>$transmission,'trim'=>$trim!==''?$trim:null]);
 return $query->fetch()?:null;
}

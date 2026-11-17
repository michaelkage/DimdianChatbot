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

<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

/** Resolve private attachment storage and refuse paths inside public or temporary roots. */
function private_upload_directory(bool $create=false): ?string {
 $configured=(string)app_config('uploads.directory','');
 if($configured===''||!str_starts_with($configured,DIRECTORY_SEPARATOR)||is_link($configured))return null;
 $public=realpath(dirname(__DIR__).'/public');$systemTemp=realpath(sys_get_temp_dir());
 $production=app_config('app.environment','production')==='production';
 if($public===false)return null;
 if(!is_dir($configured)){
  if(!$create)return null;
  // Never create a misconfigured upload directory under public/ or production temp storage.
  $parent=realpath(dirname($configured));
  if($parent===false||$parent===$public||str_starts_with($parent.DIRECTORY_SEPARATOR,$public.DIRECTORY_SEPARATOR)||
    ($production&&$systemTemp!==false&&($parent===$systemTemp||str_starts_with($parent.DIRECTORY_SEPARATOR,$systemTemp.DIRECTORY_SEPARATOR))))return null;
  if(!mkdir($configured,0700,false)&&!is_dir($configured))return null;
 }
 if(!is_dir($configured))return null;
 $directory=realpath($configured);
 if($directory===false||$directory===DIRECTORY_SEPARATOR||$directory===$public||
   ($production&&$systemTemp!==false&&($directory===$systemTemp||str_starts_with($directory.DIRECTORY_SEPARATOR,$systemTemp.DIRECTORY_SEPARATOR)||str_starts_with($systemTemp.DIRECTORY_SEPARATOR,$directory.DIRECTORY_SEPARATOR)))||
   str_starts_with($directory.DIRECTORY_SEPARATOR,$public.DIRECTORY_SEPARATOR)||str_starts_with($public.DIRECTORY_SEPARATOR,$directory.DIRECTORY_SEPARATOR))return null;
 @chmod($directory,0700);
 clearstatcache(true,$directory);
 $permissions=fileperms($directory);
 if($permissions===false||($permissions&0077)!==0)return null;
 return $directory;
}
function private_attachment_path(string $storageKey): ?string {
 if(!preg_match('/^[a-f0-9]{48}\\.(?:jpg|png|webp)$/D',$storageKey))return null;
 $directory=private_upload_directory(false);if($directory===null)return null;
 $path=$directory.DIRECTORY_SEPARATOR.$storageKey;
 if(is_link($path)||!is_file($path))return null;
 $real=realpath($path);
 return $real!==false&&str_starts_with($real,$directory.DIRECTORY_SEPARATOR)?$real:null;
}

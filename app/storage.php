<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

/** Resolve private attachment storage and refuse paths inside the public document root. */
function private_upload_directory(bool $create=false): ?string {
 $configured=(string)app_config('uploads.directory','');
 if($configured===''||!str_starts_with($configured,DIRECTORY_SEPARATOR)||is_link($configured))return null;
 if($create&&!is_dir($configured)&&!mkdir($configured,0700,true)&&!is_dir($configured))return null;
 if(!is_dir($configured))return null;
 $directory=realpath($configured);$public=realpath(dirname(__DIR__).'/public');
 if($directory===false||$public===false||$directory===$public||str_starts_with($directory.DIRECTORY_SEPARATOR,$public.DIRECTORY_SEPARATOR))return null;
 return $directory;
}
function private_attachment_path(string $storageKey): ?string {
 if(!preg_match('/^[a-f0-9]{48}\.(?:jpg|png|webp)$/D',$storageKey))return null;
 $directory=private_upload_directory(false);if($directory===null)return null;
 $path=$directory.DIRECTORY_SEPARATOR.$storageKey;
 if(is_link($path)||!is_file($path))return null;
 $real=realpath($path);
 return $real!==false&&str_starts_with($real,$directory.DIRECTORY_SEPARATOR)?$real:null;
}

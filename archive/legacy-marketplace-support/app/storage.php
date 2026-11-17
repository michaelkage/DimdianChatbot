<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

/** Resolve private attachment storage and refuse every configured or active web root. */
function private_upload_directory(bool $create=false): ?string {
 $configured=(string)app_config('uploads.directory','');
 if($configured===''||!str_starts_with($configured,DIRECTORY_SEPARATOR)||is_link($configured))return null;
 $appPublic=realpath(dirname(__DIR__).'/public');
 $configuredRoot=realpath((string)app_config('app.document_root',dirname(__DIR__).'/public'));
 $serverRoot=isset($_SERVER['DOCUMENT_ROOT'])&&$_SERVER['DOCUMENT_ROOT']!==''?realpath((string)$_SERVER['DOCUMENT_ROOT']):false;
 $systemTemp=realpath(sys_get_temp_dir());$production=app_config('app.environment','production')==='production';
 if($appPublic===false||$configuredRoot===false)return null;
 $webRoots=array_values(array_unique(array_filter([$appPublic,$configuredRoot,$serverRoot],static fn($root)=>is_string($root)&&$root!=='')));
 $insideOrAncestor=static fn(string $path,string $root):bool=>$path===$root||str_starts_with($path.DIRECTORY_SEPARATOR,$root.DIRECTORY_SEPARATOR)||str_starts_with($root.DIRECTORY_SEPARATOR,$path.DIRECTORY_SEPARATOR);
 if(!is_dir($configured)){
  if(!$create)return null;
  // Validate the existing parent before creating anything; never create storage in a web root.
  $parent=realpath(dirname($configured));
  if($parent===false)return null;
  foreach($webRoots as $root)if($parent===$root||str_starts_with($parent.DIRECTORY_SEPARATOR,$root.DIRECTORY_SEPARATOR))return null;
  if($production&&$systemTemp!==false&&($parent===$systemTemp||str_starts_with($parent.DIRECTORY_SEPARATOR,$systemTemp.DIRECTORY_SEPARATOR)))return null;
  if(!mkdir($configured,0700,false)&&!is_dir($configured))return null;
 }
 if(!is_dir($configured))return null;
 $directory=realpath($configured);
 if($directory===false||$directory===DIRECTORY_SEPARATOR)return null;
 foreach($webRoots as $root)if($insideOrAncestor($directory,$root))return null;
 if($production&&$systemTemp!==false&&$insideOrAncestor($directory,$systemTemp))return null;
 @chmod($directory,0700);clearstatcache(true,$directory);
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

<?php
/** Runtime metadata only: no Laravel bootstrap, environment/config dump or DB connection. */
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--metadata-only'){fwrite(STDERR,"Usage: php scripts/release_runtime_baseline.php --metadata-only\n");exit(2);}
$extensions=[];foreach(['bcmath','pdo_mysql','mbstring','openssl','fileinfo','intl','gd','zip'] as $name)$extensions[$name]=['loaded'=>extension_loaded($name),'version'=>phpversion($name)?:null];
echo json_encode(['mode'=>'RUNTIME_METADATA_ONLY','writes_performed'=>false,'php_version'=>PHP_VERSION,'php_os_family'=>PHP_OS_FAMILY,'php_int_size'=>PHP_INT_SIZE,'extensions'=>$extensions,'pdo_drivers'=>class_exists(PDO::class)?PDO::getAvailableDrivers():[]],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;

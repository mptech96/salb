<?php
/** Read-only prerequisite inventory; no database access, env/config dumps or installers. */
function stagingResources(string $meminfo,int|float|false $free):array {
    preg_match('/^MemAvailable:\s+(\d+)\s+kB$/m',$meminfo,$m);
    $kb=isset($m[1])?(int)$m[1]:null;
    return ['mem_available_kib'=>$kb,'free_disk_bytes'=>$free===false?null:(int)$free,
      'existing_harness_resource_minimum_met'=>$kb!==null&&$kb>=1572864&&$free!==false&&$free>=2147483648];
}
function stagingExecutable(string $name):?string {
    foreach(explode(PATH_SEPARATOR,getenv('PATH')?:'')as$dir){if($dir===''||$dir==='.')continue;$file=$dir.DIRECTORY_SEPARATOR.$name;if(is_file($file)&&is_executable($file))return $file;}
    return null;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__)return;
try{
    $composer=stagingExecutable('composer');$version=null;
    if($composer){
        $env=getenv();$env['COMPOSER_DISABLE_NETWORK']='1';
        $p=proc_open([$composer,'--no-plugins','--no-scripts','--version','--no-ansi'],[0=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,PHP_OS_FAMILY==='Windows'?null:'/', $env);
        if(is_resource($p)){$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$status=proc_close($p);unset($err);if($status===0&&preg_match('/\bComposer version (\d+\.\d+\.\d+)/',$out,$m))$version=$m[1];unset($out);}
    }
    $directory=is_dir('/home/mgye-api-sulb')?'/home/mgye-api-sulb':getcwd();
    $extensions=[];foreach(['pdo_mysql','bcmath','mbstring','dom','xml','fileinfo','zip']as$e)$extensions[$e]=extension_loaded($e);
    echo json_encode(['mode'=>'READ_ONLY_STAGING_RUNTIME','database_connections'=>0,'schema_read'=>false,'services_changed'=>false,
      'php_cli'=>PHP_VERSION,'php84_or_newer'=>PHP_VERSION_ID>=80400,'required_qa_extensions'=>$extensions,
      'composer_available'=>$composer!==null,'composer_version'=>$version,'composer_install_executed'=>false,
      'unshare_command_available'=>stagingExecutable('unshare')!==null,
      'resources'=>stagingResources(PHP_OS_FAMILY==='Linux'?file_get_contents('/proc/meminfo'):'',disk_free_space($directory)),
      'production_native_instance_authorized'=>false,'separate_staging_host_required'=>true,'release_decision'=>'NO-GO'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,"STOP: runtime prerequisite inventory unavailable; no database action attempted.\n");exit(1);}

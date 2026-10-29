<?php
namespace App\Support;
final class ReleaseRuntimeConfig {
    public const FILE='/etc/sulb-release/runtime.json';
    public const FLAGS=['uom.operational_enabled','inventory_value.enabled','inventory_transfer.root_protocol_enabled','shipment_allocation.enabled','shipment_cost_read.enabled'];
    public static function validate(array $v):array {
        if(($v['format']??'')!=='SULB_FULL_RELEASE_ACTIVATION_V1'||($v['deployment_authorized']??false)!==true||!preg_match('/^[a-f0-9]{40}$/D',$v['approved_commit']??'')||!preg_match('/^[a-f0-9]{64}$/D',$v['derived_artifact_sha256']??''))throw new \RuntimeException('RELEASE_RUNTIME_APPROVAL_REQUIRED');
        $flags=$v['flags']??[];$keys=array_keys($flags);sort($keys);$expected=self::FLAGS;sort($expected);if($keys!==$expected||array_filter($flags,fn($v)=>$v!==true))throw new \RuntimeException('RELEASE_EXACT_OPERATIONAL_FLAGS_REQUIRED');return $flags;
    }
    public static function apply():void {
        if(app()->environment('testing')||!file_exists(self::FILE))return;$parent=dirname(self::FILE);if(PHP_OS_FAMILY!=='Linux'||is_link(self::FILE)||realpath(self::FILE)!==self::FILE||fileowner(self::FILE)!==0||(fileperms(self::FILE)&0022)!==0||is_link($parent)||fileowner($parent)!==0||(fileperms($parent)&0022)!==0||filesize(self::FILE)>16384)throw new \RuntimeException('RELEASE_RUNTIME_ROOT_FILE_REQUIRED');
        config(self::validate(json_decode(file_get_contents(self::FILE),true,512,JSON_THROW_ON_ERROR)));
    }
}

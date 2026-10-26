<?php
namespace App\Services\Uom;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\Auth\SessionContextService;

final class UomAuthorization
{
    public static function actor(int $company,bool $write=false,?int $branch=null):object
    {
        $id=Auth::id();$u=$id?DB::table('users')->where('id',$id)->where('is_active',1)->first():null;
        if(!$u||(int)$u->company_id!==$company)throw new \RuntimeException('UOM_AUTH_COMPANY');
        $ctx=app(SessionContextService::class);$r=$ctx->roleForUser((int)$id);$code=strtoupper((string)($r->role_code??''));
        if(!$r||in_array($code,['SUPER_ADMIN','SUPPORT'],true)||request()->attributes->get('is_support_mode',false))throw new \RuntimeException('UOM_AUTH_ROLE');
        if($write&&in_array($code,['READ_ONLY','VIEWER'],true))throw new \RuntimeException('UOM_READ_ONLY');
        $permission=$write?'items.update':'items.view';if(!$ctx->permissionsForUser((int)$id,$company)->contains($permission))throw new \RuntimeException('UOM_PERMISSION');
        if($branch!==null&&(!DB::table('branches')->where('company_id',$company)->where('id',$branch)->exists()||($u->branch_id!==null&&(int)$u->branch_id!==$branch)))throw new \RuntimeException('UOM_AUTH_BRANCH');
        return $u;
    }
}

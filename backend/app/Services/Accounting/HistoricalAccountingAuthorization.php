<?php
namespace App\Services\Accounting;

use App\Services\Auth\SessionContextService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Service-boundary authorization. Never derive the actor from a client UID. */
final class HistoricalAccountingAuthorization
{
    public static function assert(int $companyId,int $branchId,int $userId,array $permissions): void
    {
        IsolatedHistoricalAccountingGate::assert();
        if(!Auth::check()||(int)Auth::id()!==$userId)throw new \RuntimeException('Authenticated actor mismatch.');
        $actor=DB::table('users')->where('id',$userId)->where('is_active',1)->first();
        if(!$actor||(int)$actor->company_id!==$companyId)throw new \RuntimeException('Actor outside company scope.');
        if(!DB::table('branches')->where('company_id',$companyId)->where('id',$branchId)->exists()
            ||($actor->branch_id!==null&&(int)$actor->branch_id!==$branchId))throw new \RuntimeException('Actor outside branch scope.');
        $contexts=app(SessionContextService::class);$role=$contexts->roleForUser($userId);
        // Support activation is not approved for these experimental services. Fail closed
        // even if a caller bypasses EnsureSupportAccess or supplies fake context attributes.
        if(strtoupper((string)($role->role_code??''))==='SUPER_ADMIN'
            ||request()->attributes->get('is_support_mode',false))throw new \RuntimeException('Historical support mutations are not enabled.');
        if(!$role)throw new \RuntimeException('Active role required.');
        $allowed=$contexts->permissionsForUser($userId,$companyId);
        foreach($permissions as$permission)if($allowed->contains($permission))return;
        throw new \RuntimeException('Historical accounting permission denied.');
    }
}

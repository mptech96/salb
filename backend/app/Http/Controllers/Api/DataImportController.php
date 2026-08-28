<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\MigrationCenterService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class DataImportController extends Controller
{
    public function catalog(Request $r, AccountingContext $ctx, MigrationCenterService $svc)
    {
        $cid=$ctx->companyId($r);$branch=$ctx->branchFilter($r);
        return response()->json(['status'=>true,'data'=>[
            'entities'=>$svc->prepareCatalog(),
            'history'=>$svc->history($cid),
            'branches'=>DB::table('branches')->where('company_id',$cid)->when($branch!==null,fn($q)=>$q->where('id',$branch))->where('is_active',1)->orderBy('branch_name')->get(['id','branch_code','branch_name']),
            'limits'=>['max_file_bytes'=>20*1024*1024,'max_rows'=>50000,'formats'=>['csv','txt']],
        ]]);
    }

    public function history(Request $r, AccountingContext $ctx, MigrationCenterService $svc)
    {
        return response()->json(['status'=>true,'data'=>$svc->history($ctx->companyId($r))]);
    }

    public function batch(Request $r, int $id, AccountingContext $ctx, MigrationCenterService $svc)
    {
        try{return response()->json(['status'=>true,'data'=>$svc->batch($ctx->companyId($r),$id)]);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],404);}
    }

    public function template(Request $r, string $entity, MigrationCenterService $svc)
    {
        try{
            $t=$svc->template($entity);$filename='SULB-'.$entity.'-template.csv';
            return response()->streamDownload(function()use($t){$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,$t['headers']);fputcsv($out,array_map(fn($h)=>$t['example'][$h]??'',$t['headers']));fclose($out);},$filename,['Content-Type'=>'text/csv; charset=UTF-8']);
        }catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}
    }

    public function preview(Request $r, string $entity, AccountingContext $ctx, MigrationCenterService $svc)
    {
        $r->validate([
            'file'=>'nullable|file|max:20480',
            'file_base64'=>'nullable|string',
            'file_text'=>'nullable|string',
            'file_name'=>'nullable|string|max:255',
            'mapping'=>'nullable|string',
        ]);
        try{
            $parsed=$this->parsedFile($r,$svc);
            $mapping=$this->mapping($r);
            $data=$svc->preview($ctx->companyId($r),$entity,$parsed,$mapping,$ctx->branchFilter($r));
            return response()->json(['status'=>true,'data'=>$data]);
        }catch(ValidationException $e){throw $e;}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}
    }

    public function import(Request $r, string $entity, AccountingContext $ctx, MigrationCenterService $svc)
    {
        $r->validate([
            'file'=>'nullable|file|max:20480',
            'file_base64'=>'nullable|string',
            'file_text'=>'nullable|string',
            'file_name'=>'nullable|string|max:255',
            'mapping'=>'nullable|string',
            'source_system'=>'nullable|string|max:120',
            'posting_mode'=>'nullable|in:DRAFT',
            'existing_draft_policy'=>'nullable|in:SKIP_EXISTING',
            'auto_create_groups_categories'=>'nullable',
        ]);
        try{
            $parsed=$this->parsedFile($r,$svc);
            $mapping=$this->mapping($r);
            $opts=[
                'source_system'=>$r->input('source_system','Legacy System'),
                'posting_mode'=>$r->input('posting_mode','DRAFT'),
                'existing_draft_policy'=>$r->input('existing_draft_policy','SKIP_EXISTING'),
                'auto_create_groups_categories'=>filter_var($r->input('auto_create_groups_categories',true),FILTER_VALIDATE_BOOLEAN),
            ];
            $data=$svc->import(
                $ctx->companyId($r),
                $ctx->branchFilter($r),
                $ctx->userId($r),
                $entity,
                $parsed,
                $mapping,
                $opts,
                ['name'=>$r->hasFile('file')?$r->file('file')->getClientOriginalName():$r->input('file_name','import.csv')]
            );
            return response()->json(['status'=>true,'message'=>'اكتملت دفعة الاستيراد. راجع الملخص والأخطاء قبل اعتماد أي ترحيل مالي.','data'=>$data]);
        }catch(ValidationException $e){throw $e;}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}
    }

    public function export(Request $r, string $entity, AccountingContext $ctx, MigrationCenterService $svc)
    {
        $r->validate(['date_from'=>'nullable|date','date_to'=>'nullable|date|after_or_equal:date_from','branch_id'=>'nullable|integer','format'=>'nullable|in:csv']);
        try{$rows=$svc->exportRows($ctx->companyId($r),$entity,['branch_id'=>$ctx->branchFilter($r),'date_from'=>$r->query('date_from'),'date_to'=>$r->query('date_to')]);$filename='SULB-'.$entity.'-export-'.date('Ymd-His').'.csv';return response()->streamDownload(function()use($rows){$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");if(!$rows){fputcsv($out,['no_data']);}else{$headers=array_keys($rows[0]);fputcsv($out,$headers);foreach($rows as$row)fputcsv($out,array_map(fn($h)=>is_scalar($row[$h]??null)||is_null($row[$h]??null)?$row[$h]??'':json_encode($row[$h],JSON_UNESCAPED_UNICODE),$headers));}fclose($out);},$filename,['Content-Type'=>'text/csv; charset=UTF-8']);}catch(\Throwable $e){return response()->json(['status'=>false,'message'=>$e->getMessage()],422);}
    }

    private function parsedFile(Request $r, MigrationCenterService $svc): array
    {
        // Keep multipart compatibility, but SULB frontend now uses Base64 JSON deliberately.
        // This avoids browser/Axios multipart-boundary problems and makes preview/import deterministic.
        if ($r->hasFile('file')) {
            return $svc->parse($r->file('file'));
        }

        $fileName=(string)$r->input('file_name','import.csv');
        $b64=trim((string)$r->input('file_base64',''));
        if ($b64!=='') {
            $raw=base64_decode($b64,true);
            if($raw===false) throw new \RuntimeException('تعذر فك محتوى ملف CSV المرسل.');
            if(strlen($raw)>20*1024*1024) throw new \RuntimeException('حجم الملف أكبر من الحد المسموح 20 MB.');
            return $svc->parseRaw($raw,$fileName);
        }

        // Optional text payload for API clients/testing.
        $text=$r->input('file_text');
        if(is_string($text) && $text!=='') {
            if(strlen($text)>20*1024*1024) throw new \RuntimeException('حجم الملف أكبر من الحد المسموح 20 MB.');
            return $svc->parseRaw($text,$fileName);
        }

        throw ValidationException::withMessages(['file'=>['لم يصل محتوى ملف CSV إلى الخادم. أعد اختيار الملف وحاول مرة أخرى.']]);
    }

    private function mapping(Request $r): array
    {
        $raw=$r->input('mapping');if(!$raw)return [];$x=json_decode($raw,true);return is_array($x)?$x:[];
    }
}

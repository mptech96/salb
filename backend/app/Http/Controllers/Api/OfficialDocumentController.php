<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OfficialDocumentController extends Controller
{
    use LogsActivity;

    private function companyId()
    {
        return request()->header('X-Company-ID');
    }

    private function branchId()
    {
        return request()->header('X-Branch-ID');
    }

    private function userId()
    {
        return request()->header('X-User-ID');
    }

    public function index()
    {
        $companyId = $this->companyId();
        $branchScopeId = (int) $this->branchId();

        $data = DB::table('official_documents as d')
            ->leftJoin('branches as b', 'b.id', '=', 'd.branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.created_by')
            ->where('d.company_id', $companyId)
            ->when($branchScopeId > 0, fn ($q) => $q->where('d.branch_id', $branchScopeId))
            ->select(
                'd.*',
                'b.branch_name',
                'u.name as created_by_name',
                DB::raw('(SELECT COUNT(*) FROM official_document_attachments a WHERE a.document_id = d.id AND a.company_id = d.company_id) as attachments_count')
            )
            ->orderByDesc('d.id')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId();

        if (!$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية'
            ], 400);
        }

        $request->validate([
            'doc_title' => 'required|string|max:255',
            'doc_type' => 'nullable|string|max:100',
            'doc_content' => 'nullable|string',
        ]);

        $id = DB::table('official_documents')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $this->branchId(),
            'created_by' => $this->userId(),
            'doc_title' => $request->doc_title,
            'doc_type' => $request->doc_type ?? 'GENERAL',
            'doc_content' => $request->doc_content,
            'status' => $request->status ?? 'DRAFT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->logCreate('OfficialDocuments', $id, 'تم إنشاء ورقة رسمية: ' . $request->doc_title);

        return response()->json([
            'status' => true,
            'message' => 'تم حفظ الورقة الرسمية',
            'id' => $id
        ]);
    }

    public function show($id)
    {
        $companyId = $this->companyId();

        $doc = DB::table('official_documents')
            ->where('company_id', $companyId)
            ->when((int) $this->branchId() > 0, fn ($q) => $q->where('branch_id', (int) $this->branchId()))
            ->where('id', $id)
            ->first();

        if (!$doc) {
            return response()->json([
                'status' => false,
                'message' => 'الورقة غير موجودة'
            ], 404);
        }

        $settings = DB::table('company_settings')
            ->where('company_id', $companyId)
            ->first();

        $attachments = DB::table('official_document_attachments')
            ->where('company_id', $companyId)
            ->where('document_id', $id)
            ->orderByDesc('id')
            ->get()
            ->map(function ($row) {
                $row->url = asset('storage/' . $row->file_path);
                return $row;
            });

        return response()->json([
            'status' => true,
            'data' => [
                'document' => $doc,
                'settings' => $settings,
                'attachments' => $attachments
            ]
        ]);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->companyId();

        $request->validate([
            'doc_title' => 'required|string|max:255',
            'doc_type' => 'nullable|string|max:100',
            'doc_content' => 'nullable|string',
        ]);

        $updated = DB::table('official_documents')
            ->where('company_id', $companyId)
            ->when((int) $this->branchId() > 0, fn ($q) => $q->where('branch_id', (int) $this->branchId()))
            ->where('id', $id)
            ->update([
                'doc_title' => $request->doc_title,
                'doc_type' => $request->doc_type ?? 'GENERAL',
                'doc_content' => $request->doc_content,
                'status' => $request->status ?? 'DRAFT',
                'updated_at' => now(),
            ]);

        if (!$updated) {
            return response()->json([
                'status' => false,
                'message' => 'الورقة غير موجودة'
            ], 404);
        }

        $this->logUpdate('OfficialDocuments', $id, 'تم تعديل ورقة رسمية');

        return response()->json([
            'status' => true,
            'message' => 'تم تعديل الورقة الرسمية'
        ]);
    }

    public function uploadAttachment(Request $request, $id)
    {
        $companyId = $this->companyId();

        $doc = DB::table('official_documents')
            ->where('company_id', $companyId)
            ->when((int) $this->branchId() > 0, fn ($q) => $q->where('branch_id', (int) $this->branchId()))
            ->where('id', $id)
            ->first();

        if (!$doc) {
            return response()->json([
                'status' => false,
                'message' => 'الورقة غير موجودة'
            ], 404);
        }

        $request->validate([
            'files' => 'required',
            'files.*' => 'file|max:10240',
        ]);

        $saved = [];

        foreach ($request->file('files', []) as $file) {
            $path = $file->store("official-documents/{$companyId}/{$id}", 'public');

            $attachmentId = DB::table('official_document_attachments')->insertGetId([
                'company_id' => $companyId,
                'document_id' => $id,
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $saved[] = [
                'id' => $attachmentId,
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'url' => asset('storage/' . $path),
            ];
        }

        $this->logUpdate('OfficialDocuments', $id, 'تم رفع مرفقات للورقة الرسمية');

        return response()->json([
            'status' => true,
            'message' => 'تم رفع المرفقات',
            'data' => $saved
        ]);
    }

    public function deleteAttachment($attachmentId)
    {
        $companyId = $this->companyId();

        $attachment = DB::table('official_document_attachments as a')
            ->join('official_documents as d', 'd.id', '=', 'a.document_id')
            ->where('a.company_id', $companyId)
            ->where('d.company_id', $companyId)
            ->when((int) $this->branchId() > 0, fn ($q) => $q->where('d.branch_id', (int) $this->branchId()))
            ->where('a.id', $attachmentId)
            ->select('a.*')
            ->first();

        if (!$attachment) {
            return response()->json([
                'status' => false,
                'message' => 'المرفق غير موجود'
            ], 404);
        }

        if ($attachment->file_path && Storage::disk('public')->exists($attachment->file_path)) {
            Storage::disk('public')->delete($attachment->file_path);
        }

        DB::table('official_document_attachments')
            ->where('company_id', $companyId)
            ->where('id', $attachmentId)
            ->delete();

        $this->logDelete('OfficialDocuments', $attachment->document_id, 'تم حذف مرفق من الورقة الرسمية');

        return response()->json([
            'status' => true,
            'message' => 'تم حذف المرفق'
        ]);
    }

    public function destroy($id)
    {
        $companyId = $this->companyId();

        $doc = DB::table('official_documents')
            ->where('company_id', $companyId)
            ->when((int) $this->branchId() > 0, fn ($q) => $q->where('branch_id', (int) $this->branchId()))
            ->where('id', $id)
            ->first();

        if (!$doc) {
            return response()->json(['status' => false, 'message' => 'الورقة غير موجودة ضمن نطاقك'], 404);
        }

        $attachments = DB::table('official_document_attachments')
            ->where('company_id', $companyId)
            ->where('document_id', $id)
            ->get();

        foreach ($attachments as $attachment) {
            if ($attachment->file_path && Storage::disk('public')->exists($attachment->file_path)) {
                Storage::disk('public')->delete($attachment->file_path);
            }
        }

        DB::table('official_document_attachments')
            ->where('company_id', $companyId)
            ->where('document_id', $id)
            ->delete();

        DB::table('official_documents')
            ->where('company_id', $companyId)
            ->when((int) $this->branchId() > 0, fn ($q) => $q->where('branch_id', (int) $this->branchId()))
            ->where('id', $id)
            ->delete();

        $this->logDelete('OfficialDocuments', $id, 'تم حذف ورقة رسمية');

        return response()->json([
            'status' => true,
            'message' => 'تم حذف الورقة الرسمية'
        ]);
    }
}
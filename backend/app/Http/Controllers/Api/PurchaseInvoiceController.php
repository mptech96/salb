<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseInvoiceController extends Controller
{
    use LogsActivity;

    private function companyId()
    {
        return request()->header('X-Company-ID');
    }

    public function index()
    {
        $companyId = $this->companyId();

        $data = DB::table('purchase_invoices as p')
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->leftJoin('cars as c', 'c.id', '=', 'p.car_id')
            ->where('p.company_id', $companyId)
            ->select('p.*', 's.supplier_name', 'c.car_number')
            ->orderByDesc('p.id')
            ->get();

        return response()->json(['status' => true, 'data' => $data]);
    }

    public function show($id)
    {
        $companyId = $this->companyId();

        $invoice = DB::table('purchase_invoices')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$invoice) {
            return response()->json([
                'status' => false,
                'message' => 'فاتورة الشراء غير موجودة'
            ], 404);
        }

        $lines = DB::table('purchase_invoice_lines as l')
            ->leftJoin('items as i', 'i.id', '=', 'l.item_id')
            ->select('l.*', 'i.item_name')
            ->where('l.company_id', $companyId)
            ->where('l.purchase_invoice_id', $id)
            ->get();

        return response()->json([
            'status' => true,
            'data' => [
                'invoice' => $invoice,
                'lines' => $lines
            ]
        ]);
    }

    public function store(Request $request)
    {
        return $this->saveInvoice($request);
    }

    public function update(Request $request, $id)
    {
        return $this->saveInvoice($request, $id);
    }

    private function saveInvoice(Request $request, $id = null)
    {
        $companyId = $this->companyId();

        if (!$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية'
            ], 400);
        }

        DB::beginTransaction();

        try {
            if ($id) {
                $oldInvoice = DB::table('purchase_invoices')
                    ->where('company_id', $companyId)
                    ->where('id', $id)
                    ->first();

                if (!$oldInvoice) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => 'فاتورة الشراء غير موجودة'
                    ], 404);
                }

                DB::table('purchase_invoice_lines')
                    ->where('company_id', $companyId)
                    ->where('purchase_invoice_id', $id)
                    ->delete();

                DB::table('stock_movements')
                    ->where('company_id', $companyId)
                    ->where('source_type', 'PURCHASE')
                    ->where('source_id', $id)
                    ->delete();

                DB::table('purchase_invoices')
                    ->where('company_id', $companyId)
                    ->where('id', $id)
                    ->update([
                        'branch_id' => $request->branch_id,
                        'supplier_id' => $request->supplier_id,
                        'car_id' => $request->car_id,
                        'invoice_number' => $request->invoice_number,
                        'invoice_date' => $request->invoice_date,
                        'total_qty' => $request->total_qty ?? 0,
                        'total_before_discount' => $request->total_before_discount ?? 0,
                        'discount_amount' => $request->discount_amount ?? 0,
                        'transport_cost' => $request->transport_cost ?? 0,
                        'extra_cost' => $request->extra_cost ?? 0,
                        'total_amount' => $request->total_amount ?? 0,
                        'notes' => $request->notes,
                        'updated_at' => now(),
                    ]);

                $invoiceId = $id;
            } else {
                $invoiceId = DB::table('purchase_invoices')->insertGetId([
                    'company_id' => $companyId,
                    'branch_id' => $request->branch_id,
                    'supplier_id' => $request->supplier_id,
                    'car_id' => $request->car_id,
                    'invoice_number' => $request->invoice_number,
                    'invoice_date' => $request->invoice_date,
                    'total_qty' => $request->total_qty ?? 0,
                    'total_before_discount' => $request->total_before_discount ?? 0,
                    'discount_amount' => $request->discount_amount ?? 0,
                    'transport_cost' => $request->transport_cost ?? 0,
                    'extra_cost' => $request->extra_cost ?? 0,
                    'total_amount' => $request->total_amount ?? 0,
                    'payment_status' => 'UNPAID',
                    'notes' => $request->notes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach (($request->items ?? []) as $item) {
                DB::table('purchase_invoice_lines')->insert([
                    'company_id' => $companyId,
                    'purchase_invoice_id' => $invoiceId,
                    'item_id' => $item['item_id'],
                    'car_id' => $request->car_id,
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                    'discount_amount' => $item['discount_amount'] ?? 0,
                    'line_total' => $item['line_total'],
                    'notes' => $item['notes'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('stock_movements')->insert([
                    'company_id' => $companyId,
                    'branch_id' => $request->branch_id,
                    'item_id' => $item['item_id'],
                    'car_id' => $request->car_id,
                    'movement_type' => 'IN',
                    'source_type' => 'PURCHASE',
                    'source_id' => $invoiceId,
                    'movement_date' => $request->invoice_date ?? now(),
                    'qty' => $item['qty'],
                    'unit_cost' => $item['unit_price'],
                    'total_cost' => $item['line_total'],
                    'notes' => 'فاتورة شراء',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            if ($id) {
                $this->logUpdate(
                    'PurchaseInvoices',
                    $invoiceId,
                    'تم تعديل فاتورة شراء رقم: ' . ($request->invoice_number ?: $invoiceId)
                );
            } else {
                $this->logCreate(
                    'PurchaseInvoices',
                    $invoiceId,
                    'تم إنشاء فاتورة شراء رقم: ' . ($request->invoice_number ?: $invoiceId)
                );
            }

            return response()->json([
                'status' => true,
                'message' => $id ? 'تم تعديل الفاتورة' : 'تم حفظ الفاتورة'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        $companyId = $this->companyId();

        $invoice = DB::table('purchase_invoices')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$invoice) {
            return response()->json([
                'status' => false,
                'message' => 'فاتورة الشراء غير موجودة'
            ], 404);
        }

        DB::table('purchase_invoice_lines')
            ->where('company_id', $companyId)
            ->where('purchase_invoice_id', $id)
            ->delete();

        DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('source_type', 'PURCHASE')
            ->where('source_id', $id)
            ->delete();

        DB::table('purchase_invoices')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->delete();

        $this->logDelete(
            'PurchaseInvoices',
            $id,
            'تم حذف فاتورة شراء رقم: ' . ($invoice->invoice_number ?: $id)
        );

        return response()->json([
            'status' => true,
            'message' => 'تم حذف الفاتورة'
        ]);
    }
}
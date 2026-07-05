<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesInvoiceController extends Controller
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

    public function index()
    {
        $companyId = $this->companyId();

        $data = DB::table('sales_invoices as s')
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->leftJoin('cars as car', 'car.id', '=', 's.car_id')
            ->where('s.company_id', $companyId)
            ->select('s.*', 'c.customer_name', 'car.car_number')
            ->orderByDesc('s.id')
            ->get();

        return response()->json(['status' => true, 'data' => $data]);
    }

    public function show($id)
    {
        $companyId = $this->companyId();

        $invoice = DB::table('sales_invoices')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$invoice) {
            return response()->json([
                'status' => false,
                'message' => 'فاتورة البيع غير موجودة'
            ], 404);
        }

        $lines = DB::table('sales_invoice_lines as l')
            ->leftJoin('items as i', 'i.id', '=', 'l.item_id')
            ->select('l.*', 'i.item_name')
            ->where('l.company_id', $companyId)
            ->where('l.sales_invoice_id', $id)
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
        $branchId = $request->branch_id ?? $this->branchId();

        if (!$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'لم يتم تحديد الشركة الحالية'
            ], 400);
        }

        DB::beginTransaction();

        try {
            foreach (($request->items ?? []) as $item) {
                $stockInQuery = DB::table('stock_movements')
                    ->where('company_id', $companyId)
                    ->where('item_id', $item['item_id'])
                    ->where('movement_type', 'IN');

                if ($request->car_id) {
                    $stockInQuery->where('car_id', $request->car_id);
                } else {
                    $stockInQuery->whereNull('car_id');
                }

                $stockIn = $stockInQuery->sum('qty');

                $stockOutQuery = DB::table('stock_movements')
                    ->where('company_id', $companyId)
                    ->where('item_id', $item['item_id'])
                    ->where('movement_type', 'OUT');

                if ($request->car_id) {
                    $stockOutQuery->where('car_id', $request->car_id);
                } else {
                    $stockOutQuery->whereNull('car_id');
                }

                if ($id) {
                    $stockOutQuery
                        ->where('source_type', 'SALE')
                        ->where('source_id', '!=', $id);
                }

                $stockOut = $stockOutQuery->sum('qty');
                $available = $stockIn - $stockOut;

                if ((float) $item['qty'] > (float) $available) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => 'الكمية المطلوبة أكبر من المتوفر بالمخزون. المتوفر: ' . number_format($available, 3)
                    ], 500);
                }
            }

            if ($id) {
                $oldInvoice = DB::table('sales_invoices')
                    ->where('company_id', $companyId)
                    ->where('id', $id)
                    ->first();

                if (!$oldInvoice) {
                    DB::rollBack();

                    return response()->json([
                        'status' => false,
                        'message' => 'فاتورة البيع غير موجودة'
                    ], 404);
                }

                DB::table('sales_invoice_lines')
                    ->where('company_id', $companyId)
                    ->where('sales_invoice_id', $id)
                    ->delete();

                DB::table('stock_movements')
                    ->where('company_id', $companyId)
                    ->where('source_type', 'SALE')
                    ->where('source_id', $id)
                    ->delete();

                DB::table('sales_invoices')
                    ->where('company_id', $companyId)
                    ->where('id', $id)
                    ->update([
                        'branch_id' => $branchId,
                        'customer_id' => $request->customer_id,
                        'car_id' => $request->car_id ?: null,
                        'invoice_number' => $request->invoice_number,
                        'invoice_date' => $request->invoice_date,
                        'total_qty' => $request->total_qty ?? 0,
                        'total_before_discount' => $request->total_before_discount ?? 0,
                        'discount_amount' => $request->discount_amount ?? 0,
                        'commission_amount' => $request->commission_amount ?? 0,
                        'total_amount' => $request->total_amount ?? 0,
                        'notes' => $request->notes,
                        'updated_at' => now(),
                    ]);

                $invoiceId = $id;
            } else {
                $invoiceId = DB::table('sales_invoices')->insertGetId([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'customer_id' => $request->customer_id,
                    'car_id' => $request->car_id ?: null,
                    'invoice_number' => $request->invoice_number,
                    'invoice_date' => $request->invoice_date,
                    'total_qty' => $request->total_qty ?? 0,
                    'total_before_discount' => $request->total_before_discount ?? 0,
                    'discount_amount' => $request->discount_amount ?? 0,
                    'commission_amount' => $request->commission_amount ?? 0,
                    'total_amount' => $request->total_amount ?? 0,
                    'payment_status' => 'UNPAID',
                    'notes' => $request->notes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach (($request->items ?? []) as $item) {
                DB::table('sales_invoice_lines')->insert([
                    'company_id' => $companyId,
                    'sales_invoice_id' => $invoiceId,
                    'item_id' => $item['item_id'],
                    'car_id' => $request->car_id ?: null,
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                    'discount_amount' => $item['discount_amount'] ?? 0,
                    'line_total' => $item['line_total'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('stock_movements')->insert([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'item_id' => $item['item_id'],
                    'car_id' => $request->car_id ?: null,
                    'movement_type' => 'OUT',
                    'source_type' => 'SALE',
                    'source_id' => $invoiceId,
                    'movement_date' => $request->invoice_date ?? now(),
                    'qty' => $item['qty'],
                    'unit_cost' => $item['unit_price'],
                    'total_cost' => $item['line_total'],
                    'notes' => 'فاتورة بيع',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            if ($id) {
                $this->logUpdate(
                    'SalesInvoices',
                    $invoiceId,
                    'تم تعديل فاتورة بيع رقم: ' . ($request->invoice_number ?: $invoiceId)
                );
            } else {
                $this->logCreate(
                    'SalesInvoices',
                    $invoiceId,
                    'تم إنشاء فاتورة بيع رقم: ' . ($request->invoice_number ?: $invoiceId)
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

        $invoice = DB::table('sales_invoices')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$invoice) {
            return response()->json([
                'status' => false,
                'message' => 'فاتورة البيع غير موجودة'
            ], 404);
        }

        DB::table('sales_invoice_lines')
            ->where('company_id', $companyId)
            ->where('sales_invoice_id', $id)
            ->delete();

        DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('source_type', 'SALE')
            ->where('source_id', $id)
            ->delete();

        DB::table('sales_invoices')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->delete();

        $this->logDelete(
            'SalesInvoices',
            $id,
            'تم حذف فاتورة بيع رقم: ' . ($invoice->invoice_number ?: $id)
        );

        return response()->json([
            'status' => true,
            'message' => 'تم حذف الفاتورة'
        ]);
    }
}
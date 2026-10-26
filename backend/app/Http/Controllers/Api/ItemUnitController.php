<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\Uom\ItemUnitService;
use App\Services\Uom\UnitCatalog;
use App\Services\Uom\UomAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Definition revisions only. This endpoint never posts stock or converts legacy items. */
final class ItemUnitController extends Controller
{
    private function scope(Request $request, int $item, AccountingContext $context, bool $write = false): int
    {
        $company = $context->companyId($request);
        // Keep the established UOM definition permission contract, including Support denial.
        try { UomAuthorization::actor($company, $write); }
        catch (\RuntimeException $error) { abort(403, 'UOM_DEFINITION_ACCESS_DENIED'); }
        abort_unless(DB::table('items')->where('company_id', $company)->where('id', $item)->exists(), 404);
        return $company;
    }

    private function ready(): bool
    {
        foreach (['uom_units', 'uom_item_modes', 'item_uom_mappings', 'item_uom_heads', 'item_uom_prices', 'item_uom_price_heads'] as $table) {
            if (!Schema::hasTable($table)) return false;
        }
        return Schema::hasColumn('items', 'base_uom_id');
    }

    public function show(Request $request, int $id, AccountingContext $context)
    {
        $company = $this->scope($request, $id, $context);
        if (!$this->ready()) return response()->json(['status' => true, 'data' => ['schema_ready' => false, 'mode' => 'LEGACY_ONLY', 'units' => [], 'mappings' => [], 'prices' => []]]);
        $mappings = DB::table('item_uom_mappings as m')
            ->join('uom_units as u', 'u.id', '=', 'm.unit_id')
            ->join('item_uom_heads as h', function ($join) {
                $join->on('h.company_id', '=', 'm.company_id')->on('h.item_id', '=', 'm.item_id')
                    ->on('h.unit_id', '=', 'm.unit_id')->on('h.revision', '=', 'm.revision');
            })->where('m.company_id', $company)->where('m.item_id', $id)
            ->orderBy('u.code')->select('m.*', 'u.code', 'u.name_ar', 'u.dimension')->get();
        $prices = DB::table('item_uom_prices as p')->join('item_uom_price_heads as h', function ($join) {
            $join->on('h.company_id', '=', 'p.company_id')->on('h.mapping_id', '=', 'p.mapping_id')
                ->on('h.currency_code', '=', 'p.currency_code')->on('h.revision', '=', 'p.revision');
        })->where('p.company_id', $company)->whereIn('p.mapping_id', $mappings->pluck('id'))
            ->orderBy('p.mapping_id')->orderBy('p.currency_code')->select('p.*')->get();
        return response()->json(['status' => true, 'data' => [
            'schema_ready' => true,
            'mode' => DB::table('uom_item_modes')->where('company_id', $company)->where('item_id', $id)->value('mode') ?? 'LEGACY_ONLY',
            'base_unit_id' => DB::table('items')->where('company_id', $company)->where('id', $id)->value('base_uom_id'),
            'units' => app(UnitCatalog::class)->all(), 'mappings' => $mappings, 'prices' => $prices,
        ]]);
    }

    public function mapping(Request $request, int $id, AccountingContext $context, ItemUnitService $service)
    {
        $company = $this->scope($request, $id, $context, true);
        abort_unless($this->ready(), 409, 'UOM_SCHEMA_REQUIRED');
        $input = $request->validate([
            'unit_id' => ['required', 'integer', 'min:1'],
            // Strings preserve decimal intent; JSON floating point is not a quantity contract.
            'factor' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,6})?$/D'],
            // tenant.scope overwrites company_id; never consume it from validated input.
            'item_id' => ['prohibited'], 'base_unit_id' => ['prohibited'],
            'revision' => ['prohibited'], 'mapping_id' => ['prohibited'],
        ]);
        try { $mapping = $service->reviseMapping($company, $id, (int) $input['unit_id'], $input['factor']); }
        catch (\InvalidArgumentException|\RuntimeException $error) {
            if ($error instanceof \Illuminate\Database\QueryException) return response()->json(['status' => false, 'code' => 'UOM_DEFINITION_CONFLICT', 'message' => 'تعذر حفظ مراجعة الوحدة. أعد تحميل التعريف قبل المحاولة.'], 409);
            return response()->json(['status' => false, 'code' => 'UOM_DEFINITION_REJECTED', 'message' => 'معامل التحويل أو نطاق الوحدة غير صالح.'], 422);
        }
        return response()->json(['status' => true, 'data' => ['mapping_id' => $mapping]], 201);
    }

    public function price(Request $request, int $id, AccountingContext $context, ItemUnitService $service)
    {
        $company = $this->scope($request, $id, $context, true);
        abort_unless($this->ready(), 409, 'UOM_SCHEMA_REQUIRED');
        $input = $request->validate([
            'mapping_id' => ['required', 'integer', 'min:1'], 'currency_code' => ['required', 'string', 'max:10'],
            'purchase_price' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,6})?$/D'],
            'sale_price' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,6})?$/D'],
            'item_id' => ['prohibited'], 'revision' => ['prohibited'],
        ]);
        // A stale revision remains readable but is not the target of a new catalog price.
        $current = DB::table('item_uom_mappings as m')->join('item_uom_heads as h', function ($join) {
            $join->on('h.company_id', '=', 'm.company_id')->on('h.item_id', '=', 'm.item_id')->on('h.unit_id', '=', 'm.unit_id')->on('h.revision', '=', 'm.revision');
        })->where('m.company_id', $company)->where('m.item_id', $id)->where('m.id', $input['mapping_id'])->exists();
        if (!$current || !DB::table('company_currencies')->where('company_id', $company)->where('currency_code', $input['currency_code'])->where('is_active', 1)->exists()) {
            return response()->json(['status' => false, 'code' => 'UOM_PRICE_SCOPE'], 422);
        }
        try { $price = $service->revisePrice($company, $id, (int) $input['mapping_id'], $input['currency_code'], $input['purchase_price'], $input['sale_price'], true); }
        catch (\InvalidArgumentException|\RuntimeException $error) {
            if ($error instanceof \Illuminate\Database\QueryException) return response()->json(['status' => false, 'code' => 'UOM_PRICE_CONFLICT', 'message' => 'تعذر حفظ مراجعة السعر. أعد تحميل التعريف قبل المحاولة.'], 409);
            return response()->json(['status' => false, 'code' => 'UOM_PRICE_INVALID'], 422);
        }
        return response()->json(['status' => true, 'data' => ['price_id' => $price]], 201);
    }
}

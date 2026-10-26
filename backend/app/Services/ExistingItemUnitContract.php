<?php
namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Existing weight-stock contract only. NOT a generic UOM or cross-dimension converter. */
final class ExistingItemUnitContract
{
    public static function stock(array $input):array
    {
        $base=strtoupper(trim((string)($input['base_unit_code']??'KG')));
        $commercial=strtoupper(trim((string)($input['commercial_unit_code']??'TON')));
        if($base!=='KG')throw ValidationException::withMessages(['base_unit_code'=>'المخزون الحالي يدعم كيلوجرام كوحدة أساسية فقط؛ الوحدات غير الوزنية تحتاج اعتماد نموذج مخزون متعدد الوحدات.']);
        if(!in_array($commercial,['KG','TON'],true))throw ValidationException::withMessages(['commercial_unit_code'=>'اختر كيلوجرام أو طن للأصناف المخزنية الحالية؛ لا يمكن تحويل هذه الوحدة إلى وزن تلقائيًا.']);
        $factor=$commercial==='TON'?1000:1;
        $provided=$input['commercial_to_base_factor']??null;
        if($provided!==null&&$provided!==''&&(!is_numeric($provided)||!is_finite((float)$provided)||(float)$provided!== (float)$factor))throw ValidationException::withMessages(['commercial_to_base_factor'=>'معامل التحويل المعتمد: الطن = 1000 كجم، والكيلوجرام = 1 كجم.']);
        return ['base_unit_code'=>'KG','commercial_unit_code'=>$commercial,'commercial_to_base_factor'=>$factor,'unit_name'=>$commercial==='TON'?'طن':'كجم'];
    }
}

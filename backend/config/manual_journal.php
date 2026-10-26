<?php
// Explicit financial writes; registration grants no role or user access.
return ['permissions'=>[
    ['permission_code'=>'journals.post','permission_name'=>'ترحيل قيد يدوي','module_name'=>'accounting','permission_scope'=>'COMPANY'],
    ['permission_code'=>'journals.reverse','permission_name'=>'عكس قيد يدوي','module_name'=>'accounting','permission_scope'=>'COMPANY'],
]];

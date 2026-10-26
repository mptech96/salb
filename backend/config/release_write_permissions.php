<?php
// Write permissions are registered without assigning them to any existing role.
return ['permissions'=>[
    ['permission_code'=>'fixed_assets.manage','permission_name'=>'إدارة وترحيل الأصول الثابتة','module_name'=>'accounting','permission_scope'=>'COMPANY'],
    ['permission_code'=>'workers.manage','permission_name'=>'تعديل بيانات وعمليات العمال','module_name'=>'workers','permission_scope'=>'COMPANY'],
    ['permission_code'=>'payroll.manage','permission_name'=>'إعداد واعتماد وصرف الرواتب','module_name'=>'workers','permission_scope'=>'COMPANY'],
]];

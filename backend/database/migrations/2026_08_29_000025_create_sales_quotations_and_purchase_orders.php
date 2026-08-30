<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_quotations', function (Blueprint $t): void {
            $t->id(); $t->bigInteger('company_id'); $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('customer_id'); $t->string('document_number',100); $t->date('document_date');
            $t->date('valid_until')->nullable(); $t->string('status',30)->default('DRAFT');
            $t->string('currency_code',10); $t->decimal('exchange_rate',18,8)->default(1);
            $t->decimal('subtotal',18,3)->default(0); $t->decimal('discount_amount',18,3)->default(0);
            $t->decimal('tax_amount',18,3)->default(0); $t->decimal('total_amount',18,3)->default(0);
            $t->json('tax_summary_json')->nullable(); $t->text('notes')->nullable(); $t->text('terms')->nullable();
            $t->unsignedBigInteger('converted_invoice_id')->nullable(); $t->timestamp('converted_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable(); $t->unsignedBigInteger('updated_by')->nullable(); $t->timestamps();
            $t->unique(['company_id','document_number'],'uq_sales_quotation_company_number');
            $t->index(['company_id','branch_id','status','document_date'],'idx_sales_quotation_scope_status_date');
            $t->index(['company_id','customer_id','document_date'],'idx_sales_quotation_customer_date');
            $t->foreign('company_id')->references('id')->on('companies'); $t->foreign('branch_id')->references('id')->on('branches');
            $t->foreign('customer_id')->references('id')->on('customers'); $t->foreign('converted_invoice_id')->references('id')->on('sales_invoices');
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete(); $t->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('sales_quotation_lines', function (Blueprint $t): void {
            $t->id(); $t->bigInteger('company_id'); $t->unsignedBigInteger('sales_quotation_id'); $t->unsignedBigInteger('item_id');
            $t->text('description')->nullable(); $t->decimal('quantity',18,6); $t->string('unit_code',20);
            $t->decimal('qty_kg',18,3)->default(0); $t->string('price_unit',10); $t->decimal('unit_price',18,6);
            $t->decimal('unit_price_per_kg',18,6)->default(0); $t->decimal('discount_amount',18,3)->default(0);
            $t->unsignedBigInteger('tax_code_id')->nullable(); $t->string('tax_code_snapshot',50)->nullable();
            $t->string('tax_name_snapshot',150)->nullable(); $t->decimal('tax_rate_snapshot',9,4)->default(0);
            $t->decimal('subtotal',18,3); $t->decimal('tax_amount',18,3); $t->decimal('total_amount',18,3); $t->timestamps();
            $t->index(['company_id','sales_quotation_id'],'idx_sales_quotation_lines_parent');
            $t->foreign('company_id')->references('id')->on('companies');
            $t->foreign('sales_quotation_id')->references('id')->on('sales_quotations')->cascadeOnDelete();
            $t->foreign('item_id')->references('id')->on('items'); $t->foreign('tax_code_id')->references('id')->on('tax_codes');
        });
        Schema::create('purchase_orders', function (Blueprint $t): void {
            $t->id(); $t->bigInteger('company_id'); $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('supplier_id'); $t->string('document_number',100); $t->date('document_date');
            $t->date('expected_delivery_date')->nullable(); $t->string('status',30)->default('DRAFT');
            $t->string('currency_code',10); $t->decimal('exchange_rate',18,8)->default(1);
            $t->decimal('subtotal',18,3)->default(0); $t->decimal('discount_amount',18,3)->default(0);
            $t->decimal('tax_amount',18,3)->default(0); $t->decimal('total_amount',18,3)->default(0);
            $t->json('tax_summary_json')->nullable(); $t->text('notes')->nullable(); $t->text('terms')->nullable();
            $t->unsignedBigInteger('converted_invoice_id')->nullable(); $t->timestamp('converted_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable(); $t->unsignedBigInteger('updated_by')->nullable(); $t->timestamps();
            $t->unique(['company_id','document_number'],'uq_purchase_order_company_number');
            $t->index(['company_id','branch_id','status','document_date'],'idx_purchase_order_scope_status_date');
            $t->index(['company_id','supplier_id','document_date'],'idx_purchase_order_supplier_date');
            $t->foreign('company_id')->references('id')->on('companies'); $t->foreign('branch_id')->references('id')->on('branches');
            $t->foreign('supplier_id')->references('id')->on('suppliers'); $t->foreign('converted_invoice_id')->references('id')->on('purchase_invoices');
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete(); $t->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('purchase_order_lines', function (Blueprint $t): void {
            $t->id(); $t->bigInteger('company_id'); $t->unsignedBigInteger('purchase_order_id'); $t->unsignedBigInteger('item_id');
            $t->text('description')->nullable(); $t->decimal('quantity',18,6); $t->string('unit_code',20);
            $t->decimal('qty_kg',18,3)->default(0); $t->string('price_unit',10); $t->decimal('unit_price',18,6);
            $t->decimal('unit_price_per_kg',18,6)->default(0); $t->decimal('discount_amount',18,3)->default(0);
            $t->unsignedBigInteger('tax_code_id')->nullable(); $t->string('tax_code_snapshot',50)->nullable();
            $t->string('tax_name_snapshot',150)->nullable(); $t->decimal('tax_rate_snapshot',9,4)->default(0);
            $t->decimal('subtotal',18,3); $t->decimal('tax_amount',18,3); $t->decimal('total_amount',18,3); $t->timestamps();
            $t->index(['company_id','purchase_order_id'],'idx_purchase_order_lines_parent');
            $t->foreign('company_id')->references('id')->on('companies');
            $t->foreign('purchase_order_id')->references('id')->on('purchase_orders')->cascadeOnDelete();
            $t->foreign('item_id')->references('id')->on('items'); $t->foreign('tax_code_id')->references('id')->on('tax_codes');
        });

        $permissions = [
            'quotations.view'=>'عرض عروض الأسعار','quotations.create'=>'إنشاء عروض الأسعار','quotations.update'=>'تعديل عروض الأسعار',
            'quotations.send'=>'إرسال عروض الأسعار','quotations.accept'=>'قبول/رفض عروض الأسعار','quotations.cancel'=>'إلغاء عروض الأسعار',
            'quotations.convert'=>'تحويل عرض السعر','quotations.print'=>'طباعة عروض الأسعار',
            'purchase_orders.view'=>'عرض أوامر الشراء','purchase_orders.create'=>'إنشاء أوامر الشراء','purchase_orders.update'=>'تعديل أوامر الشراء',
            'purchase_orders.approve'=>'اعتماد أوامر الشراء','purchase_orders.send'=>'إرسال أوامر الشراء','purchase_orders.cancel'=>'إلغاء أوامر الشراء',
            'purchase_orders.convert'=>'تحويل أمر الشراء','purchase_orders.print'=>'طباعة أوامر الشراء',
        ];
        foreach ($permissions as $code=>$name) {
            if (!DB::table('permissions')->where('permission_code',$code)->exists()) {
                DB::table('permissions')->insert(['permission_code'=>$code,'permission_name'=>$name,'module_name'=>str_starts_with($code,'quotations.')?'sales':'purchases','permission_scope'=>'COMPANY','created_at'=>now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines'); Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('sales_quotation_lines'); Schema::dropIfExists('sales_quotations');
        $codes=['quotations.view','quotations.create','quotations.update','quotations.send','quotations.accept','quotations.cancel','quotations.convert','quotations.print','purchase_orders.view','purchase_orders.create','purchase_orders.update','purchase_orders.approve','purchase_orders.send','purchase_orders.cancel','purchase_orders.convert','purchase_orders.print'];
        DB::table('permissions')->whereIn('permission_code',$codes)->whereNotExists(fn($q)=>$q->selectRaw('1')->from('role_permissions')->whereColumn('role_permissions.permission_id','permissions.id'))->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1) الهوية القانونية والدولية
        |--------------------------------------------------------------------------
        */
        $this->addColumns('companies', [
            'legal_name' => fn (Blueprint $t) => $t->string('legal_name', 255)->nullable(),
            'registration_number' => fn (Blueprint $t) => $t->string('registration_number', 120)->nullable(),
            'tax_number' => fn (Blueprint $t) => $t->string('tax_number', 120)->nullable(),
            'country_code' => fn (Blueprint $t) => $t->string('country_code', 2)->nullable(),
            'default_language' => fn (Blueprint $t) => $t->string('default_language', 10)->default('ar'),
            'timezone' => fn (Blueprint $t) => $t->string('timezone', 80)->default('UTC'),
        ]);

        $this->addColumns('branches', [
            'legal_name' => fn (Blueprint $t) => $t->string('legal_name', 255)->nullable(),
            'email' => fn (Blueprint $t) => $t->string('email', 150)->nullable(),
            'registration_number' => fn (Blueprint $t) => $t->string('registration_number', 120)->nullable(),
            'tax_number' => fn (Blueprint $t) => $t->string('tax_number', 120)->nullable(),
            'country_code' => fn (Blueprint $t) => $t->string('country_code', 2)->nullable(),
        ]);

        foreach (['customers','suppliers'] as $table) {
            $this->addColumns($table, [
                'legal_name' => fn (Blueprint $t) => $t->string('legal_name', 255)->nullable(),
                'email' => fn (Blueprint $t) => $t->string('email', 150)->nullable(),
                'registration_number' => fn (Blueprint $t) => $t->string('registration_number', 120)->nullable(),
                'tax_number' => fn (Blueprint $t) => $t->string('tax_number', 120)->nullable(),
                'country_code' => fn (Blueprint $t) => $t->string('country_code', 2)->nullable(),
                'scope_all_branches' => fn (Blueprint $t) => $t->boolean('scope_all_branches')->default(false),
                'default_branch_id' => fn (Blueprint $t) => $t->unsignedBigInteger('default_branch_id')->nullable(),
            ]);
        }

        if (!Schema::hasTable('entity_addresses')) {
            Schema::create('entity_addresses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('entity_type', 30); // COMPANY / BRANCH / CUSTOMER / SUPPLIER
                $t->unsignedBigInteger('entity_id');
                $t->string('address_type', 30)->default('LEGAL');
                $t->string('country_code', 2)->nullable();
                $t->string('short_address', 100)->nullable();
                $t->string('building_no', 50)->nullable();
                $t->string('street_name', 200)->nullable();
                $t->string('district', 150)->nullable();
                $t->string('city', 150)->nullable();
                $t->string('state_region', 150)->nullable();
                $t->string('postal_code', 50)->nullable();
                $t->string('additional_no', 50)->nullable();
                $t->string('unit_no', 50)->nullable();
                $t->string('address_line1', 500)->nullable();
                $t->string('address_line2', 500)->nullable();
                $t->boolean('is_default')->default(true);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->index(['company_id','entity_type','entity_id'], 'idx_entity_address_owner');
                $t->index(['company_id','country_code','city'], 'idx_entity_address_geo');
            });
        }

        if (!Schema::hasTable('customer_branches')) {
            Schema::create('customer_branches', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('customer_id'); $t->unsignedBigInteger('branch_id');
                $t->boolean('is_default')->default(false); $t->boolean('is_active')->default(true); $t->timestamps();
                $t->unique(['company_id','customer_id','branch_id'], 'uq_customer_branch_scope');
                $t->index(['company_id','branch_id','is_active'], 'idx_customer_branch_lookup');
            });
        }

        if (!Schema::hasTable('supplier_branches')) {
            Schema::create('supplier_branches', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('supplier_id'); $t->unsignedBigInteger('branch_id');
                $t->boolean('is_default')->default(false); $t->boolean('is_active')->default(true); $t->timestamps();
                $t->unique(['company_id','supplier_id','branch_id'], 'uq_supplier_branch_scope');
                $t->index(['company_id','branch_id','is_active'], 'idx_supplier_branch_lookup');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 2) العملات وأسعار الصرف والضرائب
        |--------------------------------------------------------------------------
        */
        if (!Schema::hasTable('currencies')) {
            Schema::create('currencies', function (Blueprint $t) {
                $t->id(); $t->string('currency_code', 10)->unique(); $t->string('currency_name', 100);
                $t->string('symbol', 20)->nullable(); $t->unsignedTinyInteger('decimal_places')->default(2); $t->boolean('is_active')->default(true); $t->timestamps();
            });
        }
        if (!Schema::hasTable('company_currencies')) {
            Schema::create('company_currencies', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->string('currency_code', 10); $t->boolean('is_base')->default(false); $t->boolean('is_active')->default(true); $t->timestamps();
                $t->unique(['company_id','currency_code'], 'uq_company_currency');
            });
        }
        if (!Schema::hasTable('exchange_rates')) {
            Schema::create('exchange_rates', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->string('currency_code', 10); $t->decimal('rate_to_base', 24, 10); $t->date('valid_from');
                $t->string('source', 100)->nullable(); $t->text('notes')->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
                $t->unique(['company_id','currency_code','valid_from'], 'uq_exchange_rate_date');
                $t->index(['company_id','valid_from'], 'idx_exchange_rate_company_date');
            });
        }
        if (!Schema::hasTable('tax_codes')) {
            Schema::create('tax_codes', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->string('tax_code', 50); $t->string('tax_name', 150);
                $t->string('tax_type', 30)->default('TAX'); $t->decimal('rate', 9, 4)->default(0);
                $t->date('valid_from')->nullable(); $t->date('valid_to')->nullable();
                $t->unsignedBigInteger('sales_tax_account_id')->nullable(); $t->unsignedBigInteger('purchase_tax_account_id')->nullable();
                $t->boolean('is_zero_rated')->default(false); $t->boolean('is_exempt')->default(false); $t->boolean('is_out_of_scope')->default(false);
                $t->boolean('is_default_sales')->default(false); $t->boolean('is_default_purchase')->default(false); $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['company_id','tax_code'], 'uq_company_tax_code');
                $t->index(['company_id','is_active','valid_from','valid_to'], 'idx_company_tax_validity');
            });
        }

        if (!Schema::hasTable('document_sequences')) {
            Schema::create('document_sequences', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('document_family', 30); $t->string('document_type', 40); $t->unsignedSmallInteger('document_year');
                $t->string('prefix', 40); $t->unsignedBigInteger('next_number')->default(1); $t->timestamps();
                $t->unique(['company_id','branch_id','document_family','document_type','document_year'], 'uq_document_sequence_scope');
                $t->index(['company_id','document_family','document_year'], 'idx_document_sequence_lookup');
            });
        }

        $this->addColumns('company_settings', [
            'country_code' => fn (Blueprint $t) => $t->string('country_code', 2)->nullable(),
            'base_currency_code' => fn (Blueprint $t) => $t->string('base_currency_code', 10)->nullable(),
            'currency_decimal_places' => fn (Blueprint $t) => $t->unsignedTinyInteger('currency_decimal_places')->default(3),
            'tax_inclusive_prices' => fn (Blueprint $t) => $t->boolean('tax_inclusive_prices')->default(false),
            'default_sales_tax_code_id' => fn (Blueprint $t) => $t->unsignedBigInteger('default_sales_tax_code_id')->nullable(),
            'default_purchase_tax_code_id' => fn (Blueprint $t) => $t->unsignedBigInteger('default_purchase_tax_code_id')->nullable(),
            'invoice_prefix' => fn (Blueprint $t) => $t->string('invoice_prefix', 30)->nullable(),
            'purchase_prefix' => fn (Blueprint $t) => $t->string('purchase_prefix', 30)->nullable(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 3) الخزائن والبنوك والمحافظ كطبقة تشغيلية مرتبطة بدفتر الأستاذ
        |--------------------------------------------------------------------------
        */
        if (!Schema::hasTable('financial_accounts')) {
            Schema::create('financial_accounts', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('account_code', 80); $t->string('account_name', 200); $t->string('account_type', 30); // CASH/BANK/WALLET/PETTY_CASH/OTHER
                $t->unsignedBigInteger('gl_account_id'); $t->string('currency_code', 10);
                $t->string('bank_name', 150)->nullable(); $t->string('account_number', 120)->nullable(); $t->string('iban', 120)->nullable(); $t->string('wallet_provider', 120)->nullable();
                $t->boolean('is_default_receipt')->default(false); $t->boolean('is_default_payment')->default(false); $t->boolean('is_active')->default(true);
                $t->text('notes')->nullable(); $t->timestamps();
                $t->unique(['company_id','account_code'], 'uq_financial_account_code');
                $t->index(['company_id','branch_id','account_type','is_active'], 'idx_financial_account_branch');
            });
        }
        if (!Schema::hasTable('branch_financial_settings')) {
            Schema::create('branch_financial_settings', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id');
                $t->unsignedBigInteger('default_cash_financial_account_id')->nullable(); $t->unsignedBigInteger('default_bank_financial_account_id')->nullable();
                $t->unsignedBigInteger('default_wallet_financial_account_id')->nullable(); $t->unsignedBigInteger('default_cost_center_id')->nullable();
                $t->timestamps(); $t->unique(['company_id','branch_id'], 'uq_branch_financial_settings');
            });
        }

        $this->addColumns('journal_entry_lines', [
            'financial_account_id' => fn (Blueprint $t) => $t->unsignedBigInteger('financial_account_id')->nullable(),
            'counterparty_branch_id' => fn (Blueprint $t) => $t->unsignedBigInteger('counterparty_branch_id')->nullable(),
            'currency_code' => fn (Blueprint $t) => $t->string('currency_code', 10)->nullable(),
            'foreign_debit' => fn (Blueprint $t) => $t->decimal('foreign_debit', 18, 3)->default(0),
            'foreign_credit' => fn (Blueprint $t) => $t->decimal('foreign_credit', 18, 3)->default(0),
            'exchange_rate' => fn (Blueprint $t) => $t->decimal('exchange_rate', 24, 10)->nullable(),
        ]);
        $this->addColumns('journal_entries', [
            'currency_code' => fn (Blueprint $t) => $t->string('currency_code', 10)->nullable(),
            'exchange_rate' => fn (Blueprint $t) => $t->decimal('exchange_rate', 24, 10)->nullable(),
        ]);

        foreach (['vouchers','expenses'] as $table) {
            $this->addColumns($table, [
                'financial_account_id' => fn (Blueprint $t) => $t->unsignedBigInteger('financial_account_id')->nullable(),
                'currency_code' => fn (Blueprint $t) => $t->string('currency_code', 10)->nullable(),
                'exchange_rate' => fn (Blueprint $t) => $t->decimal('exchange_rate', 24, 10)->nullable(),
                'foreign_amount' => fn (Blueprint $t) => $t->decimal('foreign_amount', 18, 3)->nullable(),
            ]);
        }
        if (Schema::hasTable('shipment_costs')) {
            $this->addColumns('shipment_costs', [
                'financial_account_id' => fn (Blueprint $t) => $t->unsignedBigInteger('financial_account_id')->nullable(),
                'currency_code' => fn (Blueprint $t) => $t->string('currency_code', 10)->nullable(),
                'exchange_rate' => fn (Blueprint $t) => $t->decimal('exchange_rate', 24, 10)->nullable(),
                'foreign_amount' => fn (Blueprint $t) => $t->decimal('foreign_amount', 18, 3)->nullable(),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 4) Snapshot الفواتير + الضريبة على مستوى السطر + العملة
        |--------------------------------------------------------------------------
        */
        foreach (['sales_invoices','purchase_invoices'] as $table) {
            $this->addColumns($table, [
                'document_type' => fn (Blueprint $t) => $t->string('document_type', 40)->default('TAX_INVOICE'),
                'currency_code' => fn (Blueprint $t) => $t->string('currency_code', 10)->nullable(),
                'exchange_rate' => fn (Blueprint $t) => $t->decimal('exchange_rate', 24, 10)->default(1),
                'base_total_before_vat' => fn (Blueprint $t) => $t->decimal('base_total_before_vat', 18, 3)->nullable(),
                'base_vat_amount' => fn (Blueprint $t) => $t->decimal('base_vat_amount', 18, 3)->nullable(),
                'base_total_amount' => fn (Blueprint $t) => $t->decimal('base_total_amount', 18, 3)->nullable(),
                'seller_snapshot_json' => fn (Blueprint $t) => $t->longText('seller_snapshot_json')->nullable(),
                'buyer_snapshot_json' => fn (Blueprint $t) => $t->longText('buyer_snapshot_json')->nullable(),
                'tax_summary_json' => fn (Blueprint $t) => $t->longText('tax_summary_json')->nullable(),
            ]);
        }
        foreach (['sales_invoice_lines','purchase_invoice_lines'] as $table) {
            $this->addColumns($table, [
                'tax_code_id' => fn (Blueprint $t) => $t->unsignedBigInteger('tax_code_id')->nullable(),
                'tax_code_snapshot' => fn (Blueprint $t) => $t->string('tax_code_snapshot', 50)->nullable(),
                'tax_name_snapshot' => fn (Blueprint $t) => $t->string('tax_name_snapshot', 150)->nullable(),
                'tax_rate_snapshot' => fn (Blueprint $t) => $t->decimal('tax_rate_snapshot', 9, 4)->nullable(),
                'currency_code' => fn (Blueprint $t) => $t->string('currency_code', 10)->nullable(),
                'exchange_rate' => fn (Blueprint $t) => $t->decimal('exchange_rate', 24, 10)->default(1),
                'base_total_before_vat' => fn (Blueprint $t) => $t->decimal('base_total_before_vat', 18, 3)->nullable(),
                'base_vat_amount' => fn (Blueprint $t) => $t->decimal('base_vat_amount', 18, 3)->nullable(),
                'base_total_after_vat' => fn (Blueprint $t) => $t->decimal('base_total_after_vat', 18, 3)->nullable(),
            ]);
        }

        // الشحنات جزء من دورة الشراء ويجب أن تحفظ نفس سياق العملة والضريبة دون أي نسبة ثابتة.
        $this->addColumns('shipments', [
            'currency_code' => fn (Blueprint $t) => $t->string('currency_code', 10)->nullable(),
            'exchange_rate' => fn (Blueprint $t) => $t->decimal('exchange_rate', 24, 10)->default(1),
            'base_total_before_vat' => fn (Blueprint $t) => $t->decimal('base_total_before_vat', 18, 3)->nullable(),
            'base_vat_amount' => fn (Blueprint $t) => $t->decimal('base_vat_amount', 18, 3)->nullable(),
            'base_total_amount' => fn (Blueprint $t) => $t->decimal('base_total_amount', 18, 3)->nullable(),
            'tax_summary_json' => fn (Blueprint $t) => $t->longText('tax_summary_json')->nullable(),
        ]);
        $this->addColumns('shipment_items', [
            'tax_code_id' => fn (Blueprint $t) => $t->unsignedBigInteger('tax_code_id')->nullable(),
            'tax_code_snapshot' => fn (Blueprint $t) => $t->string('tax_code_snapshot', 50)->nullable(),
            'tax_name_snapshot' => fn (Blueprint $t) => $t->string('tax_name_snapshot', 150)->nullable(),
            'tax_rate_snapshot' => fn (Blueprint $t) => $t->decimal('tax_rate_snapshot', 9, 4)->nullable(),
            'currency_code' => fn (Blueprint $t) => $t->string('currency_code', 10)->nullable(),
            'exchange_rate' => fn (Blueprint $t) => $t->decimal('exchange_rate', 24, 10)->default(1),
            'base_total_before_vat' => fn (Blueprint $t) => $t->decimal('base_total_before_vat', 18, 3)->nullable(),
            'base_vat_amount' => fn (Blueprint $t) => $t->decimal('base_vat_amount', 18, 3)->nullable(),
            'base_total_after_vat' => fn (Blueprint $t) => $t->decimal('base_total_after_vat', 18, 3)->nullable(),
        ]);
        // إزالة افتراض 15% من الحقول القديمة دون تغيير أي قيمة تاريخية موجودة.
        if (DB::getDriverName() === 'mysql') {
            foreach (['shipments','shipment_items','sales_invoice_lines','purchase_invoice_lines'] as $vatTable) {
                if (Schema::hasTable($vatTable) && Schema::hasColumn($vatTable,'vat_percent')) {
                    DB::statement('ALTER TABLE `'.$vatTable.'` MODIFY `vat_percent` DECIMAL(5,2) NULL DEFAULT 0.00');
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 5) الأرصدة الافتتاحية المنظمة
        |--------------------------------------------------------------------------
        */
        if (!Schema::hasTable('opening_balance_batches')) {
            Schema::create('opening_balance_batches', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('financial_year_id'); $t->date('opening_date');
                $t->string('batch_number', 100); $t->string('status', 30)->default('DRAFT'); $t->unsignedBigInteger('journal_entry_id')->nullable();
                $t->decimal('total_debit', 18, 3)->default(0); $t->decimal('total_credit', 18, 3)->default(0); $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by')->nullable(); $t->unsignedBigInteger('posted_by')->nullable(); $t->timestamp('posted_at')->nullable(); $t->timestamps();
                $t->unique(['company_id','batch_number'], 'uq_opening_batch_number');
                $t->index(['company_id','financial_year_id','status'], 'idx_opening_batch_year');
            });
        }
        if (!Schema::hasTable('opening_balance_lines')) {
            Schema::create('opening_balance_lines', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('batch_id'); $t->unsignedBigInteger('branch_id')->nullable();
                $t->unsignedBigInteger('account_id'); $t->unsignedBigInteger('financial_account_id')->nullable(); $t->unsignedBigInteger('cost_center_id')->nullable();
                $t->string('party_type', 30)->nullable(); $t->unsignedBigInteger('party_id')->nullable(); $t->decimal('debit', 18, 3)->default(0); $t->decimal('credit', 18, 3)->default(0);
                $t->string('currency_code', 10)->nullable(); $t->decimal('foreign_debit', 18, 3)->default(0); $t->decimal('foreign_credit', 18, 3)->default(0); $t->decimal('exchange_rate', 24, 10)->nullable();
                $t->text('description')->nullable(); $t->timestamps();
                $t->index(['company_id','batch_id'], 'idx_opening_line_batch');
            });
        }
        if (!Schema::hasTable('opening_inventory_lines')) {
            Schema::create('opening_inventory_lines', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('batch_id'); $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('item_id');
                $t->decimal('qty_kg', 18, 3); $t->decimal('total_cost', 18, 3); $t->decimal('unit_cost_per_kg', 18, 6)->default(0); $t->string('lot_number', 120)->nullable();
                $t->text('notes')->nullable(); $t->unsignedBigInteger('inventory_lot_id')->nullable(); $t->timestamps();
                $t->index(['company_id','batch_id','branch_id'], 'idx_opening_inventory_batch');
            });
        }
        if (!Schema::hasTable('opening_fixed_asset_lines')) {
            Schema::create('opening_fixed_asset_lines', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('batch_id'); $t->unsignedBigInteger('branch_id')->nullable(); $t->unsignedBigInteger('category_id');
                $t->string('asset_code', 100); $t->string('asset_name', 255); $t->date('acquisition_date')->nullable(); $t->date('depreciation_start_date')->nullable();
                $t->decimal('historical_cost', 18, 3); $t->decimal('opening_accumulated_depreciation', 18, 3)->default(0); $t->decimal('salvage_value', 18, 3)->default(0);
                $t->string('depreciation_method', 30)->default('STRAIGHT_LINE'); $t->integer('useful_life_months')->nullable(); $t->decimal('annual_depreciation_rate', 9, 4)->nullable();
                $t->unsignedBigInteger('asset_account_id')->nullable(); $t->unsignedBigInteger('accumulated_account_id')->nullable(); $t->unsignedBigInteger('expense_account_id')->nullable();
                $t->unsignedBigInteger('fixed_asset_id')->nullable(); $t->text('notes')->nullable(); $t->timestamps();
                $t->unique(['company_id','asset_code'], 'uq_opening_asset_code');
                $t->index(['company_id','batch_id'], 'idx_opening_asset_batch');
            });
        }
        $this->addColumns('fixed_assets', [
            'acquisition_type' => fn (Blueprint $t) => $t->string('acquisition_type', 30)->default('PURCHASE'),
            'opening_accumulated_depreciation' => fn (Blueprint $t) => $t->decimal('opening_accumulated_depreciation', 18, 3)->default(0),
            'opening_balance_batch_id' => fn (Blueprint $t) => $t->unsignedBigInteger('opening_balance_batch_id')->nullable(),
        ]);
        if (Schema::hasTable('inventory_lots')) {
            $this->addColumns('inventory_lots', [
                'opening_balance_batch_id' => fn (Blueprint $t) => $t->unsignedBigInteger('opening_balance_batch_id')->nullable(),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 6) الحسابات القياسية الجديدة وإعدادات الشركات الحالية
        |--------------------------------------------------------------------------
        */
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $companyId = (int) $companyId;
            $this->ensureAccount($companyId, '1240', 'جاري الفروع - مدين', 'ASSET', 'DEBIT', '1200', 3, 1);
            $this->ensureAccount($companyId, '2800', 'جاري الفروع - دائن', 'LIABILITY', 'CREDIT', '2000', 2, 1);
            $this->ensureAccount($companyId, '3600', 'حساب الأرصدة الافتتاحية', 'EQUITY', 'CREDIT', '3000', 2, 0);
            $this->ensureAccount($companyId, '4700', 'أرباح فروقات العملة', 'REVENUE', 'CREDIT', '4000', 2, 1);
            $this->ensureAccount($companyId, '7800', 'خسائر فروقات العملة', 'EXPENSE', 'DEBIT', '7000', 2, 1);

            $map = [
                'INTERBRANCH_DUE_FROM_ACCOUNT' => '1240',
                'INTERBRANCH_DUE_TO_ACCOUNT' => '2800',
                'OPENING_BALANCE_ACCOUNT' => '3600',
                'FX_GAIN_ACCOUNT' => '4700',
                'FX_LOSS_ACCOUNT' => '7800',
            ];
            foreach ($map as $key => $code) {
                $accountId = DB::table('accounts')->where('company_id',$companyId)->where('account_code',$code)->value('id');
                if ($accountId) {
                    DB::table('accounting_settings')->updateOrInsert(
                        ['company_id'=>$companyId,'setting_key'=>$key],
                        ['account_id'=>$accountId,'created_at'=>now(),'updated_at'=>now()]
                    );
                }
            }

            $settings = DB::table('company_settings')->where('company_id',$companyId)->first();
            $base = strtoupper(trim((string) ($settings->base_currency_code ?? $settings->currency_code ?? 'USD')));
            if ($base === '') $base = 'USD';
            DB::table('company_settings')->updateOrInsert(
                ['company_id'=>$companyId],
                ['base_currency_code'=>$base,'currency_code'=>$base,'updated_at'=>now()]
            );
            $this->ensureCurrency($base, $settings->currency_name ?? $base, null, (int) ($settings->currency_decimal_places ?? 3));
            DB::table('company_currencies')->updateOrInsert(
                ['company_id'=>$companyId,'currency_code'=>$base],
                ['is_base'=>1,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]
            );

            // أكواد حيادية لا تفترض دولة أو نسبة ضريبة.
            DB::table('tax_codes')->updateOrInsert(
                ['company_id'=>$companyId,'tax_code'=>'ZERO'],
                ['tax_name'=>'خاضع بنسبة صفر','tax_type'=>'TAX','rate'=>0,'is_zero_rated'=>1,'is_exempt'=>0,'is_out_of_scope'=>0,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]
            );
            DB::table('tax_codes')->updateOrInsert(
                ['company_id'=>$companyId,'tax_code'=>'EXEMPT'],
                ['tax_name'=>'معفى','tax_type'=>'TAX','rate'=>0,'is_zero_rated'=>0,'is_exempt'=>1,'is_out_of_scope'=>0,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]
            );
            DB::table('tax_codes')->updateOrInsert(
                ['company_id'=>$companyId,'tax_code'=>'OUT_SCOPE'],
                ['tax_name'=>'خارج النطاق','tax_type'=>'TAX','rate'=>0,'is_zero_rated'=>0,'is_exempt'=>0,'is_out_of_scope'=>1,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]
            );

            // نفضّل الحساب المضبوط في إعدادات المحاسبة، ثم شجرة الحسابات الحديثة، ثم حساب الصندوق القديم إن وجد.
            $cashGl = DB::table('accounting_settings')->where('company_id',$companyId)->where('setting_key','CASH_ACCOUNT')->value('account_id');
            if (!$cashGl) $cashGl = DB::table('accounts')->where('company_id',$companyId)->where('account_code','1110')->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->value('id');
            if (!$cashGl) $cashGl = DB::table('accounts')->where('company_id',$companyId)->where('account_code','1100')->where('is_active',1)->where('is_group',0)->where('allow_posting',1)->value('id');
            foreach (DB::table('branches')->where('company_id',$companyId)->get() as $branch) {
                $costCenterId = DB::table('cost_centers')->where('company_id',$companyId)->where('branch_id',$branch->id)->where('is_active',1)->value('id');
                if ($cashGl) {
                    $code = 'CASH-BR-' . $branch->id;
                    $fa = DB::table('financial_accounts')->where('company_id',$companyId)->where('account_code',$code)->first();
                    if (!$fa) {
                        $faId = DB::table('financial_accounts')->insertGetId([
                            'company_id'=>$companyId,'branch_id'=>$branch->id,'account_code'=>$code,
                            'account_name'=>'صندوق ' . $branch->branch_name,'account_type'=>'CASH','gl_account_id'=>$cashGl,'currency_code'=>$base,
                            'is_default_receipt'=>1,'is_default_payment'=>1,'is_active'=>1,'created_at'=>now(),'updated_at'=>now(),
                        ]);
                    } else $faId = (int) $fa->id;
                    DB::table('branch_financial_settings')->updateOrInsert(
                        ['company_id'=>$companyId,'branch_id'=>$branch->id],
                        ['default_cash_financial_account_id'=>$faId,'default_cost_center_id'=>$costCenterId,'created_at'=>now(),'updated_at'=>now()]
                    );
                }
            }
        }

        foreach (['customers' => 'customer_branches', 'suppliers' => 'supplier_branches'] as $entityTable => $linkTable) {
            foreach (DB::table($entityTable)->get() as $row) {
                if (!$row->company_id || !$row->branch_id) continue;
                DB::table($entityTable)->where('id',$row->id)->update([
                    'default_branch_id'=>$row->branch_id,
                    'scope_all_branches'=>0,
                    'updated_at'=>now(),
                ]);
                $idColumn = $entityTable === 'customers' ? 'customer_id' : 'supplier_id';
                DB::table($linkTable)->updateOrInsert(
                    ['company_id'=>$row->company_id,$idColumn=>$row->id,'branch_id'=>$row->branch_id],
                    ['is_default'=>1,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]
                );
            }
        }

        $this->backfillLegacyAddresses('companies', 'COMPANY', 'company_name');
        $this->backfillLegacyAddresses('branches', 'BRANCH', 'branch_name');
        $this->backfillLegacyAddresses('customers', 'CUSTOMER', 'customer_name');
        $this->backfillLegacyAddresses('suppliers', 'SUPPLIER', 'supplier_name');

        if (Schema::hasTable('permissions')) {
            $permissions = [
                ['permission_name'=>'عرض الخزائن والبنوك','permission_code'=>'financial_accounts.view','module_name'=>'accounting'],
                ['permission_name'=>'إدارة الخزائن والبنوك','permission_code'=>'financial_accounts.manage','module_name'=>'accounting'],
                ['permission_name'=>'عرض الضرائب والعملات','permission_code'=>'financial_setup.view','module_name'=>'accounting'],
                ['permission_name'=>'إدارة الضرائب والعملات','permission_code'=>'financial_setup.manage','module_name'=>'accounting'],
                ['permission_name'=>'عرض الأرصدة الافتتاحية','permission_code'=>'opening_balances.view','module_name'=>'accounting'],
                ['permission_name'=>'ترحيل الأرصدة الافتتاحية','permission_code'=>'opening_balances.post','module_name'=>'accounting'],
                ['permission_name'=>'إدارة مراكز التكلفة','permission_code'=>'cost_centers.manage','module_name'=>'accounting'],
            ];
            foreach ($permissions as $p) {
                DB::table('permissions')->updateOrInsert(
                    ['permission_code'=>$p['permission_code']],
                    [...$p,'created_at'=>now()]
                );
            }
        }
    }

    public function down(): void
    {
        // لا نحذف البنية أو البيانات المالية تلقائيًا حفاظًا على الأثر المحاسبي.
    }

    private function addColumns(string $table, array $definitions): void
    {
        if (!Schema::hasTable($table)) return;
        foreach ($definitions as $name => $callback) {
            if (!Schema::hasColumn($table, $name)) Schema::table($table, $callback);
        }
    }

    private function ensureAccount(int $companyId, string $code, string $name, string $type, string $normalSide, ?string $parentCode, int $level, int $allowCostCenter): int
    {
        $parentId = null;
        if ($parentCode) {
            $parent = DB::table('accounts')->where('company_id',$companyId)->where('account_code',$parentCode)->first();
            // لا نربط حسابًا جديدًا تحت حساب ترحيل قديم في الشركات ذات الشجرة القديمة.
            if ($parent && (int)($parent->is_group ?? 0) === 1) $parentId = (int)$parent->id;
        }
        $existing = DB::table('accounts')->where('company_id',$companyId)->where('account_code',$code)->first();
        $data = ['parent_id'=>$parentId,'account_name'=>$name,'account_type'=>$type,'normal_side'=>$normalSide,'account_level'=>$level,'is_group'=>0,'allow_posting'=>1,'allow_cost_center'=>$allowCostCenter,'is_active'=>1,'updated_at'=>now()];
        if ($existing) { DB::table('accounts')->where('id',$existing->id)->update($data); return (int) $existing->id; }
        return DB::table('accounts')->insertGetId(['company_id'=>$companyId,'account_code'=>$code,'notes'=>null,'created_at'=>now(),...$data]);
    }

    private function ensureCurrency(string $code, string $name, ?string $symbol, int $decimals): void
    {
        DB::table('currencies')->updateOrInsert(
            ['currency_code'=>$code],
            ['currency_name'=>$name ?: $code,'symbol'=>$symbol,'decimal_places'=>max(0,min(6,$decimals)),'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]
        );
    }

    private function backfillLegacyAddresses(string $table, string $entityType, string $nameColumn): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'city') || !Schema::hasColumn($table, 'address')) return;
        $columns = $table === 'companies' ? ['id','city','address'] : ['id','company_id','city','address'];
        foreach (DB::table($table)->select($columns)->get() as $row) {
            $companyId = $table === 'companies' ? (int) $row->id : (int) ($row->company_id ?? 0);
            if (!$companyId) continue;
            if (!$row->city && !$row->address) continue;
            $exists = DB::table('entity_addresses')->where('company_id',$companyId)->where('entity_type',$entityType)->where('entity_id',$row->id)->where('is_default',1)->exists();
            if ($exists) continue;
            DB::table('entity_addresses')->insert([
                'company_id'=>$companyId,'entity_type'=>$entityType,'entity_id'=>$row->id,'address_type'=>'LEGAL',
                'city'=>$row->city,'address_line1'=>$row->address,'is_default'=>1,'is_active'=>1,'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
    }
};

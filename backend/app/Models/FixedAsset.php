<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedAsset extends Model
{
    protected $fillable = [

        'company_id',
        'branch_id',

        'category_id',

        'asset_code',
        'asset_name',
        'description',

        'serial_number',
        'barcode',

        'location',
        'cost_center_id',

        'responsible_worker_id',

        'purchase_date',
        'purchase_cost',
        'salvage_value',
        'current_book_value',

        'depreciation_method',
        'useful_life_months',
        'annual_depreciation_rate',
        'accumulated_depreciation',
        'depreciation_start_date',
        'last_depreciation_date',

        'asset_account_id',
        'accumulated_account_id',
        'expense_account_id',

        'purchase_invoice_id',
        'journal_entry_id',

        'asset_status',
        'is_active',

        'created_by',
        'updated_by'
    ];

    protected $casts = [

        'purchase_date' => 'date',
        'depreciation_start_date' => 'date',
        'last_depreciation_date' => 'date',

        'purchase_cost' => 'decimal:3',
        'salvage_value' => 'decimal:3',
        'current_book_value' => 'decimal:3',
        'accumulated_depreciation' => 'decimal:3',
        'annual_depreciation_rate' => 'decimal:4',

        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function category(): BelongsTo
    {
        return $this->belongsTo(
            FixedAssetCategory::class,
            'category_id'
        );
    }

    public function movements(): HasMany
    {
        return $this->hasMany(
            FixedAssetMovement::class,
            'asset_id'
        );
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(
            FixedAssetDepreciation::class,
            'asset_id'
        );
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(
            FixedAssetMaintenance::class,
            'asset_id'
        );
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedAssetCategory extends Model
{
    protected $fillable = [
        'company_id',
        'category_code',
        'category_name',
        'description',
        'depreciation_method',
        'useful_life_months',
        'annual_depreciation_rate',
        'default_salvage_percentage',
        'asset_account_id',
        'accumulated_depreciation_account_id',
        'depreciation_expense_account_id',
        'disposal_gain_account_id',
        'disposal_loss_account_id',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'useful_life_months' => 'integer',
        'annual_depreciation_rate' => 'decimal:4',
        'default_salvage_percentage' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'category_id');
    }
}
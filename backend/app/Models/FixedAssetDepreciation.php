<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetDepreciation extends Model
{
    protected $table = 'fixed_asset_depreciation';

    protected $fillable = [
        'company_id',
        'branch_id',
        'asset_id',
        'depreciation_month',
        'opening_book_value',
        'depreciation_amount',
        'accumulated_depreciation',
        'closing_book_value',
        'journal_entry_id',
        'status',
        'created_by',
    ];

    protected $casts = [
        'depreciation_month' => 'date',
        'opening_book_value' => 'decimal:3',
        'depreciation_amount' => 'decimal:3',
        'accumulated_depreciation' => 'decimal:3',
        'closing_book_value' => 'decimal:3',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }
}
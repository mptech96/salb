<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetMovement extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',
        'asset_id',
        'movement_type',
        'movement_date',
        'amount',
        'from_branch_id',
        'to_branch_id',
        'worker_id',
        'journal_entry_id',
        'reference_no',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'movement_date' => 'date',
        'amount' => 'decimal:3',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }
}
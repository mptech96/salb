<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetMaintenance extends Model
{
    protected $table = 'fixed_asset_maintenance';

    protected $fillable = [
        'company_id',
        'branch_id',
        'asset_id',
        'maintenance_date',
        'maintenance_type',
        'supplier_name',
        'invoice_number',
        'maintenance_cost',
        'cost_treatment',
        'status',
        'expense_account_id',
        'payment_account_id',
        'journal_entry_id',
        'voucher_id',
        'description',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
        'paid_at',
    ];

    protected $casts = [
        'maintenance_date' => 'date',
        'maintenance_cost' => 'decimal:3',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }
}
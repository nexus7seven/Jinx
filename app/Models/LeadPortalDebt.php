<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadPortalDebt extends Model
{
    protected $fillable = [
        'lead_id',
        'creditor_name',
        'balance',
        'source',
    ];

    protected $casts = [
        'lead_id' => 'integer',
        'balance' => 'decimal:2',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadChecklistItem extends Model
{
    protected $fillable = [
        'lead_id',
        'item_name',
        'is_complete',
        'source_type',
        'source_id',
        'is_system',
    ];

    protected $casts = [
        'is_complete' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
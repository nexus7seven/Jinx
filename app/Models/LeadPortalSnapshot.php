<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadPortalSnapshot extends Model
{
    protected $fillable = [
        'lead_id',
        'snapshot_json',
        'emailed_at',
    ];

    protected $casts = [
        'lead_id' => 'integer',
        'snapshot_json' => 'array',
        'emailed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}

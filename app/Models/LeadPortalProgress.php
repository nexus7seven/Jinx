<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadPortalProgress extends Model
{
    protected $table = 'lead_portal_progress';

    protected $fillable = [
        'lead_id',
        'is_demo_mode',
        'demo_payload',
        'current_step',
        'last_completed_step',
        'credit_check_attempts',
        'started_at',
        'last_seen_at',
        'completed_at',
    ];

    protected $casts = [
        'lead_id' => 'integer',
        'credit_check_attempts' => 'integer',
        'is_demo_mode' => 'boolean',
        'demo_payload' => 'array',
        'started_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}

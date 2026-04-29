<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadPortalProgress extends Model
{
    protected $table = 'lead_portal_progress';

    protected $fillable = [
        'lead_id',
        'current_step',
        'last_completed_step',
        'started_at',
        'last_seen_at',
        'completed_at',
    ];

    protected $casts = [
        'lead_id' => 'integer',
        'started_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}

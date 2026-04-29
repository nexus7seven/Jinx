<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadPortalEmailClick extends Model
{
    protected $fillable = [
        'lead_id',
        'lead_portal_snapshot_id',
        'click_type',
        'destination_url',
        'ip_address',
        'user_agent',
        'clicked_at',
        'raw_context_json',
    ];

    protected $casts = [
        'lead_id' => 'integer',
        'lead_portal_snapshot_id' => 'integer',
        'clicked_at' => 'datetime',
        'raw_context_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(LeadPortalSnapshot::class, 'lead_portal_snapshot_id');
    }
}

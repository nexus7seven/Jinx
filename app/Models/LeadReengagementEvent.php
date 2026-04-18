<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadReengagementEvent extends Model
{
    protected $fillable = [
        'lead_id',
        'vicidial_lead_id',
        'whatsapp_detector_event_id',
        'whatsapp_detector_event_uuid',
        'channel',
        'remarketing_stage',
        'remarketing_task_type',
        'remarketing_reason',
        'flow_started_at',
        'engagement_at',
    ];

    protected $casts = [
        'flow_started_at' => 'datetime',
        'engagement_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function whatsAppDetectorEvent(): BelongsTo
    {
        return $this->belongsTo(WhatsAppDetectorEvent::class, 'whatsapp_detector_event_id');
    }
}

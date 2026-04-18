<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppDetectorEvent extends Model
{
    protected $table = 'whatsapp_detector_events';

    protected $fillable = [
        'event_id',
        'chat_id',
        'chat_name',
        'phone',
        'preview_time_text',
        'inferred_message_at',
        'last_inbound_at',
        'latest_message',
        'detected_at',
        'batch_written_at',
        'matched_vicidial_lead_id',
        'flow_started_at',
        'engagement_at',
        'is_after_flow_start',
        'match_status',
        'notes',
        'raw_payload',
    ];

    protected $casts = [
        'inferred_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'detected_at' => 'datetime',
        'batch_written_at' => 'datetime',
        'flow_started_at' => 'datetime',
        'engagement_at' => 'datetime',
        'is_after_flow_start' => 'boolean',
        'raw_payload' => 'array',
    ];
}

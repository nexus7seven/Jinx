<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemarketingResponseEvent extends Model
{
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_HANDLED = 'handled';
    public const STATUS_IGNORED = 'ignored';

    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_CALL = 'call';

    protected $fillable = [
        'lead_id',
        'jinx_lead_id',
        'remarketing_progress_id',
        'source_event_id',
        'dedupe_key',
        'channel',
        'direction',
        'status',
        'matched_phone',
        'matched_email',
        'message_preview',
        'raw_payload_json',
        'detected_at',
        'handled_at',
        'handled_by',
        'decision',
    ];

    protected $casts = [
        'lead_id' => 'integer',
        'jinx_lead_id' => 'integer',
        'remarketing_progress_id' => 'integer',
        'raw_payload_json' => 'array',
        'detected_at' => 'datetime',
        'handled_at' => 'datetime',
        'handled_by' => 'integer',
    ];

    public function progress(): BelongsTo
    {
        return $this->belongsTo(LeadRemarketingProgress::class, 'remarketing_progress_id');
    }

    public function jinxLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'jinx_lead_id');
    }
}

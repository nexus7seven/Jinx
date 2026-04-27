<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RemarketingStep extends Model
{
    protected $fillable = [
        'step_order',
        'step_key',
        'step_name',
        'medium',
        'template_id',
        'template_name',
        'template_variable',
        'delay_minutes',
        'requires_manual_completion',
        'auto_advance_on_send',
        'respect_send_window',
        'send_window_start_time',
        'send_window_end_time',
        'allowed_days_json',
        'stop_if_replied',
        'stop_if_converted',
        'is_active',
        'step_date_added',
        'step_date_modified',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'template_id' => 'integer',
        'delay_minutes' => 'integer',
        'requires_manual_completion' => 'boolean',
        'auto_advance_on_send' => 'boolean',
        'respect_send_window' => 'boolean',
        'allowed_days_json' => 'array',
        'stop_if_replied' => 'boolean',
        'stop_if_converted' => 'boolean',
        'is_active' => 'boolean',
        'step_date_added' => 'datetime',
        'step_date_modified' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(RemarketingTemplate::class, 'template_id');
    }

    public function stepLogs(): HasMany
    {
        return $this->hasMany(LeadRemarketingStepLog::class, 'remarketing_step_id');
    }
}

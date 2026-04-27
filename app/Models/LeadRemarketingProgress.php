<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadRemarketingProgress extends Model
{
    protected $table = 'lead_remarketing_progress';

    protected $fillable = [
        'lead_id',
        'current_step_id',
        'current_step_order',
        'status',
        'started_at',
        'last_step_completed_at',
        'next_step_due_at',
        'stopped_at',
        'stop_reason',
        'stop_context_json',
    ];

    protected $casts = [
        'lead_id' => 'integer',
        'current_step_id' => 'integer',
        'current_step_order' => 'integer',
        'started_at' => 'datetime',
        'last_step_completed_at' => 'datetime',
        'next_step_due_at' => 'datetime',
        'stopped_at' => 'datetime',
        'stop_context_json' => 'array',
    ];

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(RemarketingStep::class, 'current_step_id');
    }
}

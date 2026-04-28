<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadRemarketingStepLog extends Model
{
    protected $table = 'lead_remarketing_step_logs';

    protected $fillable = [
        'lead_id',
        'remarketing_step_id',
        'step_order',
        'medium',
        'planned_medium',
        'actual_medium',
        'template_id',
        'planned_template_key',
        'actual_template_key',
        'fallback_used',
        'fallback_reason',
        'provider',
        'provider_template_id',
        'provider_message_id',
        'status',
        'execution_status',
        'execution_error',
        'due_at',
        'executed_at',
        'started_at',
        'completed_at',
        'failed_at',
        'error_message',
        'created_task_id',
        'context_json',
        'metadata_json',
    ];

    protected $casts = [
        'lead_id' => 'integer',
        'remarketing_step_id' => 'integer',
        'step_order' => 'integer',
        'template_id' => 'integer',
        'fallback_used' => 'boolean',
        'due_at' => 'datetime',
        'executed_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'created_task_id' => 'integer',
        'context_json' => 'array',
        'metadata_json' => 'array',
    ];

    public function remarketingStep(): BelongsTo
    {
        return $this->belongsTo(RemarketingStep::class, 'remarketing_step_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(RemarketingTemplate::class, 'template_id');
    }
}

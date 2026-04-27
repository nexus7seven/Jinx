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
        'template_id',
        'status',
        'due_at',
        'started_at',
        'completed_at',
        'failed_at',
        'provider_message_id',
        'error_message',
        'created_task_id',
        'context_json',
    ];

    protected $casts = [
        'lead_id' => 'integer',
        'remarketing_step_id' => 'integer',
        'step_order' => 'integer',
        'template_id' => 'integer',
        'due_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'created_task_id' => 'integer',
        'context_json' => 'array',
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RemarketingStepLog extends Model
{
    public const STATUS_COMPLETED = 'completed';

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
        'context_json' => 'array',
    ];
}

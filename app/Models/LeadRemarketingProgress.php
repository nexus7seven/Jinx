<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadRemarketingProgress extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PENDING_MANUAL_TASK = 'pending_manual_task';

    protected $table = 'lead_remarketing_progress';

    protected $fillable = [
        'lead_id',
        'current_step_id',
        'current_step_order',
        'status',
        'started_at',
        'last_step_completed_at',
        'next_step_due_at',
    ];

    protected $casts = [
        'lead_id' => 'integer',
        'current_step_id' => 'integer',
        'current_step_order' => 'integer',
        'started_at' => 'datetime',
        'last_step_completed_at' => 'datetime',
        'next_step_due_at' => 'datetime',
    ];
}

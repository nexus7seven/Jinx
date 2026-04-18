<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemarketingCycle extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_COMPLETED = 'completed';

    protected $table = 'remarketing_cycles';

    protected $fillable = [
        'lead_id',
        'flow_key',
        'current_step_key',
        'current_stage',
        'status',
        'entered_step_at',
        'next_due_at',
        'stopped_at',
        'completed_at',
        'meta',
    ];

    protected $casts = [
        'entered_step_at' => 'datetime',
        'next_due_at' => 'datetime',
        'stopped_at' => 'datetime',
        'completed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}

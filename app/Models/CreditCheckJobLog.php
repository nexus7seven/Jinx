<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditCheckJobLog extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_TIMEOUT = 'timeout';

    protected $fillable = [
        'lead_id',
        'local_worker_job_id',
        'external_job_id',
        'invoked_by_user_id',
        'status',
        'friendly_status',
        'started_at',
        'ended_at',
        'raw_log',
        'saved_pdf_path',
        'result_json',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'result_json' => 'array',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function invokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invoked_by_user_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_TIMEOUT,
        ], true);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }
}

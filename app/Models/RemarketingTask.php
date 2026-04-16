<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RemarketingTask extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_STARTED = 'started';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'lead_id',
        'lead_name',
        'phone',
        'campaign_id',
        'task_type',
        'reason',
        'stage',
        'status',
        'time_waiting_text',
        'whatsapp_url',
    ];
}

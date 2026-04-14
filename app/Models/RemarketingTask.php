<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RemarketingTask extends Model
{
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

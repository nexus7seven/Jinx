<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RemarketingTask extends Model
{
    protected $fillable = [
        'lead_name',
        'phone',
        'task_type',
        'reason',
        'stage',
        'status',
        'time_waiting_text',
        'whatsapp_url',
    ];
}

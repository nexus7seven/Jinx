<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RemarketingStep extends Model
{
    protected $table = 'remarketing_steps';

    protected $fillable = [
        'step_order',
        'medium',
        'template_id',
        'delay_minutes',
        'active',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'template_id' => 'integer',
        'delay_minutes' => 'integer',
        'active' => 'boolean',
    ];
}

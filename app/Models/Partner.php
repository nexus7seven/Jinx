<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    protected $fillable = [
        'name',
        'token',
        'active',
        'single_stage_submission',
        'minimal_submission_form',
    ];

    protected $casts = [
        'active' => 'boolean',
        'single_stage_submission' => 'boolean',
        'minimal_submission_form' => 'boolean',
    ];
}
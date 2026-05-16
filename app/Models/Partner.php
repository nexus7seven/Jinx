<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    protected $fillable = [
        'name',
        'token',
        'active',
        'portal_email',
        'portal_password',
        'portal_access_enabled',
        'single_stage_submission',
        'minimal_submission_form',
    ];

    protected $casts = [
        'active' => 'boolean',
        'portal_access_enabled' => 'boolean',
        'single_stage_submission' => 'boolean',
        'minimal_submission_form' => 'boolean',
    ];
}
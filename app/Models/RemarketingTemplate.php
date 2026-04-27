<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RemarketingTemplate extends Model
{
    protected $fillable = [
        'template_key',
        'template_name',
        'medium',
        'provider',
        'subject',
        'body',
        'external_template_id',
        'variables_json',
        'is_active',
    ];

    protected $casts = [
        'variables_json' => 'array',
        'is_active' => 'boolean',
    ];

    public function remarketingSteps(): HasMany
    {
        return $this->hasMany(RemarketingStep::class, 'template_id');
    }

    public function stepLogs(): HasMany
    {
        return $this->hasMany(LeadRemarketingStepLog::class, 'template_id');
    }
}

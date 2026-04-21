<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalBrowserJob extends Model
{
    protected $fillable = [
        'lead_id',
        'job_type',
        'status',
        'payload_json',
        'result_json',
        'claimed_by',
        'claimed_at',
        'heartbeat_at',
        'started_at',
        'finished_at',
        'error_text',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'result_json' => 'array',
        'claimed_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function artifacts()
    {
        return $this->hasMany(LocalBrowserJobArtifact::class)->latest();
    }
}

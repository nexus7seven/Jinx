<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalBrowserJobArtifact extends Model
{
    protected $fillable = [
        'local_browser_job_id',
        'type',
        'original_name',
        'path',
        'mime_type',
        'size_bytes',
        'meta_json',
    ];

    protected $casts = [
        'meta_json' => 'array',
    ];

    public function job()
    {
        return $this->belongsTo(LocalBrowserJob::class, 'local_browser_job_id');
    }
}

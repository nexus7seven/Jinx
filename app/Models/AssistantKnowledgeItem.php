<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantKnowledgeItem extends Model
{
    protected $fillable = [
        'scope',
        'scope_key',
        'category',
        'title',
        'content',
        'status',
        'created_by',
        'supersedes_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}

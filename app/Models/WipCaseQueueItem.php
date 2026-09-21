<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WipCaseQueueItem extends Model
{
    protected $fillable = [
        'user_id',
        'lead_id',
        'position',
        'waiting_on',
        'next_chase_at',
        'last_actioned_at',
        'action_note',
    ];

    protected $casts = [
        'position' => 'integer',
        'next_chase_at' => 'datetime',
        'last_actioned_at' => 'datetime',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

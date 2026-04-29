<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadPortalToken extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'lead_id',
        'token_hash',
        'status',
        'activated_at',
        'expires_at',
        'completed_at',
        'revoked_at',
        'last_used_at',
        'created_ip',
        'last_used_ip',
    ];

    protected $casts = [
        'lead_id' => 'integer',
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadPortalShortLink extends Model
{
    protected $fillable = [
        'lead_portal_token_id',
        'lead_id',
        'short_code',
        'click_count',
        'last_clicked_at',
    ];

    protected $casts = [
        'lead_portal_token_id' => 'integer',
        'lead_id' => 'integer',
        'click_count' => 'integer',
        'last_clicked_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function portalToken(): BelongsTo
    {
        return $this->belongsTo(LeadPortalToken::class, 'lead_portal_token_id');
    }
}

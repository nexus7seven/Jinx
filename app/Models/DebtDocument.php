<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Debt;

class DebtDocument extends Model
{
    protected $fillable = [
        'debt_id',
        'proof_type',
        'is_complete',
    ];

    protected $casts = [
        'is_complete' => 'boolean',
    ];

    public function debt()
    {
        return $this->belongsTo(Debt::class);
    }
}
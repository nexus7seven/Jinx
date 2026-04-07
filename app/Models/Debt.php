<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Lead;
use App\Models\Creditor;
use App\Models\DebtDocument;

class Debt extends Model
{
    protected $fillable = [
        'lead_id',
        'creditor_id',
        'balance',
        'source_expected',
        'reference',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function creditor()
    {
        return $this->belongsTo(Creditor::class);
    }

    public function document()
    {
        return $this->hasOne(DebtDocument::class);
    }
}
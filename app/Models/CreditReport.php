<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditReport extends Model
{
    protected $fillable = [
        'lead_id',
        'provider',
        'status',
        'combined_raw_text',
        'generated_pdf_path',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function files()
    {
        return $this->hasMany(CreditReportFile::class)->orderBy('sort_order');
    }
}
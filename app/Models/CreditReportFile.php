<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditReportFile extends Model
{
    protected $fillable = [
        'credit_report_id',
        'original_name',
        'stored_path',
        'mime_type',
        'sort_order',
        'extracted_text',
    ];

    public function creditReport()
    {
        return $this->belongsTo(CreditReport::class);
    }
}
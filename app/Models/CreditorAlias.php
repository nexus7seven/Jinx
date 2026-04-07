<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditorAlias extends Model
{
    protected $fillable = [
        'creditor_id',
        'alias',
        'normalized_alias',
    ];

    public function creditor()
    {
        return $this->belongsTo(Creditor::class);
    }
}
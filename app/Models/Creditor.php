<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Debt;

class Creditor extends Model
{
    protected $fillable = [
        'name',
        'voting_house',
        'voting_practice1',
        'voting_practice2',
        'voting_practice3',
    ];

    public function debts()
    {
        return $this->hasMany(Debt::class);
    }

    public function aliases()
    {
        return $this->hasMany(\App\Models\CreditorAlias::class);
    }
}
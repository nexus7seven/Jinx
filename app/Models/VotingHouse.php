<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VotingHouse extends Model
{
    protected $fillable = [
        'key',
        'rules_text',
    ];
}
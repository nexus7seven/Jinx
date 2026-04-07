<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VotingPractice extends Model
{
    protected $fillable = [
        'key',
        'label',
        'rules_text',
    ];
}
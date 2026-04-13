<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Debt;

class Lead extends Model
{
    /** Shown first on WIP with highlight; partner + website intake. */
    public const PRIORITY_WIP_STATUSES = [
        'Initial Assessment',
        'Awaiting Call',
    ];

protected $fillable = [
    'vicidial_lead_id',
    'phone_number',
    'first_name',
    'last_name',
    'dob',
    'email',
    'temp_mail',
    'temp_mail_provider',
    'temp_mail_created_at',
    'temp_mail_last_checked_at',
    'temp_mail_last_code',
    'temp_mail_last_subject',
    'temp_mail_last_from',
    'temp_mail_last_message_id',
    'temp_mail_last_body_text',
    'house_number',
    'postcode',
    'address_line_1',
    'case_notes',
    'source',
    'from_vicidial_webform',
    'wip_status',
    'financial_statement',
];

    protected $casts = [
        'temp_mail_created_at' => 'datetime',
        'temp_mail_last_checked_at' => 'datetime',
        'financial_statement' => 'array',
        'from_vicidial_webform' => 'boolean',
    ];

    public function isPriorityWip(): bool
    {
        return in_array($this->wip_status, self::PRIORITY_WIP_STATUSES, true);
    }

    public function debts()
    {
        return $this->hasMany(Debt::class);
    }

    public function creditReports()
    {
        return $this->hasMany(\App\Models\CreditReport::class);
    }

    public function actionPoints()
    {
        return $this->hasMany(\App\Models\LeadActionPoint::class)->latest();
    }
}

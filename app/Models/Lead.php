<?php

namespace App\Models;

use App\Models\Debt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Lead extends Model
{
    /** Shown first on WIP with highlight; partner + website intake. */
    public const PRIORITY_WIP_STATUSES = [
        'Initial Assessment',
        'Awaiting Call',
    ];

    /** Created within this window (hours) counts as "fresh" for immediate-attention UI. */
    public const IMMEDIATE_ATTENTION_FRESH_HOURS = 24;

    public const DEFAULT_WIP_STATUS_WEBSITE_INTAKE = 'Awaiting Call';

    public const DEFAULT_WIP_STATUS_PARTNER_INTAKE = 'Initial Assessment';

    /** All selectable WIP case statuses (lead detail, WIP screen, API validation). */
    public const WIP_STATUSES = [
        'Initial Assessment',
        'Awaiting Call',
        'WIP',
        'Awaiting Docs',
        'Ready to Draft',
        'Sale',
        'Lost Contact',
        'DEAD',
    ];

    /** Not listed on the WIP "Active" tab (shown on "All" only). */
    public const WIP_STATUSES_EXCLUDED_FROM_ACTIVE_TAB = [
        'Sale',
        'Lost Contact',
        'DEAD',
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

    public static function defaultWipStatusForWebsiteIntake(): string
    {
        return self::DEFAULT_WIP_STATUS_WEBSITE_INTAKE;
    }

    public static function defaultWipStatusForPartnerIntake(): string
    {
        return self::DEFAULT_WIP_STATUS_PARTNER_INTAKE;
    }

    /**
     * VICIdial / CRM source id for public website submissions (see config services.vicidial.website_source_id).
     */
    public static function websiteIntakeSourceId(): string
    {
        return config('services.vicidial.website_source_id', 'WEBSITE-CLEARMYCREDIT');
    }

    /**
     * Strong WIP attention: priority intake, never dialled (requires last_dialled_at from VicidialDialActivityService), fresh.
     */
    public function needsImmediateAttention(?Carbon $now = null): bool
    {
        $now = $now ?? now();

        if (! $this->isPriorityWip()) {
            return false;
        }

        if ($this->getAttribute('last_dialled_at') !== null) {
            return false;
        }

        $created = $this->created_at;
        if ($created === null) {
            return false;
        }

        return $created->greaterThanOrEqualTo($now->copy()->subHours(self::IMMEDIATE_ATTENTION_FRESH_HOURS));
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

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

    /** WhatsApp / detector re-engagement; only manual WIP changes should move away from this. */
    public const WIP_STATUS_REENGAGED = 'Re-engaged';

    /** All selectable WIP case statuses (lead detail, WIP screen, API validation). */
    public const WIP_STATUSES = [
        'Initial Assessment',
        'Awaiting Call',
        'WIP',
        'Awaiting Docs',
        'Ready to Draft',
        'Sale',
        'Lost Contact',
        self::WIP_STATUS_REENGAGED,
        'DEAD',
    ];

    /** Not listed on the WIP "Active" tab (shown on "All" only). */
    public const WIP_STATUSES_EXCLUDED_FROM_ACTIVE_TAB = [
        'Sale',
        'Lost Contact',
        'DEAD',
    ];

    public const TITLES = [
        'Mr',
        'Mrs',
        'Miss',
        'Ms',
        'Mx',
        'Dr',
    ];

    protected $fillable = [
        'vicidial_lead_id',
        'phone_number',
        'title',
        'first_name',
        'middle_name',
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
        'house_name',
        'building_number',
        'postcode',
        'address_line_1',
        'case_notes',
        'source',
        'from_vicidial_webform',
        'wip_status',
        'financial_statement',
        'estimated_total_debt',
        'portal_credit_check_started_at',
        'portal_credit_check_completed_at',
        'portal_credit_check_last_run_at',
    ];

    protected $casts = [
        'temp_mail_created_at' => 'datetime',
        'temp_mail_last_checked_at' => 'datetime',
        'financial_statement' => 'array',
        'estimated_total_debt' => 'decimal:2',
        'from_vicidial_webform' => 'boolean',
        'portal_credit_check_started_at' => 'datetime',
        'portal_credit_check_completed_at' => 'datetime',
        'portal_credit_check_last_run_at' => 'datetime',
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

    public function creditCheckJobLogs()
    {
        return $this->hasMany(CreditCheckJobLog::class)->latest('id');
    }

    public function portalTokens()
    {
        return $this->hasMany(LeadPortalToken::class)->latest('id');
    }

    public function latestPortalToken()
    {
        return $this->hasOne(LeadPortalToken::class)->latestOfMany();
    }

    public function portalProgress()
    {
        return $this->hasOne(LeadPortalProgress::class);
    }

    public function portalSnapshots()
    {
        return $this->hasMany(LeadPortalSnapshot::class)->latest('id');
    }

    public function portalDebts()
    {
        return $this->hasMany(LeadPortalDebt::class)->latest('id');
    }

    public function actionPoints()
    {
        return $this->hasMany(\App\Models\LeadActionPoint::class)->latest();
    }

    /**
     * First and last name with optional title (no leading/trailing space when title is empty).
     */
    public function formattedName(): string
    {
        $name = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
        if ($name === '') {
            return '';
        }

        $t = trim((string) ($this->title ?? ''));

        return $t !== '' ? $t . ' ' . $name : $name;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RemarketingStepDefinition extends Model
{
    public const FLOW_DEFAULT_RECOVERY_V1 = 'default_recovery_v1';

    public const STAGE_FRESH = 'fresh';

    public const STAGE_COOLING = 'cooling';

    public const STAGE_COLD = 'cold';

    public const STAGE_DORMANT = 'dormant';

    public const ACTION_CALL = 'call';

    public const ACTION_WHATSAPP = 'whatsapp';

    public const ACTION_SMS = 'sms';

    public const ACTION_EMAIL = 'email';

    protected $table = 'remarketing_step_definitions';

    protected $fillable = [
        'flow_key',
        'step_key',
        'sequence',
        'stage',
        'action_type',
        'delay_minutes',
        'template_name',
        'requires_manual_completion',
        'auto_advance_on_send',
        'stop_if_reengaged',
        'stop_if_dead',
        'stop_if_opted_out',
        'active',
        'instructions',
        'next_step_key',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'delay_minutes' => 'integer',
        'requires_manual_completion' => 'boolean',
        'auto_advance_on_send' => 'boolean',
        'stop_if_reengaged' => 'boolean',
        'stop_if_dead' => 'boolean',
        'stop_if_opted_out' => 'boolean',
        'active' => 'boolean',
    ];
}

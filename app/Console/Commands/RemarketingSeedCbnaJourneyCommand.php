<?php

namespace App\Console\Commands;

use App\Models\RemarketingTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RemarketingSeedCbnaJourneyCommand extends Command
{
    protected $signature = 'remarketing:seed-cbna-journey {--dry-run : Preview only, do not write changes}';

    protected $description = 'Seed or update the locked CBNA Lost Contact remarketing journey.';

    private const FLOW_KEY = 'cbna_lost_contact';

    private const JOURNEY_LABEL = 'CBNA Lost Contact Remarketing';

    private const CLICK_TO_CALL_DISPLAY = '0113 519 5885';

    private const CLICK_TO_CALL_TEL = 'tel:+441135195885';

    private const WHATSAPP_NUMBER = '441617685416';

    private const GLOBAL_VARIABLES = [
        'first_name',
        'agent_name',
        'company_name',
        'whatsapp_link',
        'portal_link',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $templates = $this->templatePayloads();
        $steps = $this->stepPayloads();

        if ($dryRun) {
            $this->line('Dry run mode: no database writes.');
            $this->line('Templates to upsert: '.count($templates));
            $this->line('Steps to upsert: '.count($steps));
            $this->line('Flow key: '.self::FLOW_KEY);

            return self::SUCCESS;
        }

        $templateStats = [
            'created' => 0,
            'updated' => 0,
        ];
        $stepStats = [
            'created' => 0,
            'updated' => 0,
        ];

        $templateIdMap = [];

        foreach ($templates as $template) {
            $record = RemarketingTemplate::query()->updateOrCreate(
                ['template_key' => $template['template_key']],
                $template
            );

            $templateIdMap[$template['template_key']] = (int) $record->id;
            if ($record->wasRecentlyCreated) {
                $templateStats['created']++;
            } else {
                $templateStats['updated']++;
            }
        }

        $supportsFlowKey = Schema::hasColumn('remarketing_steps', 'flow_key');
        $supportsStage = Schema::hasColumn('remarketing_steps', 'stage');

        foreach ($steps as $step) {
            $criteria = ['step_key' => $step['step_key']];
            if ($supportsFlowKey) {
                $criteria['flow_key'] = self::FLOW_KEY;
            }

            $now = now();
            $existing = DB::table('remarketing_steps')->where($criteria)->first();
            $primaryTemplateKey = $step['primary_template_key'];
            $templateId = $primaryTemplateKey !== null ? ($templateIdMap[$primaryTemplateKey] ?? null) : null;

            $payload = [
                'step_order' => $step['step_order'],
                'step_key' => $step['step_key'],
                'step_name' => $step['display_title'],
                'medium' => $step['primary_medium'],
                'primary_medium' => $step['primary_medium'],
                'primary_template_key' => $step['primary_template_key'],
                'fallback_medium' => $step['fallback_medium'],
                'fallback_template_key' => $step['fallback_template_key'],
                'fallback_condition' => $step['fallback_condition'],
                'sendgrid_template_id' => $step['sendgrid_template_id'],
                'call_window_label' => $step['call_window_label'],
                'call_window_start' => $step['call_window_start'],
                'call_window_end' => $step['call_window_end'],
                'parent_step_key' => $step['parent_step_key'],
                'is_manual' => $step['is_manual'],
                'template_id' => $templateId,
                'template_name' => $step['primary_template_key'],
                'template_variable' => null,
                'delay_minutes' => $step['delay_minutes'],
                'requires_manual_completion' => $step['requires_manual_completion'],
                'auto_advance_on_send' => $step['auto_advance_on_send'],
                'metadata_json' => $step['metadata_json'],
                'respect_send_window' => true,
                'send_window_start_time' => '09:00:00',
                'send_window_end_time' => '21:00:00',
                'allowed_days_json' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
                'stop_if_replied' => true,
                'stop_if_converted' => true,
                'is_active' => true,
                'step_date_modified' => $now,
                'updated_at' => $now,
            ];

            if ($supportsFlowKey) {
                $payload['flow_key'] = self::FLOW_KEY;
            }

            if ($supportsStage) {
                $payload['stage'] = $step['stage'];
            }

            if ($existing === null) {
                $payload['step_date_added'] = $now;
                $payload['created_at'] = $now;
                $insertPayload = $this->normalizePayloadForDatabase(array_merge($criteria, $payload));
                DB::table('remarketing_steps')->insert($insertPayload);
                $stepStats['created']++;
                continue;
            }

            $payload['step_date_added'] = $existing->step_date_added ?? $now;
            $payload = $this->normalizePayloadForDatabase($payload);
            DB::table('remarketing_steps')->where($criteria)->update($payload);
            $stepStats['updated']++;
        }

        $this->line('CBNA journey seed complete.');
        $this->line('Templates created: '.$templateStats['created']);
        $this->line('Templates updated: '.$templateStats['updated']);
        $this->line('Steps created: '.$stepStats['created']);
        $this->line('Steps updated: '.$stepStats['updated']);

        return self::SUCCESS;
    }

    private function templatePayloads(): array
    {
        $fromName = '{{agent_name}} from Clear My Credit';

        return [
            $this->smsTemplate('cbna_sms_instant', 'CBNA SMS instant', "Hi {{first_name}}, it’s {{agent_name}} from {{company_name}}.\n\nI just tried giving you a call about your enquiry but couldn’t get through.\n\nNo problem at all — if you still want to go over things, just reply here or message me on WhatsApp:\n{{whatsapp_link}}\n\n👍"),
            $this->whatsappTemplate('cbna_whatsapp_60m', 'CBNA WhatsApp 60m', "Hi {{first_name}}, I tried giving you a call earlier about getting you some help.\n\nNo pressure at all — I know these things aren’t always easy to talk about.\n\nI’m just here to help, so if you want to go over anything, just drop me a message whenever you’re ready 👍"),
            $this->emailTemplate('cbna_step3_email_explainer', 'CBNA Step 3 email explainer', 'd-f22fcf0b856b4d9f840d8b4722fc0385', 'I tried calling earlier — just explaining', 'No pressure — just so you know what the call was about', $fromName),
            $this->whatsappTemplate('cbna_step3_wa_explainer_fallback', 'CBNA Step 3 WhatsApp fallback', "Hi {{first_name}}, I tried calling earlier about getting you some help.\n\nJust so you know what it was about — it’s really just to understand your situation and explain what options might be available.\n\nIf it’s easier, you can check where you stand here:\n{{portal_link}}\n\nOr just message me here 👍"),
            $this->whatsappTemplate('cbna_whatsapp_value_shift', 'CBNA WhatsApp value shift', "Hi {{first_name}}, I know I’ve tried reaching out a couple of times so I’ll keep this quick.\n\nA lot of people I speak to are surprised at how much easier things can be once they know what options are available — things like lowering payments and taking the pressure off.\n\nIf you ever want to go over it, just drop me a message 👍"),
            $this->emailTemplate('cbna_step6_email_benefits', 'CBNA Step 6 email benefits', null, 'TBD', 'TBD', $fromName, ['sendgrid_status' => 'pending_template']),
            $this->whatsappTemplate('cbna_step6_wa_benefits_fallback', 'CBNA Step 6 WhatsApp fallback', "Hi {{first_name}}, I just wanted to send this over in case it helps.\n\nA lot of people don’t realise there are ways to make things more manageable — like lowering monthly payments and taking the pressure off.\n\nIf you want to have a quick look, you can check where you stand here:\n{{portal_link}}\n\nOr just message me 👍"),
            $this->smsTemplate('cbna_sms_portal_intro', 'CBNA SMS portal intro', "Hi {{first_name}}, you can check where you stand, run a free credit check and go through things in your own time here — no login or passwords needed:\n\n{{portal_link}}"),
            $this->whatsappTemplate('cbna_whatsapp_portal_objection', 'CBNA WhatsApp portal objection', "Hi {{first_name}}, a lot of people aren’t sure if this actually applies to them at first.\n\nYou can just have a quick look and see where you stand here — it only takes a few minutes:\n\n{{portal_link}}"),
            $this->emailTemplate('cbna_email_trust_reassurance', 'CBNA email trust reassurance', 'd-03836a8e782c44328c1a5472764a3268', 'Check where you stand — takes a few minutes', 'Quick to do, no login needed — just have a look in your own time', $fromName),
            $this->whatsappTemplate('cbna_step9_wa_trust_fallback', 'CBNA Step 9 WhatsApp fallback', "Hi {{first_name}}, I just wanted to explain how this works, as a lot of people aren’t sure what to expect.\n\nIt starts with understanding your situation — there’s no obligation to go ahead with anything.\n\nIf something isn’t suitable, we’ll let you know and talk through other options.\n\nIf you’d rather, you can check where you stand here:\n{{portal_link}}\n\nOr just message me 👍"),
            $this->smsTemplate('cbna_sms_soft_exit', 'CBNA SMS soft exit', "Hi {{first_name}}, I’ll leave things with you for now.\n\nIf you do want help at any point, you can message me on WhatsApp here:\n{{whatsapp_link}}\n\nOr check where you stand here:\n{{portal_link}}"),
            $this->emailTemplate('cbna_email_nurture', 'CBNA email nurture', 'd-bf2f9063f21b42eba2be18df9277c9de', 'Just checking in', 'Just something to come back to if you’ve been meaning to look into it', $fromName),
            $this->whatsappTemplate('cbna_step12_wa_nurture_fallback', 'CBNA Step 12 WhatsApp fallback', "Hi {{first_name}}, I just wanted to check in as I know this is something people often come back to after a bit of time.\n\nIf it’s still on your mind, you can check where you stand here:\n{{portal_link}}\n\nOr just message me if you want to go over anything 👍"),
            $this->smsTemplate('cbna_sms_reengage_day38', 'CBNA SMS re-engage day 38', "Hi {{first_name}}, just checking in — if this is still something you want to look into, you can message me on WhatsApp here:\n{{whatsapp_link}}\n\nOr run your free credit check here:\n{{portal_link}}"),
            $this->emailTemplate('cbna_email_dormant_nurture', 'CBNA email dormant nurture', 'd-116d8075095b4998b93d1e057484444e', 'If things have changed at all', 'If this has been on your mind again, it might be worth a quick look', $fromName),
            $this->whatsappTemplate('cbna_step14_wa_dormant_fallback', 'CBNA Step 14 WhatsApp fallback', "Hi {{first_name}}, I just wanted to reach out again as things can change over time.\n\nIf this is something you’ve been thinking about again, you can check where you stand here:\n{{portal_link}}\n\nOr just message me if you’d rather talk it through 👍"),
            $this->smsTemplate('cbna_sms_final_close', 'CBNA SMS final close', "Hi {{first_name}}, I’ll close this off for now 👍\n\nIf you do want help at any point, just message me on WhatsApp:\n{{whatsapp_link}}\n\nOr you can check where you stand here:\n{{portal_link}}"),
        ];
    }

    private function stepPayloads(): array
    {
        return [
            $this->stepPayload(1, 1, 'cbna_sms_instant', 'fresh', 'sms', 'cbna_sms_instant', 0, false, true, 'Instant CBNA SMS', 'Immediate SMS after missed call attempt.'),
            $this->stepPayload(2, 2, 'cbna_whatsapp_60m', 'fresh', 'whatsapp', 'cbna_whatsapp_60m', 60, false, true, 'WhatsApp after 60 minutes', 'Follow-up WhatsApp one hour later.'),
            $this->stepPayload(3, 3, 'cbna_step3_email_explainer', 'fresh', 'email', 'cbna_step3_email_explainer', 240, false, true, 'Explainer email', 'Explains what the earlier call was about.', [
                'fallback_medium' => 'whatsapp',
                'fallback_template_key' => 'cbna_step3_wa_explainer_fallback',
                'fallback_condition' => 'lead_email_missing',
                'sendgrid_template_id' => 'd-f22fcf0b856b4d9f840d8b4722fc0385',
                'fallback_policy' => 'send_whatsapp_when_email_missing',
            ]),
            $this->stepPayload(4, 4, 'cbna_call_day1_morning', 'fresh', 'call', null, 1440, true, false, 'Day 1 morning call attempt', 'Manual follow-up call in morning window.', [
                'parent_step_key' => 'cbna_call_day1_dual',
                'call_window_label' => 'day1_morning_attempt',
                'call_window_start' => '10:00:00',
                'call_window_end' => '12:00:00',
                'metadata_append' => [
                    'substep' => '4a',
                    'reason' => 'CBNA follow-up call - day 1 morning',
                ],
            ]),
            $this->stepPayload(5, 4, 'cbna_call_day1_evening', 'fresh', 'call', null, 1440, true, false, 'Day 1 evening call attempt', 'Second manual attempt if no contact after morning call.', [
                'parent_step_key' => 'cbna_call_day1_dual',
                'call_window_label' => 'day1_evening_attempt',
                'call_window_start' => '17:30:00',
                'call_window_end' => '19:30:00',
                'metadata_append' => [
                    'substep' => '4b',
                    'condition' => 'no_contact_made_after_4a',
                    'reason' => 'CBNA follow-up call - day 1 evening',
                ],
            ]),
            $this->stepPayload(6, 5, 'cbna_whatsapp_value_shift', 'fresh', 'whatsapp', 'cbna_whatsapp_value_shift', 2880, false, true, 'Value-shift WhatsApp', 'Benefits/value reframing WhatsApp nudge.'),
            $this->stepPayload(7, 6, 'cbna_step6_email_benefits', 'fresh', 'email', 'cbna_step6_email_benefits', 5760, false, true, 'Benefits email (pending template)', 'Benefits-focused email placeholder until SendGrid template is ready.', [
                'fallback_medium' => 'whatsapp',
                'fallback_template_key' => 'cbna_step6_wa_benefits_fallback',
                'fallback_condition' => 'lead_email_missing',
                'sendgrid_template_id' => null,
                'fallback_policy' => 'send_whatsapp_when_email_missing',
                'metadata_append' => ['sendgrid_status' => 'pending_template'],
            ]),
            $this->stepPayload(8, 7, 'cbna_sms_portal_intro', 'cooling', 'sms', 'cbna_sms_portal_intro', 10080, false, true, 'Portal intro SMS', 'Introduces self-serve portal.'),
            $this->stepPayload(9, 8, 'cbna_whatsapp_portal_objection', 'cooling', 'whatsapp', 'cbna_whatsapp_portal_objection', 12960, false, true, 'Portal objection WhatsApp', 'Addresses common hesitation and links portal.'),
            $this->stepPayload(10, 9, 'cbna_email_trust_reassurance', 'cooling', 'email', 'cbna_email_trust_reassurance', 17280, false, true, 'Trust reassurance email', 'Trust/reassurance email touchpoint.', [
                'fallback_medium' => 'whatsapp',
                'fallback_template_key' => 'cbna_step9_wa_trust_fallback',
                'fallback_condition' => 'lead_email_missing',
                'sendgrid_template_id' => 'd-03836a8e782c44328c1a5472764a3268',
                'fallback_policy' => 'send_whatsapp_when_email_missing',
            ]),
            $this->stepPayload(11, 10, 'cbna_call_day14_morning', 'cooling', 'call', null, 20160, true, false, 'Day 14 morning final call', 'Manual final follow-up call in morning window.', [
                'parent_step_key' => 'cbna_call_day14_final_dual',
                'call_window_label' => 'final_morning_attempt',
                'call_window_start' => '10:00:00',
                'call_window_end' => '12:00:00',
                'metadata_append' => [
                    'substep' => '10a',
                    'reason' => 'CBNA final follow-up call - morning',
                ],
            ]),
            $this->stepPayload(12, 10, 'cbna_call_day14_evening', 'cooling', 'call', null, 20160, true, false, 'Day 14 evening final call', 'Second final call if no contact after morning attempt.', [
                'parent_step_key' => 'cbna_call_day14_final_dual',
                'call_window_label' => 'final_evening_attempt',
                'call_window_start' => '17:30:00',
                'call_window_end' => '19:30:00',
                'metadata_append' => [
                    'substep' => '10b',
                    'condition' => 'no_contact_made_after_10a',
                    'reason' => 'CBNA final follow-up call - evening',
                ],
            ]),
            $this->stepPayload(13, 11, 'cbna_sms_soft_exit', 'cold', 'sms', 'cbna_sms_soft_exit', 24480, false, true, 'Soft-exit SMS', 'Soft exit message with WhatsApp and portal routes.'),
            $this->stepPayload(14, 12, 'cbna_email_nurture', 'cold', 'email', 'cbna_email_nurture', 40320, false, true, 'Cold nurture email', 'Later-stage nurture email touch.', [
                'fallback_medium' => 'whatsapp',
                'fallback_template_key' => 'cbna_step12_wa_nurture_fallback',
                'fallback_condition' => 'lead_email_missing',
                'sendgrid_template_id' => 'd-bf2f9063f21b42eba2be18df9277c9de',
                'fallback_policy' => 'send_whatsapp_when_email_missing',
            ]),
            $this->stepPayload(15, 13, 'cbna_sms_reengage_day38', 'cold', 'sms', 'cbna_sms_reengage_day38', 54720, false, true, 'Day 38 re-engage SMS', 'Longer-gap re-engagement SMS.'),
            $this->stepPayload(16, 14, 'cbna_email_dormant_nurture', 'dormant', 'email', 'cbna_email_dormant_nurture', 64800, false, true, 'Dormant nurture email', 'Dormant-stage nurture email touch.', [
                'fallback_medium' => 'whatsapp',
                'fallback_template_key' => 'cbna_step14_wa_dormant_fallback',
                'fallback_condition' => 'lead_email_missing',
                'sendgrid_template_id' => 'd-116d8075095b4998b93d1e057484444e',
                'fallback_policy' => 'send_whatsapp_when_email_missing',
            ]),
            $this->stepPayload(17, 15, 'cbna_sms_final_close', 'dormant', 'sms', 'cbna_sms_final_close', 86400, false, true, 'Final close SMS', 'Final close-out touchpoint.'),
        ];
    }

    private function smsTemplate(string $key, string $name, string $bodyText): array
    {
        return [
            'template_key' => $key,
            'template_name' => $name,
            'medium' => 'sms',
            'provider' => 'twilio',
            'provider_template_id' => null,
            'subject' => null,
            'preview_text' => null,
            'body' => $bodyText,
            'body_text' => $bodyText,
            'body_html' => null,
            'external_template_id' => null,
            'variables_json' => self::GLOBAL_VARIABLES,
            'metadata_json' => $this->commonTemplateMetadata(),
            'is_active' => true,
        ];
    }

    private function whatsappTemplate(string $key, string $name, string $bodyText): array
    {
        return [
            'template_key' => $key,
            'template_name' => $name,
            'medium' => 'whatsapp',
            'provider' => 'whatsapp',
            'provider_template_id' => null,
            'subject' => null,
            'preview_text' => null,
            'body' => $bodyText,
            'body_text' => $bodyText,
            'body_html' => null,
            'external_template_id' => null,
            'variables_json' => self::GLOBAL_VARIABLES,
            'metadata_json' => $this->commonTemplateMetadata(),
            'is_active' => true,
        ];
    }

    private function emailTemplate(
        string $key,
        string $name,
        ?string $providerTemplateId,
        ?string $subject,
        ?string $previewText,
        string $fromName,
        array $metadataAppend = []
    ): array {
        return [
            'template_key' => $key,
            'template_name' => $name,
            'medium' => 'email',
            'provider' => 'sendgrid',
            'provider_template_id' => $providerTemplateId,
            'subject' => $subject,
            'preview_text' => $previewText,
            'body' => null,
            'body_text' => null,
            'body_html' => null,
            'external_template_id' => null,
            'variables_json' => self::GLOBAL_VARIABLES,
            'metadata_json' => array_merge(
                $this->commonTemplateMetadata(),
                ['from_name' => $fromName],
                $metadataAppend
            ),
            'is_active' => true,
        ];
    }

    private function commonTemplateMetadata(): array
    {
        return [
            'journey_label' => self::JOURNEY_LABEL,
            'flow_key' => self::FLOW_KEY,
            'click_to_call_display' => self::CLICK_TO_CALL_DISPLAY,
            'click_to_call_tel' => self::CLICK_TO_CALL_TEL,
            'whatsapp_number' => self::WHATSAPP_NUMBER,
        ];
    }

    private function normalizePayloadForDatabase(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $payload[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $payload;
    }

    private function stepPayload(
        int $stepOrder,
        int $businessStep,
        string $stepKey,
        string $stage,
        string $primaryMedium,
        ?string $primaryTemplateKey,
        int $delayMinutes,
        bool $requiresManualCompletion,
        bool $autoAdvanceOnSend,
        string $displayTitle,
        string $displayDescription,
        array $overrides = []
    ): array {
        $metadata = [
            'journey_label' => self::JOURNEY_LABEL,
            'business_step' => $businessStep,
            'display_title' => $displayTitle,
            'display_description' => $displayDescription,
        ];

        if (array_key_exists('fallback_policy', $overrides)) {
            $metadata['fallback_policy'] = $overrides['fallback_policy'];
        }

        if (array_key_exists('metadata_append', $overrides) && is_array($overrides['metadata_append'])) {
            $metadata = array_merge($metadata, $overrides['metadata_append']);
        }

        return [
            'step_order' => $stepOrder,
            'step_key' => $stepKey,
            'stage' => $stage,
            'primary_medium' => $primaryMedium,
            'primary_template_key' => $primaryTemplateKey,
            'fallback_medium' => $overrides['fallback_medium'] ?? null,
            'fallback_template_key' => $overrides['fallback_template_key'] ?? null,
            'fallback_condition' => $overrides['fallback_condition'] ?? null,
            'sendgrid_template_id' => $overrides['sendgrid_template_id'] ?? null,
            'call_window_label' => $overrides['call_window_label'] ?? null,
            'call_window_start' => $overrides['call_window_start'] ?? null,
            'call_window_end' => $overrides['call_window_end'] ?? null,
            'parent_step_key' => $overrides['parent_step_key'] ?? null,
            'is_manual' => $overrides['is_manual'] ?? $requiresManualCompletion,
            'delay_minutes' => $delayMinutes,
            'requires_manual_completion' => $requiresManualCompletion,
            'auto_advance_on_send' => $autoAdvanceOnSend,
            'display_title' => $displayTitle,
            'metadata_json' => $metadata,
        ];
    }
}

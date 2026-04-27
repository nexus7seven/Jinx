<?php

namespace Database\Seeders;

use App\Models\RemarketingStep;
use App\Models\RemarketingTemplate;
use Illuminate\Database\Seeder;

class RemarketingLinearFlowSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'template_key' => 'iva_intro_sms',
                'template_name' => 'IVA intro SMS',
                'medium' => 'sms',
                'provider' => 'twilio',
                'subject' => null,
                'body' => 'Hi {{first_name}}, it\'s Clear My Credit. We tried to reach you about reducing your debt payments. Reply here if you\'d like help.',
                'external_template_id' => null,
                'variables_json' => ['first_name'],
                'is_active' => true,
            ],
            [
                'template_key' => 'iva_benefits_email',
                'template_name' => 'IVA benefits email',
                'medium' => 'email',
                'provider' => 'sendgrid',
                'subject' => 'How an IVA could reduce your monthly debt payments',
                'body' => 'An IVA can make debt repayments more affordable and help reduce pressure from creditors. Reply if you would like support.',
                'external_template_id' => null,
                'variables_json' => ['first_name'],
                'is_active' => true,
            ],
            [
                'template_key' => 'self_serve_portal_sms',
                'template_name' => 'Self-serve portal SMS',
                'medium' => 'sms',
                'provider' => 'twilio',
                'subject' => null,
                'body' => 'Hi {{first_name}}, you can also check your options online here: {{portal_link}}',
                'external_template_id' => null,
                'variables_json' => ['first_name', 'portal_link'],
                'is_active' => true,
            ],
            [
                'template_key' => 'dormant_final_whatsapp',
                'template_name' => 'Dormant final WhatsApp',
                'medium' => 'whatsapp',
                'provider' => 'manual',
                'subject' => null,
                'body' => 'Hi {{first_name}}, just checking if you still want help with reducing your debt payments. If not, no problem.',
                'external_template_id' => null,
                'variables_json' => ['first_name'],
                'is_active' => true,
            ],
        ];

        $templateMap = [];
        foreach ($templates as $payload) {
            $template = RemarketingTemplate::updateOrCreate(
                ['template_key' => $payload['template_key']],
                $payload
            );
            $templateMap[$payload['template_key']] = $template->id;
        }

        $steps = [
            [
                'step_key' => 'fresh_sms_intro',
                'step_order' => 1,
                'step_name' => 'Fresh SMS intro',
                'medium' => 'sms',
                'template_id' => $templateMap['iva_intro_sms'],
                'template_name' => 'iva_intro_sms',
                'template_variable' => null,
                'delay_minutes' => 0,
                'requires_manual_completion' => false,
                'auto_advance_on_send' => true,
                'respect_send_window' => true,
                'send_window_start_time' => '09:00:00',
                'send_window_end_time' => '21:00:00',
                'allowed_days_json' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
                'stop_if_replied' => true,
                'stop_if_converted' => true,
                'is_active' => true,
            ],
            [
                'step_key' => 'fresh_email_benefits',
                'step_order' => 2,
                'step_name' => 'Fresh IVA benefits email',
                'medium' => 'email',
                'template_id' => $templateMap['iva_benefits_email'],
                'template_name' => 'iva_benefits_email',
                'template_variable' => null,
                'delay_minutes' => 180,
                'requires_manual_completion' => false,
                'auto_advance_on_send' => true,
                'respect_send_window' => true,
                'send_window_start_time' => '09:00:00',
                'send_window_end_time' => '21:00:00',
                'allowed_days_json' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
                'stop_if_replied' => true,
                'stop_if_converted' => true,
                'is_active' => true,
            ],
            [
                'step_key' => 'fresh_call_follow_up',
                'step_order' => 3,
                'step_name' => 'Fresh follow-up call',
                'medium' => 'call',
                'template_id' => null,
                'template_name' => null,
                'template_variable' => null,
                'delay_minutes' => 1440,
                'requires_manual_completion' => true,
                'auto_advance_on_send' => false,
                'respect_send_window' => true,
                'send_window_start_time' => '09:00:00',
                'send_window_end_time' => '21:00:00',
                'allowed_days_json' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
                'stop_if_replied' => true,
                'stop_if_converted' => true,
                'is_active' => true,
            ],
            [
                'step_key' => 'cooling_sms_portal',
                'step_order' => 4,
                'step_name' => 'Cooling self-serve portal SMS',
                'medium' => 'sms',
                'template_id' => $templateMap['self_serve_portal_sms'],
                'template_name' => 'self_serve_portal_sms',
                'template_variable' => null,
                'delay_minutes' => 2880,
                'requires_manual_completion' => false,
                'auto_advance_on_send' => true,
                'respect_send_window' => true,
                'send_window_start_time' => '09:00:00',
                'send_window_end_time' => '21:00:00',
                'allowed_days_json' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
                'stop_if_replied' => true,
                'stop_if_converted' => true,
                'is_active' => true,
            ],
            [
                'step_key' => 'dormant_final_whatsapp',
                'step_order' => 5,
                'step_name' => 'Dormant final WhatsApp',
                'medium' => 'whatsapp',
                'template_id' => $templateMap['dormant_final_whatsapp'],
                'template_name' => 'dormant_final_whatsapp',
                'template_variable' => null,
                'delay_minutes' => 10080,
                'requires_manual_completion' => true,
                'auto_advance_on_send' => false,
                'respect_send_window' => true,
                'send_window_start_time' => '09:00:00',
                'send_window_end_time' => '21:00:00',
                'allowed_days_json' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
                'stop_if_replied' => true,
                'stop_if_converted' => true,
                'is_active' => true,
            ],
        ];

        foreach ($steps as $payload) {
            RemarketingStep::updateOrCreate(
                ['step_key' => $payload['step_key']],
                array_merge($payload, [
                    'step_date_modified' => now(),
                    'step_date_added' => RemarketingStep::query()
                        ->where('step_key', $payload['step_key'])
                        ->value('step_date_added') ?? now(),
                ])
            );
        }
    }
}

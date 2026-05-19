<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Partner;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class PartnerLeadTeamsNotificationService
{
    private const PAYLOAD_MODE_TEXT = 'text';


    private const PAYLOAD_MODE_ADAPTIVE_CARD_ATTACHMENTS = 'adaptive_card_attachments';
    public function notify(Partner $partner, Lead $lead, bool $isDuplicate = false): void
    {
        if ($partner->name !== 'IS SUBMISSIONS') {
            return;
        }

        $webhookUrl = $this->webhookUrl();

        if ($webhookUrl === '') {
            return;
        }

        $submissionReference = $lead->vicidial_lead_id ?: $lead->id;
        $customerName = trim($lead->first_name.' '.$lead->last_name);
        $customerName = $customerName !== '' ? $customerName : 'Unknown customer';
        $phone = filled($lead->phone_number) ? $lead->phone_number : '—';
        $submittedBy = filled($lead->submitted_by_vicidial_user) ? $lead->submitted_by_vicidial_user : '—';
        $notes = filled($lead->case_notes) ? $lead->case_notes : '—';

        $payloadData = [
            'title' => '🚨 TRANSFER INCOMING',
            'partner' => 'IS SUBMISSIONS',
            'type' => $isDuplicate ? 'Duplicate lead' : 'New lead',
            'submissionReference' => $submissionReference,
            'leadId' => (string) $lead->id,
            'customerName' => $customerName,
            'phone' => $phone,
            'submittedBy' => $submittedBy,
            'notes' => $notes,
            'staffLink' => 'https://jinx.hextech.lol/lead/'.$lead->id,
        ];

        $message = implode("\n", [
            '🚨 TRANSFER INCOMING',
            '',
            'Partner: IS SUBMISSIONS',
            'Type: '.$payloadData['type'],
            'Submission Reference: '.$payloadData['submissionReference'],
            'Jinx Lead ID: '.$payloadData['leadId'],
            'Customer: '.$payloadData['customerName'],
            'Phone: '.$payloadData['phone'],
            'Submitted by: '.$payloadData['submittedBy'],
            'Notes: '.$payloadData['notes'],
            'Staff link: '.$payloadData['staffLink'],
        ]);

        try {
            $content = $this->payloadMode() === self::PAYLOAD_MODE_TEXT
                ? $message
                : $this->buildAdaptiveCard($payloadData);

            $this->sendPayload($this->buildPayload($content))->throw();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function sendRawTestMessage(string $message): Response
    {
        return $this->sendPayload($this->buildPayload($message));
    }

    public function sendRawTestAdaptiveCard(string $title, string $body): Response
    {
        return $this->sendPayload($this->buildPayload($this->buildAdaptiveCard([
            'title' => $title,
            'partner' => 'IS SUBMISSIONS',
            'type' => 'Test lead',
            'submissionReference' => 'TEST-REFERENCE',
            'leadId' => 'TEST-LEAD-ID',
            'customerName' => 'Test Customer',
            'phone' => '—',
            'submittedBy' => '—',
            'notes' => $body,
            'staffLink' => 'https://jinx.hextech.lol/lead/test',
        ])));
    }

    public function webhookIsConfigured(): bool
    {
        return $this->webhookUrl() !== '';
    }

    public function payloadKey(): string
    {
        $payloadKey = trim((string) config('services.teams_partner_lead.payload_key', 'text'));

        return $payloadKey !== '' ? $payloadKey : 'text';
    }

    private function webhookUrl(): string
    {
        return trim((string) config('services.teams_partner_lead.webhook_url', ''));
    }

    public function payloadMode(): string
    {
        $payloadMode = strtolower(trim((string) config('services.teams_partner_lead.payload_mode', self::PAYLOAD_MODE_TEXT)));

        return $payloadMode !== '' ? $payloadMode : self::PAYLOAD_MODE_TEXT;
    }

    private function sendPayload(array $payload): Response
    {
        return Http::timeout(10)
            ->acceptJson()
            ->asJson()
            ->post($this->webhookUrl(), $payload);
    }

    private function buildPayload(string|array $content): array
    {
        if ($this->payloadMode() === self::PAYLOAD_MODE_ADAPTIVE_CARD_ATTACHMENTS) {
            return [
                'attachments' => [
                    [
                        'contentType' => 'application/vnd.microsoft.card.adaptive',
                        'content' => is_array($content) ? $content : ['type' => 'TextBlock', 'text' => $content],
                    ],
                ],
            ];
        }

        return [
            $this->payloadKey() => $content,
        ];
    }

    private function buildAdaptiveCard(array $data): array
    {
        return [
            'type' => 'AdaptiveCard',
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'version' => '1.4',
            'body' => [
                [
                    'type' => 'TextBlock',
                    'text' => $data['title'],
                    'weight' => 'Bolder',
                    'size' => 'Large',
                    'color' => 'Attention',
                ],
                [
                    'type' => 'FactSet',
                    'facts' => [
                        ['title' => 'Partner', 'value' => $data['partner']],
                        ['title' => 'Type', 'value' => $data['type']],
                        ['title' => 'Reference', 'value' => $data['submissionReference']],
                        ['title' => 'Jinx Lead ID', 'value' => $data['leadId']],
                        ['title' => 'Customer', 'value' => $data['customerName']],
                        ['title' => 'Phone', 'value' => $data['phone']],
                        ['title' => 'Submitted by', 'value' => $data['submittedBy']],
                    ],
                ],
                [
                    'type' => 'TextBlock',
                    'text' => 'Notes:',
                    'weight' => 'Bolder',
                    'wrap' => true,
                ],
                [
                    'type' => 'TextBlock',
                    'text' => $data['notes'],
                    'wrap' => true,
                ],
            ],
            'actions' => [
                [
                    'type' => 'Action.OpenUrl',
                    'title' => 'Open Jinx Lead',
                    'url' => $data['staffLink'],
                ],
            ],
        ];
    }
}

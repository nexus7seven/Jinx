<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Partner;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class PartnerLeadTeamsNotificationService
{
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

        $message = implode("\n", [
            '🚨 TRANSFER INCOMING',
            '',
            'Partner: IS SUBMISSIONS',
            'Type: '.($isDuplicate ? 'Duplicate lead' : 'New lead'),
            'Submission Reference: '.$submissionReference,
            'Jinx Lead ID: '.$lead->id,
            'Customer: '.$customerName,
            'Phone: '.$phone,
            'Submitted by: '.$submittedBy,
            'Notes: '.$notes,
            'Staff link: https://jinx.hextech.lol/lead/'.$lead->id,
        ]);

        try {
            $this->sendMessage($message)->throw();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function sendRawTestMessage(string $message): Response
    {
        return $this->sendMessage($message);
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

    private function sendMessage(string $message): Response
    {
        return Http::timeout(10)
            ->acceptJson()
            ->asJson()
            ->post($this->webhookUrl(), [
                $this->payloadKey() => $message,
            ]);
    }
}

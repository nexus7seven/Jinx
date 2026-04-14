<?php

namespace App\Services;

use App\Support\VicidialDialPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RemarketingCallbackService
{
    /**
     * @param  array{lead_id:mixed,phone_number:mixed,campaign_id:mixed}  $payload
     * @return array{ok:bool,message:string,popup_confirmed:bool,lead_id:int,phone:?string}
     */
    public function dialLead(array $payload): array
    {
        $leadId = (int) ($payload['lead_id'] ?? 0);
        $campaignId = trim((string) ($payload['campaign_id'] ?? ''));
        $rawPhone = (string) ($payload['phone_number'] ?? '');
        $nationalDigits = VicidialDialPhone::nationalDigits($rawPhone);

        if ($leadId <= 0) {
            return $this->result(false, 'Missing lead_id.', false, $leadId, $rawPhone);
        }
        if ($campaignId === '') {
            return $this->result(false, 'Missing campaign_id.', false, $leadId, $rawPhone);
        }
        if ($nationalDigits === null || $nationalDigits === '') {
            return $this->result(false, 'Lead has no usable phone number.', false, $leadId, $rawPhone);
        }

        $apiUrl = (string) config('services.vicidial.api_url');
        $apiUser = (string) config('services.vicidial.api_user');
        $apiPass = (string) config('services.vicidial.api_pass');
        $agentUser = (string) config('services.vicidial.agent_user');
        $source = (string) config('services.vicidial.remarketing_source', 'remarketing');

        if ($apiUrl === '' || $apiUser === '' || $apiPass === '' || $agentUser === '') {
            return $this->result(false, 'VICIdial API is not configured.', false, $leadId, $rawPhone);
        }

        $this->sendApiRequest($apiUrl, [
            'source' => $source,
            'user' => $apiUser,
            'pass' => $apiPass,
            'agent_user' => $agentUser,
            'function' => 'external_pause',
            'value' => 'PAUSE',
            'pause_code' => 'CALLBACK',
        ]);

        $dialResponse = $this->sendApiRequest($apiUrl, [
            'source' => $source,
            'user' => $apiUser,
            'pass' => $apiPass,
            'agent_user' => $agentUser,
            'function' => 'external_dial',
            'value' => $nationalDigits,
            'phone_code' => '44',
            'search' => 'YES',
            'preview' => 'NO',
            'focus' => 'YES',
            'lead_id' => $leadId,
            'campaign' => $campaignId,
        ]);

        if ($dialResponse === null || stripos($dialResponse, 'SUCCESS') === false) {
            Log::warning('Remarketing callback external_dial failed', [
                'lead_id' => $leadId,
                'campaign_id' => $campaignId,
                'phone' => $nationalDigits,
                'response' => $dialResponse,
            ]);

            return $this->result(false, 'VICIdial external_dial failed.', false, $leadId, $nationalDigits);
        }

        $popupConfirmed = $this->confirmPopupLead($agentUser, $leadId);

        if (! $popupConfirmed) {
            return $this->result(true, 'Call started, but popup not confirmed yet.', false, $leadId, $nationalDigits);
        }

        return $this->result(true, 'Call started successfully.', true, $leadId, $nationalDigits);
    }

    private function sendApiRequest(string $url, array $params): ?string
    {
        try {
            return Http::timeout(30)
                ->acceptJson()
                ->get($url, $params)
                ->body();
        } catch (Throwable $e) {
            Log::error('Remarketing callback API request failed', [
                'url' => $url,
                'function' => $params['function'] ?? null,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function confirmPopupLead(string $agentUser, int $leadId): bool
    {
        $connection = config('services.vicidial.db_connection');

        for ($i = 0; $i < 15; $i++) {
            usleep(200000);

            try {
                $query = DB::connection($connection)
                    ->table('vicidial_live_agents')
                    ->select('lead_id')
                    ->where('user', $agentUser)
                    ->first();

                if ($query && (int) ($query->lead_id ?? 0) === $leadId) {
                    return true;
                }
            } catch (Throwable $e) {
                Log::warning('Remarketing callback popup check failed', [
                    'connection' => $connection,
                    'agent_user' => $agentUser,
                    'lead_id' => $leadId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                return false;
            }
        }

        return false;
    }

    /**
     * @return array{ok:bool,message:string,popup_confirmed:bool,lead_id:int,phone:?string}
     */
    private function result(bool $ok, string $message, bool $popupConfirmed, int $leadId, ?string $phone): array
    {
        return [
            'ok' => $ok,
            'message' => $message,
            'popup_confirmed' => $popupConfirmed,
            'lead_id' => $leadId,
            'phone' => $phone,
        ];
    }
}

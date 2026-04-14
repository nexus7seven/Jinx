<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\RemarketingTask;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RemarketingTaskService
{
    public function resolveStageFromHours(float|int $hoursSinceLastActivity): string
    {
        $hours = (float) $hoursSinceLastActivity;

        if ($hours < 24) {
            return 'fresh';
        }

        if ($hours < 72) {
            return 'cooling';
        }

        if ($hours < 336) {
            return 'cold';
        }

        return 'dormant';
    }

    /**
     * @param  array<string, mixed>  $data
     * @throws ValidationException
     */
    public function createCallTask(array $data): RemarketingTask
    {
        $payload = $this->normalize($data);
        $validator = Validator::make($payload, [
            'lead_id' => ['required', 'integer'],
            'lead_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
            'campaign_id' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:255'],
            'stage' => ['required', 'in:fresh,cooling,cold,dormant'],
            'time_waiting_text' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:pending'],
        ]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        $payload = $validator->validated();
        $payload['task_type'] = 'call';

        return RemarketingTask::create($payload);
    }

    /**
     * @param  array<string, mixed>  $data
     * @throws ValidationException
     */
    public function createWhatsAppTask(array $data): RemarketingTask
    {
        $payload = $this->normalize($data);
        $validator = Validator::make($payload, [
            'lead_id' => ['nullable', 'integer'],
            'lead_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
            'campaign_id' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:255'],
            'stage' => ['required', 'in:fresh,cooling,cold,dormant'],
            'time_waiting_text' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:pending'],
        ]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        $payload = $validator->validated();
        $payload['task_type'] = 'whatsapp';

        return RemarketingTask::create($payload);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @throws ValidationException
     */
    public function createCallTaskForLead(Lead $lead, array $overrides = []): RemarketingTask
    {
        return $this->createCallTask(array_merge($this->payloadFromLead($lead), $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @throws ValidationException
     */
    public function createWhatsAppTaskForLead(Lead $lead, array $overrides = []): RemarketingTask
    {
        return $this->createWhatsAppTask(array_merge($this->payloadFromLead($lead), $overrides));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function normalize(array $data): array
    {
        return [
            'lead_id' => isset($data['lead_id']) && $data['lead_id'] !== ''
                ? (int) $data['lead_id']
                : null,
            'lead_name' => trim((string) ($data['lead_name'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'campaign_id' => isset($data['campaign_id']) && $data['campaign_id'] !== ''
                ? trim((string) $data['campaign_id'])
                : null,
            'reason' => trim((string) ($data['reason'] ?? '')),
            'stage' => trim((string) ($data['stage'] ?? '')),
            'time_waiting_text' => isset($data['time_waiting_text']) && $data['time_waiting_text'] !== null
                ? trim((string) $data['time_waiting_text'])
                : null,
            'status' => 'pending',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFromLead(Lead $lead): array
    {
        $first = trim((string) ($lead->first_name ?? ''));
        $last = trim((string) ($lead->last_name ?? ''));
        $fullName = trim($first . ' ' . $last);

        return [
            'lead_id' => is_numeric($lead->vicidial_lead_id) ? (int) $lead->vicidial_lead_id : null,
            'lead_name' => $fullName !== '' ? $fullName : ('Lead #' . $lead->id),
            'phone' => (string) ($lead->phone_number ?? ''),
            'campaign_id' => null,
            'reason' => '',
            'stage' => 'fresh',
            'time_waiting_text' => null,
        ];
    }
}

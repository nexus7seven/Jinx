<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\RemarketingTask;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RemarketingTaskService
{
    /**
     * @var array<string, string>
     */
    private const REASON_TEMPLATES = [
        'missed_call' => 'Missed call follow-up',
        'no_answer' => 'No answer follow-up',
        'callback_follow_up' => 'Callback follow-up',
        'whatsapp_follow_up' => 'WhatsApp follow-up',
        'cooling_whatsapp_follow_up' => 'Cooling stage WhatsApp follow-up',
        'cooling_sms_follow_up' => 'Cooling stage SMS follow-up',
        'cooling_email_3_follow_up' => 'Cooling stage Email 3 follow-up',
        'cold_whatsapp_follow_up' => 'Cold stage WhatsApp follow-up',
        'cold_sms_follow_up' => 'Cold stage SMS follow-up',
        'cold_email_portal_push' => 'Cold stage portal push email',
        'dormant_email_iva_reengagement' => 'Dormant stage IVA re-engagement email',
        'dormant_sms_portal_nudge' => 'Dormant stage SMS portal nudge',
        'stale_lead_follow_up' => 'Stale lead follow-up',
        'cooling_call_follow_up' => 'Cooling stage call follow-up',
    ];

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

    public function resolveReason(string $reasonKey): string
    {
        $key = trim($reasonKey);

        return self::REASON_TEMPLATES[$key] ?? 'Remarketing follow-up';
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
     * @param  array<string, mixed>  $overrides
     * @throws ValidationException
     */
    public function createTaskForLeadTrigger(
        Lead $lead,
        string $taskType,
        string $reasonKey,
        array $overrides = []
    ): RemarketingTask {
        return $this->createTaskForLeadTriggerWithResult($lead, $taskType, $reasonKey, $overrides)['task'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{task: RemarketingTask, was_created: bool}
     * @throws ValidationException
     */
    public function createTaskForLeadTriggerWithResult(
        Lead $lead,
        string $taskType,
        string $reasonKey,
        array $overrides = []
    ): array {
        $type = trim(strtolower($taskType));
        $payloadOverrides = $overrides;
        $basePayload = $this->payloadFromLead($lead);

        $leadId = isset($payloadOverrides['lead_id']) && $payloadOverrides['lead_id'] !== ''
            ? (int) $payloadOverrides['lead_id']
            : ($basePayload['lead_id'] !== null ? (int) $basePayload['lead_id'] : null);

        if (! isset($payloadOverrides['reason']) || trim((string) $payloadOverrides['reason']) === '') {
            $payloadOverrides['reason'] = $this->resolveReason($reasonKey);
        }
        $dedupeReason = trim((string) ($payloadOverrides['reason'] ?? ''));

        if ($leadId !== null && in_array($type, ['call', 'whatsapp'], true)) {
            $existingPending = RemarketingTask::query()
                ->where('lead_id', $leadId)
                ->where('task_type', $type)
                ->where('status', 'pending')
                ->where('reason', $dedupeReason)
                ->first();

            if ($existingPending) {
                return [
                    'task' => $existingPending,
                    'was_created' => false,
                ];
            }
        }

        if ($type === 'call') {
            return [
                'task' => $this->createCallTaskForLead($lead, array_merge($basePayload, $payloadOverrides)),
                'was_created' => true,
            ];
        }

        if ($type === 'whatsapp') {
            return [
                'task' => $this->createWhatsAppTaskForLead($lead, array_merge($basePayload, $payloadOverrides)),
                'was_created' => true,
            ];
        }

        throw new InvalidArgumentException('Unsupported remarketing task type: ' . $taskType);
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
            // IMPORTANT: remarketing_tasks.lead_id stores VICIdial lead_id (lead.vicidial_lead_id),
            // not the local Jinx leads.id primary key.
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

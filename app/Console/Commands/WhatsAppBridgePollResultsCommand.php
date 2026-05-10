<?php

namespace App\Console\Commands;

use App\Models\RemarketingTask;
use App\Services\RemarketingProgressionService;
use App\Services\WhatsAppBridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WhatsAppBridgePollResultsCommand extends Command
{
    protected $signature = 'whatsapp-bridge:poll-results {--limit=50 : Max records to inspect in future implementation}';

    protected $description = 'Poll WhatsApp bridge send results and complete matching pending WhatsApp tasks.';

    public function __construct(
        private WhatsAppBridgeClient $bridgeClient,
        private RemarketingProgressionService $remarketingProgressionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $bridgeResult = $this->bridgeClient->fetchSendResults($limit);

        if (! ($bridgeResult['ok'] ?? false)) {
            $this->error('Unable to fetch WhatsApp bridge send results.');
            $this->line('Error: '.(string) ($bridgeResult['error'] ?? 'unknown_error'));

            return self::FAILURE;
        }

        $payload = $bridgeResult['response'] ?? [];
        $results = $this->extractResults($payload);

        if (empty($results)) {
            $this->info('No WhatsApp bridge send results returned.');

            return self::SUCCESS;
        }

        $completedCount = 0;

        foreach ($results as $result) {
            $jobId = (string) ($result['id'] ?? $result['job_id'] ?? '');
            $success = (bool) ($result['success'] ?? false);
            $reason = strtolower(trim((string) ($result['reason'] ?? '')));

            if (! preg_match('/^whatsapp-task:(\d+):(.+)$/', $jobId, $matches)) {
                Log::info('[whatsapp-bridge] skipping non-task job result', ['job_id' => $jobId, 'result' => $result]);
                continue;
            }

            $remarketingTaskId = (int) $matches[1];
            $expectedStepKey = trim((string) $matches[2]);

            $task = RemarketingTask::query()
                ->where('id', $remarketingTaskId)
                ->where('task_type', 'whatsapp')
                ->first();

            if (! $task) {
                Log::warning('[whatsapp-bridge] result references missing whatsapp task', [
                    'job_id' => $jobId,
                    'remarketing_task_id' => $remarketingTaskId,
                ]);
                continue;
            }

            if ($task->status !== RemarketingTask::STATUS_PENDING) {
                Log::info('[whatsapp-bridge] skipping non-pending task completion', [
                    'job_id' => $jobId,
                    'remarketing_task_id' => $remarketingTaskId,
                    'task_status' => $task->status,
                ]);
                continue;
            }

            if (! $success) {
                if ($reason === 'not_on_whatsapp') {
                    Log::warning('[whatsapp-bridge] recipient not on whatsapp; task left pending for fallback handling', [
                        'job_id' => $jobId,
                        'remarketing_task_id' => $remarketingTaskId,
                    ]);
                } else {
                    Log::warning('[whatsapp-bridge] whatsapp bridge send failed; task left pending', [
                        'job_id' => $jobId,
                        'remarketing_task_id' => $remarketingTaskId,
                        'reason' => $reason !== '' ? $reason : 'unknown',
                    ]);
                }

                continue;
            }

            $completion = $this->remarketingProgressionService->completeManualStepForLead(
                leadId: (int) $task->lead_id,
                expectedStepKey: $expectedStepKey !== 'none' ? $expectedStepKey : null,
                metadata: [
                    'mode' => 'whatsapp_bridge_result_complete',
                    'source' => 'whatsapp-bridge:poll-results',
                    'bridge_job_id' => $jobId,
                ]
            );

            if (! ($completion['ok'] ?? false)) {
                Log::warning('[whatsapp-bridge] failed to complete task from successful bridge result', [
                    'job_id' => $jobId,
                    'remarketing_task_id' => $remarketingTaskId,
                    'lead_id' => $task->lead_id,
                    'message' => $completion['message'] ?? null,
                ]);
                continue;
            }

            $task->status = RemarketingTask::STATUS_COMPLETED;
            $task->save();
            $completedCount++;
        }

        $this->info("Processed bridge results. Completed tasks: {$completedCount}.");

        return self::SUCCESS;
    }

    private function extractResults(mixed $payload): array
    {
        if (is_array($payload) && isset($payload['results']) && is_array($payload['results'])) {
            return $payload['results'];
        }

        if (is_array($payload) && array_is_list($payload)) {
            return $payload;
        }

        return [];
    }
}

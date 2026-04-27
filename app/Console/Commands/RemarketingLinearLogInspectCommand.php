<?php

namespace App\Console\Commands;

use App\Models\LeadRemarketingStepLog;
use Illuminate\Console\Command;

class RemarketingLinearLogInspectCommand extends Command
{
    protected $signature = 'remarketing:linear-log-inspect
        {--lead_id= : Optional vicidial lead_id}
        {--limit=50 : Max logs to show}
        {--json : Output JSON}';

    protected $description = 'Read-only inspector for linear remarketing step logs.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $leadId = $this->option('lead_id');
        $asJson = (bool) $this->option('json');

        $query = LeadRemarketingStepLog::query()
            ->with(['remarketingStep.template', 'template'])
            ->orderByDesc('id')
            ->limit($limit);

        if ($leadId !== null && $leadId !== '') {
            $query->where('lead_id', (int) $leadId);
        }

        $logs = $query->get();

        $rows = $logs->map(function (LeadRemarketingStepLog $log): array {
            $step = $log->remarketingStep;
            $template = $log->template ?? $step?->template;

            return [
                'id' => $log->id,
                'lead_id' => $log->lead_id,
                'step_order' => $log->step_order ?? $step?->step_order,
                'step_key' => $step?->step_key,
                'step_name' => $step?->step_name,
                'medium' => $log->medium ?? $step?->medium,
                'template_key' => $template?->template_key,
                'status' => $log->status,
                'due_at' => $log->due_at?->format('Y-m-d H:i:s'),
                'started_at' => $log->started_at?->format('Y-m-d H:i:s'),
                'completed_at' => $log->completed_at?->format('Y-m-d H:i:s'),
                'failed_at' => $log->failed_at?->format('Y-m-d H:i:s'),
                'provider_message_id' => $log->provider_message_id,
                'created_task_id' => $log->created_task_id,
                'error_message' => $log->error_message,
                'context_json' => $log->context_json,
            ];
        })->values()->all();

        if ($asJson) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $stepText = $row['step_order'] === null
                ? '-'
                : $row['step_order'].' '.($row['step_key'] ?? '-').' - '.($row['step_name'] ?? '-');

            $this->line('Log ID: '.$row['id']);
            $this->line('Lead ID: '.$row['lead_id']);
            $this->line('Step: '.$stepText);
            $this->line('Medium: '.($row['medium'] ?? '-'));
            $this->line('Template: '.($row['template_key'] ?? '-'));
            $this->line('Status: '.$row['status']);
            $this->line('Due at: '.($row['due_at'] ?? '-'));
            $this->line('Started at: '.($row['started_at'] ?? '-'));
            $this->line('Completed at: '.($row['completed_at'] ?? '-'));
            $this->line('Provider message ID: '.($row['provider_message_id'] ?? '-'));
            $this->line('Created task ID: '.($row['created_task_id'] ?? '-'));
            $this->line('Context: '.json_encode($row['context_json'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES));
            $this->line('');
            $this->line(str_repeat('-', 50));
            $this->line('');
        }

        $this->line('Summary:');
        $this->line('Total logs shown: '.count($rows));

        return self::SUCCESS;
    }
}

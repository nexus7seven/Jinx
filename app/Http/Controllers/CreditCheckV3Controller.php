<?php

namespace App\Http\Controllers;

use App\Models\CreditCheckJobLog;
use App\Models\Lead;
use App\Models\VotingPractice;
use App\Services\CreditCheckV3FlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CreditCheckV3Controller extends Controller
{
    public function __construct(
        private CreditCheckV3FlowService $creditCheckV3FlowService
    ) {}

    public function page(Lead $lead)
    {
        return view('leads.credit-check-v3', compact('lead'));
    }

    public function run(Lead $lead): JsonResponse
    {
        $result = $this->creditCheckV3FlowService->startForLead($lead, auth()->id());

        return response()->json($result['payload'], $result['http']);
    }

    public function status(string $jobId): JsonResponse
    {
        $result = $this->creditCheckV3FlowService->statusForJob($jobId);

        return response()->json($result['payload'], $result['http']);
    }

    public function panelPoll(Lead $lead): JsonResponse
    {
        $result = $this->creditCheckV3FlowService->panelPollForLead(
            $lead,
            function (Lead $pollLead, CreditCheckJobLog $log, array $bundle): void {
                $this->maybePanelAutoImport($pollLead, $log, $bundle);
            }
        );

        return response()->json($result['payload']);
    }

    public function debtsSectionHtml(Lead $lead)
    {
        $lead->load(['debts.creditor', 'debts.document']);
        $practices = VotingPractice::orderBy('id')->get();

        return response()->view('leads.partials.lead-debts-section-inner', compact('lead', 'practices'));
    }

    public function submitAnswers(Request $request, string $jobId): JsonResponse
    {
        $validated = $request->validate([
            'answers' => ['required', 'array'],
        ]);

        Log::info('Agent credit-check v3 answers payload sample', [
            'job_id' => $jobId,
            'answers' => $validated['answers'],
        ]);

        $result = $this->creditCheckV3FlowService->submitAnswersForJob($jobId, $validated['answers']);

        return response()->json($result['payload'], $result['http']);
    }

    public function importReportData(Lead $lead, string $jobId): JsonResponse
    {
        Log::info('credit_check_v3_controller_import_delegating', [
            'lead_id' => $lead->id,
            'job_id' => $jobId,
        ]);

        $result = $this->creditCheckV3FlowService->importReportDataForLead($lead, $jobId);

        return response()->json(
            $result['payload'],
            $result['http'] ?? 500
        );
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    private function maybePanelAutoImport(Lead $lead, CreditCheckJobLog $log, array $bundle): void
    {
        Log::info('credit_check_v3_panel_auto_import_check', [
            'lead_id' => $lead->id,
            'job_id' => (string) $log->external_job_id,
            'log_id' => $log->id,
            'log_status' => $log->status,
        ]);

        if (! $this->creditCheckV3FlowService->shouldAttemptImportFromBundle($log, $bundle)) {
            return;
        }

        Log::info('credit_check_v3_controller_import_delegating', [
            'lead_id' => $lead->id,
            'job_id' => (string) $log->external_job_id,
        ]);

        $result = $this->creditCheckV3FlowService->importReportDataForLead($lead, (string) $log->external_job_id);
        if (! ($result['payload']['ok'] ?? false)) {
            $log->refresh();
            $rj = is_array($log->result_json) ? $log->result_json : [];
            $rj['panel_import_failed'] = true;
            $rj['panel_import_error'] = $result['payload']['message'] ?? 'Import failed';
            $log->update([
                'result_json' => $rj,
                'error_message' => (string) ($result['payload']['message'] ?? 'Import failed'),
            ]);

            return;
        }

        $log->refresh();
    }
}

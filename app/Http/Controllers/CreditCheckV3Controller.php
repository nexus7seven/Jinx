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
        $result = $this->creditCheckV3FlowService->panelPollForLead($lead);

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
        $log = CreditCheckJobLog::query()
            ->where('lead_id', $lead->id)
            ->where('external_job_id', $jobId)
            ->orderByDesc('id')
            ->first();

        if (! $log) {
            return response()->json([
                'ok' => false,
                'message' => 'Credit check job log not found for lead/job.',
            ], 404);
        }

        $result = $this->creditCheckV3FlowService->importFromJobLog($log);

        return response()->json(
            $result['payload'],
            $result['http'] ?? 500
        );
    }
}

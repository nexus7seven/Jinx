<?php

namespace App\Services;

use App\Mail\LeadPortalSummaryMail;
use App\Models\LeadPortalSnapshot;
use App\Models\LeadPortalToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

class LeadPortalCompletionService
{
    public function __construct(
        private readonly LeadPortalProgressService $leadPortalProgressService,
        private readonly LeadPortalTokenService $leadPortalTokenService,
        private readonly LeadPortalDebtPresenter $leadPortalDebtPresenter,
        private readonly LeadPortalIvaEstimateService $leadPortalIvaEstimateService
    ) {
    }

    public function complete(LeadPortalToken $portalToken): LeadPortalSnapshot
    {
        $snapshot = DB::transaction(function () use ($portalToken): LeadPortalSnapshot {
            $portalToken->loadMissing([
                'lead.portalDebts',
                'lead.portalProgress',
            ]);

            $lead = $portalToken->lead;
            $canonicalDebts = $lead->debts()
                ->with('creditor')
                ->whereIn('source_expected', ['credit_check', 'customer_added'])
                ->orderBy('id')
                ->get();
            $totalDebt = $canonicalDebts->sum(fn ($debt) => (float) ($debt->balance ?? 0));
            $progress = $this->leadPortalProgressService->ensureForLead($lead);
            $previousStep = in_array((string) $progress->current_step, ['complete_pending', 'review'], true)
                ? (string) $progress->current_step
                : 'complete_pending';

            $snapshot = LeadPortalSnapshot::create([
                'lead_id' => $lead->id,
                'snapshot_json' => [
                    'lead_id' => $lead->id,
                    'completed_at' => now()->toIso8601String(),
                    'details' => [
                        'first_name' => $lead->first_name,
                        'last_name' => $lead->last_name,
                        'email' => $lead->email,
                        'phone_number' => $lead->phone_number,
                        'postcode' => $lead->postcode,
                        'house_number' => $lead->house_number,
                        'address_line_1' => $lead->address_line_1,
                    ],
                    'debts' => $canonicalDebts->map(fn ($debt) => [
                        'creditor_name_customer' => $this->leadPortalDebtPresenter->customerFacingCreditorName($debt),
                        'creditor_name_backend' => $debt->creditor?->name,
                        'balance' => $debt->balance,
                        'source_expected' => $debt->source_expected,
                        'source_label' => $debt->source_expected === 'customer_added' ? 'Added by you' : 'Credit check',
                        'reference' => $debt->reference,
                    ])->values()->all(),
                    'totals' => [
                        'total_debt' => $totalDebt,
                    ],
                    'iva_estimate' => $this->leadPortalIvaEstimateService->buildEstimateForLead($lead),
                    'financial_summary' => [
                        'employment_status' => $lead->employment_status,
                        'monthly_income' => $lead->monthly_income,
                        'monthly_housing_cost' => $lead->monthly_housing_cost,
                        'monthly_council_tax' => $lead->monthly_council_tax,
                        'monthly_utilities_cost' => $lead->monthly_utilities_cost,
                        'monthly_food_travel_cost' => $lead->monthly_food_travel_cost,
                    ],
                    'income' => [
                        'employment_status' => $lead->employment_status,
                        'monthly_income' => $lead->monthly_income,
                    ],
                    'costs' => [
                        'monthly_housing_cost' => $lead->monthly_housing_cost,
                        'monthly_council_tax' => $lead->monthly_council_tax,
                        'monthly_utilities_cost' => $lead->monthly_utilities_cost,
                        'monthly_food_travel_cost' => $lead->monthly_food_travel_cost,
                    ],
                    'credit_check' => [
                        'portal_credit_check_started_at' => $lead->portal_credit_check_started_at,
                        'portal_credit_check_completed_at' => $lead->portal_credit_check_completed_at,
                        'portal_credit_check_last_run_at' => $lead->portal_credit_check_last_run_at,
                    ],
                ],
            ]);

            $this->leadPortalProgressService->complete($lead, $previousStep);
            $this->leadPortalTokenService->complete($portalToken);

            return $snapshot;
        });

        $this->sendSummaryEmailIfPossible($snapshot, $portalToken);

        return $snapshot;
    }

    private function sendSummaryEmailIfPossible(LeadPortalSnapshot $snapshot, LeadPortalToken $portalToken): void
    {
        if ($snapshot->emailed_at !== null) {
            return;
        }

        $lead = $portalToken->lead;
        if (blank($lead->email)) {
            return;
        }

        $mail = (new LeadPortalSummaryMail($snapshot))
            ->withSymfonyMessage(function (Email $message) use ($lead, $snapshot, $portalToken): void {
                $headers = $message->getHeaders();
                $headers->addTextHeader('X-Portal-Lead-Id', (string) $lead->id);
                $headers->addTextHeader('X-Portal-Snapshot-Id', (string) $snapshot->id);
                $headers->addTextHeader('X-Portal-Token-Id', (string) $portalToken->id);
            });

        Mail::to($lead->email)->send($mail);

        $snapshot->forceFill([
            'emailed_at' => now(),
        ])->save();
    }
}

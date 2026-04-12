<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\FinancialStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadFinancialStatementController extends Controller
{
    public function __construct(
        private FinancialStatementService $financialStatementService
    ) {
    }

    public function update(Request $request, Lead $lead): JsonResponse
    {
        $payload = $this->financialStatementService->persistForLead($lead, $request->all());

        return response()->json([
            'success' => true,
            'financial_statement' => $payload,
        ]);
    }
}

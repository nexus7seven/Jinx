<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class CreditCheckV3Controller extends Controller
{
    public function page(Lead $lead)
    {
        return view('leads.credit-check-v3', compact('lead'));
    }

    public function run(Lead $lead): JsonResponse
    {
        $payload = [
            'lead' => [
                'title' => $lead->title,
                'first_name' => $lead->first_name,
                'middle_name' => $lead->middle_name,
                'last_name' => $lead->last_name,
                'dob' => $lead->dob,
                'phone_number' => $lead->phone_number,
                'postcode' => $lead->postcode,
                'house_number' => $lead->house_number,
                'house_name' => $lead->house_name,
                'building_number' => $lead->building_number,
                'address_line_1' => $lead->address_line_1,
            ],
            'meta' => [
                'source' => 'jinx_credit_check_v3',
                'lead_id' => $lead->id,
            ],
            'targetUrl' => 'https://www.transunionstatreport.co.uk/CreditReport/AboutYou',
        ];

        try {
            $response = Http::acceptJson()->post(
                rtrim((string) config('services.credit_check_v3_listener.base_url'), '/').'/jobs/start',
                $payload
            );

            return response()->json($response->json(), $response->status());
        } catch (Throwable) {
            return response()->json([
                'ok' => false,
                'message' => 'Unable to contact local listener.',
            ], 500);
        }
    }

    public function status(string $jobId): JsonResponse
    {
        try {
            $base = rtrim((string) config('services.credit_check_v3_listener.base_url'), '/');
            $stateResponse = Http::acceptJson()->get($base.'/jobs/'.$jobId.'/state');
            $questionsResponse = Http::acceptJson()->get($base.'/jobs/'.$jobId.'/questions');

            return response()->json([
                'ok' => true,
                'state' => $stateResponse->json(),
                'questions' => $questionsResponse->json(),
            ]);
        } catch (Throwable) {
            return response()->json([
                'ok' => false,
                'message' => 'Unable to contact local listener.',
            ], 500);
        }
    }

    public function submitAnswers(Request $request, string $jobId): JsonResponse
    {
        $validated = $request->validate([
            'answers' => ['required', 'array'],
        ]);

        try {
            $response = Http::acceptJson()->post(
                rtrim((string) config('services.credit_check_v3_listener.base_url'), '/').'/jobs/'.$jobId.'/answers',
                [
                    'answers' => $validated['answers'],
                ]
            );

            return response()->json($response->json(), $response->status());
        } catch (Throwable) {
            return response()->json([
                'ok' => false,
                'message' => 'Unable to contact local listener.',
            ], 500);
        }
    }
}


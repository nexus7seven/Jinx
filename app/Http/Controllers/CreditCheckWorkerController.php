<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class CreditCheckWorkerController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lead_id' => ['required', 'integer', 'exists:leads,id'],
        ]);

        $lead = Lead::findOrFail((int) $validated['lead_id']);

        $payload = [
            'lead' => [
                'title' => $lead->title,
                'first_name' => $lead->first_name,
                'middle_name' => $lead->middle_name,
                'last_name' => $lead->last_name,
                'dob' => $lead->dob,
                'email' => $lead->email,
                'phone_number' => $lead->phone_number,
                'postcode' => $lead->postcode,
                'house_number' => $lead->house_number,
            ],
        ];

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'x-worker-token' => (string) config('services.credit_check_worker.token'),
                ])
                ->post(
                    rtrim((string) config('services.credit_check_worker.base_url'), '/').'/jobs/credit-check',
                    $payload
                );

            return response()->json($response->json(), $response->status());
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'error' => 'Unable to contact credit check worker.',
            ], 500);
        }
    }

    public function status(string $jobId): JsonResponse
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'x-worker-token' => (string) config('services.credit_check_worker.token'),
                ])
                ->get(
                    rtrim((string) config('services.credit_check_worker.base_url'), '/').'/jobs/'.$jobId.'/status'
                );

            return response()->json($response->json(), $response->status());
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'error' => 'Unable to contact credit check worker.',
            ], 500);
        }
    }

    public function questions(string $jobId): JsonResponse
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'x-worker-token' => (string) config('services.credit_check_worker.token'),
                ])
                ->get(
                    rtrim((string) config('services.credit_check_worker.base_url'), '/').'/jobs/'.$jobId.'/questions'
                );

            return response()->json($response->json(), $response->status());
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'error' => 'Unable to contact credit check worker.',
            ], 500);
        }
    }

    public function submitAnswers(Request $request, string $jobId): JsonResponse
    {
        $validated = $request->validate([
            'answers' => ['required', 'array'],
        ]);

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'x-worker-token' => (string) config('services.credit_check_worker.token'),
                ])
                ->post(
                    rtrim((string) config('services.credit_check_worker.base_url'), '/').'/jobs/'.$jobId.'/answers',
                    [
                        'answers' => $validated['answers'],
                    ]
                );

            return response()->json($response->json(), $response->status());
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'error' => 'Unable to contact credit check worker.',
            ], 500);
        }
    }
}

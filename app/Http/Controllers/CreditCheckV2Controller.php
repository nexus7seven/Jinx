<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\TempMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class CreditCheckV2Controller extends Controller
{
    public function run(Request $request, Lead $lead): JsonResponse
    {
        try {
            $service = new TempMailService();

            $inbox = $service->createNewEmail();
            $email = $inbox['email'] ?? null;

            if (!$email) {
                return response()->json([
                    'ok' => false,
                    'message' => 'No email address returned by temp-mail provider.',
                ], 500);
            }

            $lead->update([
                'temp_mail' => $email,
                'temp_mail_provider' => config('services.temp_mail.provider', 'tempmailio'),
                'temp_mail_created_at' => now(),
                'temp_mail_last_checked_at' => null,
                'temp_mail_last_code' => null,
                'temp_mail_last_subject' => null,
                'temp_mail_last_from' => null,
                'temp_mail_last_message_id' => null,
                'temp_mail_last_body_text' => null,
            ]);

            $dob = $lead->dob;
            $dobParts = is_string($dob) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dob, $m)
                ? ['year' => (int) $m[1], 'month' => (int) $m[2], 'day' => (int) $m[3]]
                : null;

            $sessionId = (string) Str::uuid();
            $sessionDir = storage_path('app/credit-check-v2/sessions/'.$sessionId);
            $reportPath = storage_path('app/credit-check-v2/reports/'.$sessionId.'.pdf');

            foreach ([$sessionDir, \dirname($reportPath)] as $dir) {
                if (! is_dir($dir)) {
                    File::makeDirectory($dir, 0755, true);
                }
            }

            file_put_contents($sessionDir.'/meta.json', json_encode([
                'leadId' => $lead->id,
                'sessionId' => $sessionId,
            ], JSON_THROW_ON_ERROR));

            $payload = [
                'leadId' => $lead->id,
                'sessionId' => $sessionId,
                'sessionDir' => $sessionDir,
                'reportPath' => $reportPath,
                'creditCheckUrl' => 'https://www.transunionstatreport.co.uk/CreditReport/AboutYou',
                'personalData' => [
                    'firstName' => $lead->first_name,
                    'lastName' => $lead->last_name,
                    'dob' => $dob,
                    'dobParts' => $dobParts,
                    'phone' => $lead->phone_number,
                    'houseNumber' => $lead->house_number,
                    'buildingName' => null,
                    'addressLine1' => $lead->address_line_1,
                    'addressLine2' => null,
                    'town' => null,
                    'postcode' => $lead->postcode,
                ],
                'tempMail' => $email,
                'tempMailApi' => [
                    'baseUrl' => rtrim((string) config('services.temp_mail.base_url'), '/'),
                    'apiKey' => (string) config('services.temp_mail.key'),
                ],
            ];

            $payloadPath = tempnam(sys_get_temp_dir(), 'ccv2_');
            if ($payloadPath === false) {
                throw new \RuntimeException('Could not create temp payload file.');
            }

            file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));

            $script = base_path('scripts/credit-check-v2.mjs');

            $timeout = (float) env('CREDIT_CHECK_V2_PROCESS_TIMEOUT', 7200);

            $process = new Process(
                ['node', $script, $payloadPath],
                base_path(),
                null,
                null,
                $timeout
            );

            $process->run();

            @unlink($payloadPath);

            return response()->json([
                'ok' => $process->isSuccessful(),
                'exit_code' => $process->getExitCode(),
                'email' => $email,
                'sessionId' => $sessionId,
                'sessionDir' => $sessionDir,
                'reportPath' => $reportPath,
                'answersUrl' => route('leads.credit-check-v2.answers', ['lead' => $lead, 'sessionId' => $sessionId]),
                'stdout' => $process->getOutput(),
                'stderr' => $process->getErrorOutput(),
            ], $process->isSuccessful() ? 200 : 500);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function submitAnswers(Request $request, Lead $lead, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'answers' => 'required|array|min:1',
            'answers.*.id' => 'nullable|string',
            'answers.*.text' => 'nullable|string',
            'answers.*.value' => 'required|string',
        ]);

        $dir = storage_path('app/credit-check-v2/sessions/'.$sessionId);

        if (! is_dir($dir)) {
            return response()->json([
                'ok' => false,
                'message' => 'Unknown session.',
            ], 404);
        }

        $metaPath = $dir.'/meta.json';
        if (is_file($metaPath)) {
            $meta = json_decode((string) file_get_contents($metaPath), true);
            if ((int) ($meta['leadId'] ?? 0) !== (int) $lead->id) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Forbidden.',
                ], 403);
            }
        }

        file_put_contents($dir.'/answers.json', json_encode(['answers' => $validated['answers']], JSON_THROW_ON_ERROR));

        return response()->json([
            'ok' => true,
        ]);
    }
}

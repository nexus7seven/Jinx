<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\TempMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;
use Throwable;

class CreditCheckV2Controller extends Controller
{
    /**
     * Start statutory credit report automation (Playwright). Same entry point as "Request statutory credit report" in the UI.
     */
    public function run(Request $request, Lead $lead): JsonResponse|StreamedResponse
    {
        try {
            $validationErrors = $this->validateLeadForStatutoryCreditReport($lead);
            if ($validationErrors !== []) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Please complete the required lead fields before requesting a statutory credit report.',
                    'errors' => $validationErrors,
                ], 422);
            }

            $prepared = $this->prepareRun($lead);

            if ($request->boolean('stream') || $request->query('stream') === '1') {
                return $this->streamRun($lead, $prepared);
            }

            return $this->jsonRun($lead, $prepared);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Strong validation before temp-mail / Playwright — avoids obvious failures from incomplete lead data.
     *
     * @return array<string, string> field key => user-facing message
     */
    private function validateLeadForStatutoryCreditReport(Lead $lead): array
    {
        $errors = [];

        if (trim((string) ($lead->title ?? '')) === '') {
            $errors['title'] = 'Title is required — select a title on the lead record.';
        }

        if (trim((string) ($lead->first_name ?? '')) === '') {
            $errors['first_name'] = 'First name is required.';
        }

        if (trim((string) ($lead->last_name ?? '')) === '') {
            $errors['last_name'] = 'Last name is required.';
        }

        $dobRaw = $lead->getAttribute('dob');
        if ($dobRaw === null || $dobRaw === '') {
            $errors['dob'] = 'Date of birth is required.';
        } else {
            $dobStr = is_string($dobRaw) ? trim($dobRaw) : trim((string) $dobRaw);
            if ($dobStr === '') {
                $errors['dob'] = 'Date of birth is required.';
            } elseif (
                ! preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $dobStr)
                && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dobStr)
            ) {
                $errors['dob'] = 'Date of birth must be in DD/MM/YYYY format (e.g. 15/03/1990).';
            }
        }

        if (trim((string) ($lead->phone_number ?? '')) === '') {
            $errors['phone_number'] = 'Phone number is required — TransUnion needs a valid UK contact number.';
        }

        if (trim((string) ($lead->postcode ?? '')) === '') {
            $errors['postcode'] = 'Postcode is required for address lookup.';
        }

        $house = trim((string) ($lead->house_number ?? ''));
        $building = trim((string) ($lead->building_number ?? ''));
        if ($house === '' && $building === '') {
            $errors['house_number'] = 'Missing house number — enter a house number or building number for address lookup.';
        }

        return $errors;
    }

    /**
     * @return array{
     *     email: string,
     *     sessionId: string,
     *     sessionDir: string,
     *     reportPath: string,
     *     payloadPath: string,
     *     process: Process,
     * }
     */
    private function prepareRun(Lead $lead): array
    {
        $service = new TempMailService();

        // Same proven path as TempMailController::generate: GET /v1/domains then POST /v1/emails with domain
        // (createNewEmail() uses an empty POST body and can fail with 500 on some API versions.)
        $inbox = $service->createInboxUsingRandomDomain();
        $email = $inbox['email'] ?? null;

        if (! $email) {
            throw new \RuntimeException('No email address returned by temp-mail provider.');
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

        $dobForPayload = $lead->dob;
        if (is_string($dobForPayload) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dobForPayload)) {
            try {
                $dobForPayload = Carbon::parse($dobForPayload)->format('d/m/Y');
            } catch (\Throwable) {
                // leave as-is
            }
        }

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

        $personalData = [
            'title' => $lead->title ?: 'Mr',
            'first_name' => $lead->first_name,
            'middle_name' => $lead->middle_name,
            'last_name' => $lead->last_name,
            'dob' => $dobForPayload,
            'phone_number' => $lead->phone_number,
            'postcode' => $lead->postcode,
            'house_number' => $lead->house_number,
            'house_name' => $lead->house_name,
            'building_number' => $lead->building_number,
            'address_line_1' => $lead->address_line_1,
        ];

        $payload = [
            'leadId' => $lead->id,
            'sessionId' => $sessionId,
            'sessionDir' => $sessionDir,
            'reportPath' => $reportPath,
            'creditCheckUrl' => 'https://www.transunionstatreport.co.uk/CreditReport/AboutYou',
            'personalData' => $personalData,
            'tempMail' => $email,
            'tempEmail' => $email,
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

        $nodeBinary = (string) env('NODE_BINARY', '/root/.nvm/versions/node/v24.11.0/bin/node');

        $process = new Process(
            [$nodeBinary, $script, $payloadPath],
            base_path(),
            null,
            null,
            $timeout
        );

        return [
            'email' => $email,
            'sessionId' => $sessionId,
            'sessionDir' => $sessionDir,
            'reportPath' => $reportPath,
            'payloadPath' => $payloadPath,
            'process' => $process,
        ];
    }

    /**
     * @param  array<string, mixed>  $prepared
     */
    private function jsonRun(Lead $lead, array $prepared): JsonResponse
    {
        $process = $prepared['process'];
        $payloadPath = $prepared['payloadPath'];
        $email = $prepared['email'];
        $sessionId = $prepared['sessionId'];
        $sessionDir = $prepared['sessionDir'];
        $reportPath = $prepared['reportPath'];

        $process->run();

        @unlink($payloadPath);

        $stdout = $process->getOutput();
        $parsedEvents = self::parseCreditCheckJsonLines($stdout);
        $securityQuestionsEvent = self::lastSecurityQuestionsEvent($parsedEvents);
        $failedEvent = self::lastFailedEvent($parsedEvents);

        $ok = self::computeRunOk($process, $parsedEvents);
        $httpStatus = self::httpStatusForRun($process, $failedEvent);

        $userMessage = null;
        if ($failedEvent !== null) {
            $userMessage = (string) ($failedEvent['message'] ?? self::identityVerificationFailedMessage());
        }

        return response()->json([
            'ok' => $ok,
            'exit_code' => $process->getExitCode(),
            'email' => $email,
            'sessionId' => $sessionId,
            'sessionDir' => $sessionDir,
            'reportPath' => $reportPath,
            'answersUrl' => route('leads.credit-check-v2.answers', ['lead' => $lead, 'sessionId' => $sessionId]),
            'events' => $parsedEvents,
            'security_questions' => $securityQuestionsEvent,
            'security_questions_events' => self::filterSecurityQuestionsEvents($parsedEvents),
            'failed' => $failedEvent,
            'message' => $userMessage,
            'stdout' => $stdout,
            'stderr' => $process->getErrorOutput(),
        ], $httpStatus);
    }

    /**
     * Stream Node stdout/stderr live for the CRM terminal view.
     *
     * @param  array<string, mixed>  $prepared
     */
    private function streamRun(Lead $lead, array $prepared): StreamedResponse
    {
        $process = $prepared['process'];
        $payloadPath = $prepared['payloadPath'];
        $email = $prepared['email'];
        $sessionId = $prepared['sessionId'];

        $answersUrl = route('leads.credit-check-v2.answers', ['lead' => $lead, 'sessionId' => $sessionId]);

        return response()->stream(function () use ($process, $payloadPath) {
            try {
                $process->start();

                while ($process->isRunning()) {
                    $out = $process->getIncrementalOutput();
                    if ($out !== '') {
                        echo $out;
                    }
                    $err = $process->getIncrementalErrorOutput();
                    if ($err !== '') {
                        echo $err;
                    }
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    usleep(40000);
                }

                $rest = $process->getIncrementalOutput();
                if ($rest !== '') {
                    echo $rest;
                }
                $restErr = $process->getIncrementalErrorOutput();
                if ($restErr !== '') {
                    echo $restErr;
                }
            } finally {
                @unlink($payloadPath);
            }
        }, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'X-Credit-Check-Session-Id' => $sessionId,
            'X-Credit-Check-Email' => $email,
            'X-Credit-Check-Answers-Url' => $answersUrl,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $parsedEvents
     */
    private static function computeRunOk(Process $process, array $parsedEvents): bool
    {
        if (! $process->isSuccessful()) {
            return false;
        }

        return self::lastFailedEvent($parsedEvents) === null;
    }

    private static function httpStatusForRun(Process $process, ?array $failedEvent): int
    {
        if (! $process->isSuccessful()) {
            return 500;
        }

        if ($failedEvent !== null) {
            return 422;
        }

        return 200;
    }

    public function submitAnswers(Request $request, Lead $lead, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'answers' => 'required|array|min:1',
            'answers.*.id' => 'nullable|string',
            'answers.*.index' => 'nullable|integer|min:0',
            'answers.*.name' => 'nullable|string',
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function parseCreditCheckJsonLines(string $stdout): array
    {
        $events = [];
        if (preg_match_all('/^CREDIT_CHECK_V2_JSON:(.+)$/m', $stdout, $matches)) {
            foreach ($matches[1] as $json) {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $events[] = $decoded;
                }
            }
        }

        return $events;
    }

    /**
     * Prefer the last security_questions event (e.g. second attempt after wrong answers).
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, mixed>|null
     */
    private static function lastSecurityQuestionsEvent(array $events): ?array
    {
        $last = null;
        foreach ($events as $event) {
            if (($event['status'] ?? null) === 'security_questions') {
                $last = $event;
            }
        }

        return $last;
    }

    /**
     * Last terminal `status: failed` event (e.g. identity verification failed).
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, mixed>|null
     */
    private static function lastFailedEvent(array $events): ?array
    {
        $last = null;
        foreach ($events as $event) {
            if (($event['status'] ?? null) === 'failed') {
                $last = $event;
            }
        }

        return $last;
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    private static function filterSecurityQuestionsEvents(array $events): array
    {
        return array_values(array_filter($events, function ($event) {
            return ($event['status'] ?? null) === 'security_questions';
        }));
    }

    /**
     * User-facing copy when {@see lastFailedEvent()} reports identity verification failure.
     * Wording is mirrored in {@see scripts/credit-check-v2.mjs} (IDENTITY_FAILURE_USER_MESSAGE).
     */
    private static function identityVerificationFailedMessage(): string
    {
        return 'TransUnion was unable to verify your identity automatically. You can try again later or request by post.';
    }
}

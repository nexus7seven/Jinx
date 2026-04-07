<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\TempMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TempMailController extends Controller
{
    public function generate(Request $request, Lead $lead): JsonResponse
    {
        try {
            $service = new TempMailService();

            $inbox = $service->createInboxUsingRandomDomain();
            $email = $inbox['email'] ?? null;

            if (!$email) {
                return response()->json([
                    'ok' => false,
                    'message' => 'No email address returned by temp-mail provider.',
                ], 500);
            }

            $lead->update([
                'temp_mail' => $email,
                'temp_mail_provider' => 'tempmailio',
                'temp_mail_created_at' => now(),
                'temp_mail_last_checked_at' => null,
                'temp_mail_last_code' => null,
                'temp_mail_last_subject' => null,
                'temp_mail_last_from' => null,
                'temp_mail_last_message_id' => null,
                'temp_mail_last_body_text' => null,
            ]);

            return response()->json([
                'ok' => true,
                'email' => $email,
                'ttl' => $inbox['ttl'] ?? null,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function inbox(Request $request, Lead $lead): JsonResponse
    {
        if (!$lead->temp_mail) {
            return response()->json([
                'ok' => false,
                'message' => 'This lead has no temp email yet.',
            ], 422);
        }

        try {
            $service = new TempMailService();

            $messagesResponse = $service->getMessages($lead->temp_mail);
            $messages = $service->normaliseMessages($messagesResponse);
            $latest = $service->pickLatestMessage($messages);

            $lead->update([
                'temp_mail_last_checked_at' => now(),
            ]);

            return response()->json([
                'ok' => true,
                'email' => $lead->temp_mail,
                'count' => count($messages),
                'latest' => $latest,
                'messages' => $messages,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function latestCode(Request $request, Lead $lead): JsonResponse
    {
        if (!$lead->temp_mail) {
            return response()->json([
                'ok' => false,
                'message' => 'This lead has no temp email yet.',
            ], 422);
        }

        try {
            $service = new TempMailService();

            $messagesResponse = $service->getMessages($lead->temp_mail);
            $messages = $service->normaliseMessages($messagesResponse);
            $latest = $service->pickLatestMessage($messages);

            if (!$latest || empty($latest['id'])) {
                $lead->update([
                    'temp_mail_last_checked_at' => now(),
                ]);

                return response()->json([
                    'ok' => true,
                    'email' => $lead->temp_mail,
                    'found' => false,
                    'code' => null,
                    'message' => 'No messages yet.',
                ]);
            }

            $full = $service->getMessage((string) $latest['id']);

            $bodyText = (string) ($full['body_text'] ?? '');
            $subject = (string) ($full['subject'] ?? ($latest['subject'] ?? ''));
            $from = (string) ($full['from'] ?? ($latest['from'] ?? ''));
            $specificPattern = $request->input('pattern');
            $code = $service->extractSpecificAuthCode($bodyText, $specificPattern);
            $link = $service->extractFirstLink($bodyText);

            $lead->update([
                'temp_mail_last_checked_at' => now(),
                'temp_mail_last_code' => $code,
                'temp_mail_last_subject' => $subject,
                'temp_mail_last_from' => $from,
                'temp_mail_last_message_id' => (string) $latest['id'],
                'temp_mail_last_body_text' => $bodyText,
            ]);

            return response()->json([
                'ok' => true,
                'found' => true,
                'email' => $lead->temp_mail,
                'message_id' => $latest['id'],
                'subject' => $subject,
                'from' => $from,
                'code' => $code,
                'link' => $link,
                'body_text' => $bodyText,
                'created_at' => $full['created_at'] ?? ($latest['created_at'] ?? null),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function reset(Request $request, Lead $lead): JsonResponse
    {
        try {
            if ($lead->temp_mail) {
                $service = new TempMailService();

                try {
                    $service->deleteEmail($lead->temp_mail);
                } catch (Throwable $inner) {
                    // ignore provider delete failure
                }
            }

            $lead->update([
                'temp_mail' => null,
                'temp_mail_provider' => null,
                'temp_mail_created_at' => null,
                'temp_mail_last_checked_at' => null,
                'temp_mail_last_code' => null,
                'temp_mail_last_subject' => null,
                'temp_mail_last_from' => null,
                'temp_mail_last_message_id' => null,
                'temp_mail_last_body_text' => null,
            ]);

            return response()->json([
                'ok' => true,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
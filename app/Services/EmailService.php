<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use SendGrid;
use SendGrid\Mail\Mail;
use Throwable;

class EmailService
{
    private ?string $lastError = null;

    public function sendEmail(string $to, string $subject, string $html): bool
    {
        $this->lastError = null;

        $apiKey = (string) env('SENDGRID_API_KEY', '');
        $from = trim((string) env('EMAIL_FROM', ''));
        $fromName = trim((string) env('EMAIL_FROM_NAME', ''));
        $toAddress = trim($to);
        $subjectText = trim($subject);
        $htmlBody = trim($html);

        if (
            $apiKey === '' ||
            $from === '' ||
            $fromName === '' ||
            $toAddress === '' ||
            $subjectText === '' ||
            $htmlBody === '' ||
            ! filter_var($from, FILTER_VALIDATE_EMAIL) ||
            ! filter_var($toAddress, FILTER_VALIDATE_EMAIL)
        ) {
            $this->lastError = 'Invalid SendGrid config or email input.';

            return false;
        }

        try {
            $email = new Mail();
            $email->setFrom($from, $fromName);
            $email->setSubject($subjectText);
            $email->addTo($toAddress);
            $email->addContent('text/html', $htmlBody);

            $sendGrid = new SendGrid($apiKey);
            $response = $sendGrid->send($email);

            if ($response->statusCode() >= 200 && $response->statusCode() < 300) {
                return true;
            }

            $this->lastError = 'SendGrid API returned status ' . $response->statusCode() . '.';
            Log::warning('SendGrid email send failed', [
                'status_code' => $response->statusCode(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::warning('SendGrid email send failed', [
                'error' => $this->lastError,
            ]);

            return false;
        }
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}

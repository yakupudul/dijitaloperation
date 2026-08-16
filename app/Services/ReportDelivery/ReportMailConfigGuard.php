<?php

namespace App\Services\ReportDelivery;

use App\Enums\ReportDeliveryFailureCategory;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;

/**
 * Truthful mail configuration gate — never fake SENT.
 */
final class ReportMailConfigGuard
{
    public function assertConfigured(): void
    {
        $mailer = (string) Config::get('mail.default', '');
        if ($mailer === '' || $mailer === 'array') {
            // array mailer is valid in tests / local; still "configured".
            return;
        }

        if ($mailer === 'smtp') {
            $host = (string) Config::get('mail.mailers.smtp.host', '');
            if ($host === '') {
                throw ValidationException::withMessages([
                    'mail' => ReportDeliveryFailureCategory::EmailConfigurationMissing->value,
                ]);
            }
        }
    }

    public function isConfigured(): bool
    {
        try {
            $this->assertConfigured();

            return true;
        } catch (ValidationException) {
            return false;
        }
    }
}

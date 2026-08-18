<?php

namespace App\Support\Operator;

/**
 * Safe, operator-facing mail delivery status. Deployment secrets stay out of /app.
 */
final class OperatorMailStatus
{
    public const string NOT_CONFIGURED = 'not_configured';

    public const string DEPLOYMENT_CONFIGURED = 'deployment_configured';

    /**
     * @return array{state: string, label: string, explanation: string, from_name: string|null, from_address: string|null, production_delivery: bool}
     */
    public static function presentation(): array
    {
        $state = self::state();

        return [
            'state' => $state,
            'label' => $state === self::DEPLOYMENT_CONFIGURED
                ? __('operator.mail.configured_deployment')
                : __('operator.mail.not_configured'),
            'explanation' => $state === self::DEPLOYMENT_CONFIGURED
                ? __('operator.mail.explanation_deployment')
                : __('operator.mail.explanation_not_configured'),
            'from_name' => self::fromName(),
            'from_address' => self::fromAddress(),
            'production_delivery' => $state === self::DEPLOYMENT_CONFIGURED,
        ];
    }

    public static function state(): string
    {
        $mailer = strtolower((string) config('mail.default'));
        $transport = strtolower((string) config("mail.mailers.{$mailer}.transport", $mailer));

        if (in_array($mailer, ['log', 'array', ''], true) || in_array($transport, ['log', 'array', ''], true)) {
            return self::NOT_CONFIGURED;
        }

        if (in_array($transport, ['smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun', 'sendmail'], true)) {
            return self::DEPLOYMENT_CONFIGURED;
        }

        return self::NOT_CONFIGURED;
    }

    public static function fromName(): ?string
    {
        $name = config('mail.from.name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    public static function fromAddress(): ?string
    {
        $address = config('mail.from.address');

        return is_string($address) && trim($address) !== '' ? trim($address) : null;
    }
}

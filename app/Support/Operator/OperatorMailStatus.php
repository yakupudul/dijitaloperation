<?php

namespace App\Support\Operator;

use App\Services\Operator\AgencySettingService;
use App\Services\Operator\OperatorMailConfigService;

/**
 * Safe, operator-facing mail delivery status. Secrets stay out of HTML and logs.
 */
final class OperatorMailStatus
{
    public const string NOT_CONFIGURED = 'not_configured';

    public const string DEPLOYMENT_CONFIGURED = 'deployment_configured';

    public const string OPERATOR_CONFIGURED = 'operator_configured';

    /**
     * @return array{
     *     state: string,
     *     label: string,
     *     explanation: string,
     *     from_name: string|null,
     *     from_address: string|null,
     *     host: string|null,
     *     port: int|null,
     *     username: string|null,
     *     encryption: string|null,
     *     has_password: bool,
     *     operator_enabled: bool,
     *     production_delivery: bool
     * }
     */
    public static function presentation(): array
    {
        $state = self::state();
        $mail = app(OperatorMailConfigService::class);
        $settings = app(AgencySettingService::class)->current();

        return [
            'state' => $state,
            'label' => match ($state) {
                self::OPERATOR_CONFIGURED => __('operator.mail.configured_operator'),
                self::DEPLOYMENT_CONFIGURED => __('operator.mail.configured_deployment'),
                default => __('operator.mail.not_configured'),
            },
            'explanation' => match ($state) {
                self::OPERATOR_CONFIGURED => __('operator.mail.explanation_operator'),
                self::DEPLOYMENT_CONFIGURED => __('operator.mail.explanation_deployment'),
                default => __('operator.mail.explanation_not_configured'),
            },
            'from_name' => is_string($settings->mail_from_name) && trim($settings->mail_from_name) !== ''
                ? trim($settings->mail_from_name)
                : self::fromName(),
            'from_address' => is_string($settings->mail_from_address) && trim($settings->mail_from_address) !== ''
                ? trim($settings->mail_from_address)
                : self::fromAddress(),
            'host' => is_string($settings->mail_host) && trim($settings->mail_host) !== '' ? trim($settings->mail_host) : null,
            'port' => $settings->mail_port !== null ? (int) $settings->mail_port : null,
            'username' => is_string($settings->mail_username) && trim($settings->mail_username) !== ''
                ? trim($settings->mail_username)
                : null,
            'encryption' => is_string($settings->mail_encryption) && AgencySettingCatalog::isMailEncryption($settings->mail_encryption)
                ? $settings->mail_encryption
                : AgencySettingCatalog::MAIL_TLS,
            'has_password' => $mail->hasStoredPassword(),
            'operator_enabled' => (bool) $settings->mail_enabled,
            'production_delivery' => in_array($state, [self::OPERATOR_CONFIGURED, self::DEPLOYMENT_CONFIGURED], true),
        ];
    }

    public static function state(): string
    {
        $mail = app(OperatorMailConfigService::class);
        if ($mail->operatorSmtpIsComplete()) {
            return self::OPERATOR_CONFIGURED;
        }

        if ($mail->deploymentMailerCanSend()) {
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

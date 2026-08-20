<?php

namespace App\Services\Operator;

use App\Mail\OperatorTestMail;
use App\Models\AgencySetting;
use App\Models\User;
use App\Support\Operator\AgencySettingCatalog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Operator SMTP overlay on top of deployment/env mail config.
 * Never copies env secrets into the database.
 */
final class OperatorMailConfigService
{
    public const string TEST_RESULT_SENT = 'sent';

    public const string TEST_RESULT_FAILED = 'failed';

    public const string TEST_RESULT_NOT_CONFIGURED = 'not_configured';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $deploymentMailBaseline = null;

    public function __construct(
        private readonly AgencySettingService $settings,
    ) {}

    /**
     * @param  array{
     *     mail_enabled: bool,
     *     mail_from_name: string,
     *     mail_from_address: string,
     *     mail_host: string,
     *     mail_port: int|string|null,
     *     mail_username: string,
     *     mail_encryption: string,
     *     mail_password?: string|null,
     *     clear_password?: bool
     * }  $attributes
     */
    public function update(array $attributes): AgencySetting
    {
        if (! $this->agencySettingsAreQueryable()) {
            throw new InvalidArgumentException('Agency settings are not available.');
        }

        $enabled = (bool) $attributes['mail_enabled'];
        $fromName = trim((string) $attributes['mail_from_name']);
        $fromAddress = strtolower(trim((string) $attributes['mail_from_address']));
        $host = trim((string) $attributes['mail_host']);
        $port = $this->normalizePort($attributes['mail_port'] ?? null);
        $username = trim((string) $attributes['mail_username']);
        $encryption = trim((string) $attributes['mail_encryption']);
        $incomingPassword = trim((string) ($attributes['mail_password'] ?? ''));
        $clearPassword = (bool) ($attributes['clear_password'] ?? false);

        if ($encryption !== '' && ! AgencySettingCatalog::isMailEncryption($encryption)) {
            throw new InvalidArgumentException('Invalid mail encryption.');
        }

        $settings = $this->settings->current();
        $storedPassword = $clearPassword ? null : (is_string($settings->mail_password) && $settings->mail_password !== ''
            ? $settings->mail_password
            : null);

        if ($incomingPassword !== '') {
            $storedPassword = $incomingPassword;
        }

        if ($enabled) {
            if ($host === '' || $fromAddress === '' || $port === null) {
                throw new InvalidArgumentException('Operator SMTP requires host, port, and from address.');
            }
            if ($storedPassword === null || $storedPassword === '') {
                throw new InvalidArgumentException('Operator SMTP requires a password.');
            }
        }

        $settings->fill([
            'mail_enabled' => $enabled,
            'mail_from_name' => $fromName !== '' ? $fromName : null,
            'mail_from_address' => $fromAddress !== '' ? $fromAddress : null,
            'mail_host' => $host !== '' ? $host : null,
            'mail_port' => $port,
            'mail_username' => $username !== '' ? $username : null,
            'mail_encryption' => $encryption !== '' ? $encryption : null,
            'mail_password' => $storedPassword,
        ]);
        $settings->save();

        $this->applyToRuntime();

        return $settings->fresh() ?? $settings;
    }

    public function clearOperatorSmtp(): AgencySetting
    {
        if (! $this->agencySettingsAreQueryable()) {
            throw new InvalidArgumentException('Agency settings are not available.');
        }

        $settings = $this->settings->current();
        $settings->fill([
            'mail_enabled' => false,
            'mail_from_name' => null,
            'mail_from_address' => null,
            'mail_host' => null,
            'mail_port' => null,
            'mail_username' => null,
            'mail_encryption' => null,
            'mail_password' => null,
        ]);
        $settings->save();

        $this->applyToRuntime();

        return $settings->fresh() ?? $settings;
    }

    public function operatorSmtpIsComplete(): bool
    {
        if (! $this->agencySettingsAreQueryable()) {
            return false;
        }

        $settings = $this->settings->current();
        $password = $settings->mail_password;

        return (bool) $settings->mail_enabled
            && is_string($settings->mail_host)
            && trim($settings->mail_host) !== ''
            && is_string($settings->mail_from_address)
            && trim($settings->mail_from_address) !== ''
            && $settings->mail_port !== null
            && is_string($password)
            && $password !== '';
    }

    public function hasStoredPassword(): bool
    {
        if (! $this->agencySettingsAreQueryable()) {
            return false;
        }

        $password = $this->settings->current()->mail_password;

        return is_string($password) && $password !== '';
    }

    /**
     * Re-read persisted operator SMTP at a queued send boundary and drop any
     * already-resolved mailer/transport from this long-lived worker.
     */
    public function reloadForQueuedSend(): void
    {
        $this->applyToRuntime();
        app('mail.manager')->forgetMailers();
    }

    public function applyToRuntime(): void
    {
        $this->captureDeploymentBaseline();

        if (! $this->operatorSmtpIsComplete()) {
            $this->restoreDeploymentBaseline();

            return;
        }

        $settings = $this->settings->current();
        $encryption = (string) ($settings->mail_encryption ?? AgencySettingCatalog::MAIL_TLS);
        $scheme = $encryption === AgencySettingCatalog::MAIL_SSL ? 'smtps' : 'smtp';

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.scheme' => $scheme,
            'mail.mailers.smtp.host' => $settings->mail_host,
            'mail.mailers.smtp.port' => (int) $settings->mail_port,
            'mail.mailers.smtp.username' => $settings->mail_username,
            'mail.mailers.smtp.password' => $settings->mail_password,
            'mail.from.address' => $settings->mail_from_address,
            'mail.from.name' => $settings->mail_from_name ?: $this->settings->branding()['portal_name'],
        ]);
    }

    /**
     * @return array{result: string, message: string}
     */
    public function sendTestEmail(User $actor): array
    {
        $this->applyToRuntime();

        if (! $this->operatorSmtpIsComplete() && ! $this->deploymentMailerCanSend()) {
            return [
                'result' => self::TEST_RESULT_NOT_CONFIGURED,
                'message' => __('operator.mail.test_not_configured'),
            ];
        }

        try {
            Mail::to($actor->email)->send(new OperatorTestMail);
        } catch (Throwable $exception) {
            Log::warning('operator.mail.test_failed', [
                'exception_class' => $exception::class,
            ]);

            return [
                'result' => self::TEST_RESULT_FAILED,
                'message' => __('operator.mail.test_failed'),
            ];
        }

        return [
            'result' => self::TEST_RESULT_SENT,
            'message' => __('operator.mail.test_sent'),
        ];
    }

    public function deploymentMailerCanSend(): bool
    {
        $mailer = strtolower((string) config('mail.default'));
        $transport = strtolower((string) config("mail.mailers.{$mailer}.transport", $mailer));

        if (in_array($mailer, ['log', 'array', ''], true) || in_array($transport, ['log', 'array', ''], true)) {
            return false;
        }

        return in_array($transport, ['smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun', 'sendmail'], true);
    }

    private function captureDeploymentBaseline(): void
    {
        if ($this->deploymentMailBaseline !== null) {
            return;
        }

        $this->deploymentMailBaseline = [
            'mail.default' => config('mail.default'),
            'mail.mailers.smtp.transport' => config('mail.mailers.smtp.transport'),
            'mail.mailers.smtp.scheme' => config('mail.mailers.smtp.scheme'),
            'mail.mailers.smtp.host' => config('mail.mailers.smtp.host'),
            'mail.mailers.smtp.port' => config('mail.mailers.smtp.port'),
            'mail.mailers.smtp.username' => config('mail.mailers.smtp.username'),
            'mail.mailers.smtp.password' => config('mail.mailers.smtp.password'),
            'mail.from.address' => config('mail.from.address'),
            'mail.from.name' => config('mail.from.name'),
        ];
    }

    private function restoreDeploymentBaseline(): void
    {
        if ($this->deploymentMailBaseline === null) {
            return;
        }

        config($this->deploymentMailBaseline);
    }

    private function agencySettingsAreQueryable(): bool
    {
        try {
            return Schema::hasTable('agency_settings');
        } catch (Throwable) {
            return false;
        }
    }

    private function normalizePort(int|string|null $port): ?int
    {
        if ($port === null || $port === '') {
            return null;
        }

        $value = (int) $port;
        if ($value < 1 || $value > 65535) {
            throw new InvalidArgumentException('Invalid mail port.');
        }

        return $value;
    }
}

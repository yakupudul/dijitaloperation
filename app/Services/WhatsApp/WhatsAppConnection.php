<?php

namespace App\Services\WhatsApp;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppWebhookReceipt;
use App\Support\Permissions;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WhatsAppConnection
{
    public const PROVIDER = 'whatsapp';

    public function integration(): ?CoreIntegration
    {
        return CoreIntegration::query()->where('provider', self::PROVIDER)->first();
    }

    public function authorize(?User $user): void
    {
        abort_unless($user?->is_active && $user->can(Permissions::ACCESS_APP) && $user->hasRole(Roles::ADMIN), 403);
    }

    public function secrets(CoreIntegration $integration): array
    {
        return $integration->providerCredential()->first()?->encrypted_payload ?? [];
    }

    /** Only presence flags may be rendered; stored secret values never enter Livewire state. */
    public function credentialStatus(?CoreIntegration $integration): array
    {
        $secrets = $integration ? $this->secrets($integration) : [];

        return array_map(
            fn (string $key): bool => is_string($secrets[$key] ?? null) && trim($secrets[$key]) !== '',
            array_combine(['access_token', 'app_secret', 'verify_token'], ['access_token', 'app_secret', 'verify_token']),
        );
    }

    public function save(User $user, array $input): void
    {
        $this->authorize($user);
        foreach (['waba_id', 'phone_number_id', 'business_phone'] as $key) {
            if (is_string($input[$key] ?? null)) {
                $input[$key] = trim($input[$key]);
            }
        }
        $data = Validator::make($input, [
            'waba_id' => ['required', 'regex:/^[0-9]{5,40}$/'],
            'phone_number_id' => ['required', 'regex:/^[0-9]{5,40}$/'],
            'business_phone' => ['required', 'regex:/^[0-9]{7,20}$/'],
            'access_token' => ['nullable', 'string', 'max:4096'],
            'app_secret' => ['nullable', 'string', 'max:255'],
            'verify_token' => ['nullable', 'string', 'min:16', 'max:255'],
            'business_context' => ['sometimes', 'required', 'string', 'max:12000'],
            'enabled' => ['required', 'boolean'],
            'automatic_suggestions' => ['sometimes', 'required', 'boolean'],
        ], [
            'verify_token.min' => 'Webhook Verify Token en az 16 karakter olmalı.',
        ], [
            'access_token' => 'Access Token',
            'app_secret' => 'Meta App Secret',
            'verify_token' => 'Webhook Verify Token',
        ])->validate();

        DB::transaction(function () use ($data): void {
            $integration = CoreIntegration::query()->firstOrCreate(['provider' => self::PROVIDER], [
                'name' => 'WhatsApp Business', 'status' => CoreIntegration::STATUS_DISABLED, 'config' => [],
            ]);
            $integration = CoreIntegration::query()->lockForUpdate()->findOrFail($integration->id);
            app(WhatsAppSignup::class)->assertIdle($integration);
            $secrets = $this->secrets($integration);
            $labels = [
                'access_token' => 'Access Token',
                'app_secret' => 'Meta App Secret',
                'verify_token' => 'Webhook Verify Token',
            ];
            $missing = [];
            foreach ($labels as $key => $label) {
                if (trim((string) ($data[$key] ?? '')) !== '') {
                    $secrets[$key] = trim($data[$key]);
                }
                if (empty($secrets[$key])) {
                    $missing[$key] = $label.' henüz kayıtlı değil. Bu alanı doldurun.';
                }
            }
            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }
            $config = $integration->config ?? [];
            $bindingChanged = $this->assertBindingAvailable($integration, $data);
            if ($bindingChanged) {
                unset($config['history_state'], $config['echo_seen_at'], $config['last_receipt_at'], $config['last_message_received_at']);
                // A number entered by hand is not a Meta-popup onboarding: its business-app history cannot be asked.
                unset($config['coexistence_state'], $config['signup_completed_at'], $config['history_sync'], $config['history_sync_error'], $config['history_sync_requested_at']);
                $config['connected_via'] = 'manual';
            }
            $config['settings_revision'] = (string) Str::uuid();
            $config['connection_error'] = null;
            if (filled($data['verify_token'] ?? null)) {
                unset($config['webhook_verified_at']);
            }
            $config['subscription_state'] = 'not_checked';
            $config['subscription_error'] = null;
            $contextChanged = array_key_exists('business_context', $data) && ($config['business_context'] ?? '') !== $data['business_context'];
            $config['connection_check_request_id'] = (string) Str::uuid();
            $config['connection_check_requested_at'] = null;
            $config['connection_check'] = 'not_checked';
            $config['connection_checked_at'] = null;
            $config['settings_saved_at'] = now()->toIso8601String();
            foreach (['waba_id', 'phone_number_id', 'business_phone', 'business_context', 'automatic_suggestions'] as $key) {
                if (array_key_exists($key, $data)) {
                    $config[$key] = $data[$key];
                }
            }
            $integration->update([
                'status' => $data['enabled'] ? CoreIntegration::STATUS_ACTIVE : CoreIntegration::STATUS_DISABLED,
                'config' => $config,
            ]);
            $integration->credentials()->updateOrCreate(['credential_type' => CoreIntegrationCredential::TYPE_PROVIDER], [
                'encrypted_payload' => $secrets, 'refreshed_at' => now(),
            ]);
            if ($contextChanged) {
                self::contextChanged($integration);
            }
        });
    }

    /** New service terms: drafts of conversations still inside the 24-hour reply window are prepared again. */
    public static function contextChanged(CoreIntegration $integration): void
    {
        WhatsAppConversation::query()->where('integration_id', $integration->id)->where('suggestion_status', 'ready')
            ->where('last_incoming_at', '>', now()->subHours(24))
            ->update(['suggestion_status' => 'pending', 'updated_at' => now()]);
    }

    /**
     * Where the connection stands, for the screen: 'setup' (Meta app details missing), 'connect' (no number yet),
     * 'attention' (a number is saved but Meta refuses it), 'disabled' or 'connected'.
     */
    public function state(?CoreIntegration $integration): string
    {
        $config = $integration?->config ?? [];
        $secrets = $this->credentialStatus($integration);
        $bound = filled($config['waba_id'] ?? null) && filled($config['phone_number_id'] ?? null) && $secrets['access_token'];

        return match (true) {
            $integration !== null && ! $integration->isActive() => 'disabled',
            ! $bound && (! filled($config['app_id'] ?? null) || ! filled($config['signup_config_id'] ?? null) || ! $secrets['app_secret'] || ! $secrets['verify_token']) => 'setup',
            ! $bound => 'connect',
            ! empty($config['connection_error']) || in_array($config['connection_check'] ?? '', ['failed', 'phone_mismatch'], true)
                || in_array($config['subscription_state'] ?? '', ['failed', 'missing'], true) => 'attention',
            default => 'connected',
        };
    }

    /**
     * Until when the operator can ask Meta for the WhatsApp Business app's contacts and chat history (24 hours after a
     * coexistence onboarding), or null when that is not possible or already asked.
     */
    public function historyDeadline(?CoreIntegration $integration): ?CarbonImmutable
    {
        $config = $integration?->config ?? [];
        $signedUpAt = $config['signup_completed_at'] ?? null;
        if (($config['connected_via'] ?? '') !== 'embedded_signup' || ! in_array($config['coexistence_state'] ?? '', ['signup_reported', 'unknown'], true)
            || ($config['history_sync'] ?? null) === 'requested' || ! filled($config['phone_number_id'] ?? null) || ! is_string($signedUpAt)) {
            return null;
        }
        $deadline = CarbonImmutable::parse($signedUpAt)->addHours(24);

        return $deadline->isFuture() ? $deadline : null;
    }

    public function assertBindingAvailable(CoreIntegration $integration, array $data): bool
    {
        $errors = [];
        foreach (['waba_id' => 'WABA ID', 'phone_number_id' => 'Phone Number ID'] as $key => $label) {
            $current = (string) data_get($integration->config, $key, '');
            if ($current !== '' && $current !== (string) ($data[$key] ?? '')) {
                $errors[$key] = $label.' değiştirilemiyor: kayıtlı görüşme veya tamamlanmamış mesaj aktarımı var. Mevcut numarayı seçin.';
            }
        }
        if ($errors !== [] && (
            WhatsAppConversation::query()->where('integration_id', $integration->id)->exists()
            || WhatsAppWebhookReceipt::query()->where('integration_id', $integration->id)->where('status', '!=', 'completed')->exists()
        )) {
            throw ValidationException::withMessages($errors);
        }

        return $errors !== [];
    }
}

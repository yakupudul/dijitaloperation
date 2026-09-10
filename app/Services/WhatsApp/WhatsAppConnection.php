<?php

namespace App\Services\WhatsApp;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppWebhookReceipt;
use App\Support\Permissions;
use App\Support\Roles;
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
            'business_context' => ['required', 'string', 'max:12000'],
            'enabled' => ['required', 'boolean'],
            'automatic_suggestions' => ['required', 'boolean'],
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
            $bindingChanged = false;
            $bindingErrors = [];
            foreach (['waba_id' => 'WABA ID', 'phone_number_id' => 'Phone Number ID'] as $key => $label) {
                if (filled($config[$key] ?? null) && (string) $config[$key] !== (string) $data[$key]) {
                    $bindingChanged = true;
                    $bindingErrors[$key] = $label.' değiştirilemiyor: kayıtlı görüşme veya tamamlanmamış mesaj aktarımı var. Mevcut kayıtlı değeri kullanın; başka numaraya geçiş geçmiş veriler korunarak ayrıca yapılmalı.';
                }
            }
            if ($bindingChanged) {
                $hasHistory = WhatsAppConversation::query()->where('integration_id', $integration->id)->exists();
                $hasUnprocessedReceipts = WhatsAppWebhookReceipt::query()->where('integration_id', $integration->id)
                    ->where('status', '!=', 'completed')->exists();
                if ($hasHistory || $hasUnprocessedReceipts) {
                    throw ValidationException::withMessages($bindingErrors);
                }
                unset($config['history_state'], $config['echo_seen_at'], $config['last_receipt_at']);
            }
            $contextChanged = ($config['business_context'] ?? '') !== $data['business_context'];
            $config['connection_check_request_id'] = (string) Str::uuid();
            $config['connection_check_requested_at'] = null;
            $config['connection_check'] = 'not_checked';
            $config['connection_checked_at'] = null;
            $config['settings_saved_at'] = now()->toIso8601String();
            foreach (['waba_id', 'phone_number_id', 'business_phone', 'business_context', 'automatic_suggestions'] as $key) {
                $config[$key] = $data[$key];
            }
            $integration->update([
                'status' => $data['enabled'] ? CoreIntegration::STATUS_ACTIVE : CoreIntegration::STATUS_DISABLED,
                'config' => $config,
            ]);
            $integration->credentials()->updateOrCreate(['credential_type' => CoreIntegrationCredential::TYPE_PROVIDER], [
                'encrypted_payload' => $secrets, 'refreshed_at' => now(),
            ]);
            if ($contextChanged) {
                \App\Models\WhatsAppConversation::query()->where('integration_id', $integration->id)
                    ->update(['suggestion_status' => 'pending', 'updated_at' => now()]);
            }
        });
    }
}


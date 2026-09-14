<?php

namespace App\Services\WhatsApp;

use App\Jobs\WhatsApp\CompleteWhatsAppSignup;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Models\WhatsAppSignupAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class WhatsAppSignup
{
    public function __construct(private WhatsAppConnection $connection, private WhatsAppGraph $graph)
    {
    }

    public function saveSetup(User $user, array $input): void
    {
        $this->connection->authorize($user);
        $data = Validator::make($input, [
            'app_id' => ['required', 'regex:/^[0-9]{5,40}$/'],
            'signup_config_id' => ['required', 'regex:/^[0-9]{5,40}$/'],
            'signup_mode' => ['required', 'in:coexistence,cloud_api'],
            'app_secret' => ['nullable', 'string', 'max:255'],
            'verify_token' => ['nullable', 'string', 'min:16', 'max:255'],
        ])->validate();
        DB::transaction(function () use ($data): void {
            $row = CoreIntegration::query()->firstOrCreate(['provider' => WhatsAppConnection::PROVIDER], [
                'name' => 'WhatsApp Business', 'status' => CoreIntegration::STATUS_ACTIVE, 'config' => [],
            ]);
            $row = CoreIntegration::query()->lockForUpdate()->findOrFail($row->id);
            $this->assertIdle($row);
            $secrets = $this->connection->secrets($row);
            foreach (['app_secret' => 'Meta App Secret', 'verify_token' => 'Webhook Verify Token'] as $key => $label) {
                if (trim((string) ($data[$key] ?? '')) !== '') {
                    $secrets[$key] = trim($data[$key]);
                }
                if (empty($secrets[$key])) {
                    throw ValidationException::withMessages([$key => $label.' alanını doldurun.']);
                }
            }
            $config = $row->config ?? [];
            $appChanged = ($config['app_id'] ?? '') !== $data['app_id'];
            foreach (['app_id', 'signup_config_id', 'signup_mode'] as $key) {
                $config[$key] = $data[$key];
            }
            $config['settings_revision'] = (string) Str::uuid();
            $config['settings_saved_at'] = now()->toIso8601String();
            $config['connection_check_request_id'] = (string) Str::uuid();
            $config['connection_check'] = 'not_checked';
            $config['connection_error'] = null;
            if ($appChanged || filled($data['app_secret'] ?? null)) {
                $config['subscription_state'] = 'not_checked';
                $config['subscription_error'] = null;
            }
            if (filled($data['verify_token'] ?? null)) {
                unset($config['webhook_verified_at']);
            }
            $row->update(['config' => $config]);
            $row->credentials()->updateOrCreate(['credential_type' => CoreIntegrationCredential::TYPE_PROVIDER], [
                'encrypted_payload' => $secrets, 'refreshed_at' => now(),
            ]);
            $this->expireUnsubmitted($row);
        });
    }

    /** Must be called under the integration row lock by every settings writer. */
    public function assertIdle(CoreIntegration $row): void
    {
        if (WhatsAppSignupAttempt::query()->where('integration_id', $row->id)
            ->whereIn('status', ['queued', 'running'])->exists()) {
            throw ValidationException::withMessages(['connection' => 'Hesap bağlantısı arka planda tamamlanıyor. İşlem sonuçlandıktan sonra ayarları değiştirebilirsiniz.']);
        }
    }

    public function begin(User $user, string $sessionId, bool $subscriptionOnly = false): WhatsAppSignupAttempt
    {
        $this->connection->authorize($user);

        return DB::transaction(function () use ($user, $sessionId, $subscriptionOnly): WhatsAppSignupAttempt {
            $row = CoreIntegration::query()->where('provider', WhatsAppConnection::PROVIDER)->lockForUpdate()->first();
            if (! $row || ! filled(data_get($row->config, 'app_id')) || ! filled(data_get($row->config, 'signup_config_id'))) {
                throw ValidationException::withMessages(['connection' => 'Önce Meta App ID ve Configuration ID bilgilerini kaydedin.']);
            }
            $this->assertIdle($row);
            $secrets = $this->connection->secrets($row);
            if (empty($secrets['app_secret']) || empty($secrets['verify_token']) || ! $row->isActive()) {
                throw ValidationException::withMessages(['connection' => 'App Secret ve Verify Token kayıtlı olmalı; mesaj alımını etkinleştirin.']);
            }
            if ($subscriptionOnly && (empty($secrets['access_token']) || ! filled(data_get($row->config, 'waba_id')))) {
                throw ValidationException::withMessages(['connection' => 'Webhook aboneliği için önce WhatsApp hesabını bağlayın.']);
            }
            $this->expireUnsubmitted($row);
            $config = $row->config ?? [];
            $config['settings_revision'] ??= (string) Str::uuid();
            $config['connection_check_request_id'] = (string) Str::uuid();
            if (($config['connection_check'] ?? '') === 'queued') {
                $config['connection_check'] = 'not_checked';
            }
            $attempt = WhatsAppSignupAttempt::query()->create([
                'id' => (string) Str::uuid(), 'integration_id' => $row->id, 'user_id' => $user->id,
                'session_hash' => hash('sha256', $sessionId), 'settings_revision' => $config['settings_revision'],
                'mode' => $subscriptionOnly ? 'subscription' : ($config['signup_mode'] ?? 'coexistence'),
                'status' => $subscriptionOnly ? 'queued' : 'prepared',
                'step' => $subscriptionOnly ? 'subscribe' : 'facebook', 'expires_at' => now()->addMinutes(15),
            ]);
            $config['last_signup_id'] = $attempt->id;
            $row->update(['config' => $config]);

            return $attempt;
        });
    }

    public function owned(string $id, User $user, string $sessionId): WhatsAppSignupAttempt
    {
        $this->connection->authorize($user);
        $attempt = WhatsAppSignupAttempt::query()->where('user_id', $user->id)->findOrFail($id);
        abort_unless(hash_equals($attempt->session_hash, hash('sha256', $sessionId)), 403);

        return $attempt;
    }

    public function submit(WhatsAppSignupAttempt $attempt, array $data): void
    {
        DB::transaction(function () use ($attempt, $data): void {
            $row = CoreIntegration::query()->lockForUpdate()->findOrFail($attempt->integration_id);
            $attempt = WhatsAppSignupAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            abort_unless($attempt->expires_at->isFuture() && $attempt->settings_revision === data_get($row->config, 'settings_revision'), 409);
            if (in_array($attempt->status, ['queued', 'running', 'completed'], true)) {
                return;
            }
            abort_unless($attempt->status === 'prepared', 409);
            if ($attempt->mode === 'coexistence' && $data['event'] !== 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING') {
                throw ValidationException::withMessages(['signup' => 'Meta, WhatsApp Business uygulamasıyla birlikte kullanım adımını tamamlamadı. Embedded Signup yapılandırmasında Coexistence seçeneğini kontrol edin.']);
            }
            $this->connection->assertBindingAvailable($row, ['waba_id' => $data['waba_id'], 'phone_number_id' => $data['phone_number_id'] ?? data_get($row->config, 'phone_number_id', '')]);
            $attempt->update([
                'status' => 'queued', 'step' => 'exchange_code',
                'payload' => ['code' => $data['code'], 'waba_id' => $data['waba_id'], 'phone_number_id' => $data['phone_number_id'] ?? null],
            ]);
        });
        $this->dispatch($attempt);
    }

    public function selectPhone(WhatsAppSignupAttempt $attempt, string $phoneId): void
    {
        DB::transaction(function () use ($attempt, $phoneId): void {
            $row = CoreIntegration::query()->lockForUpdate()->findOrFail($attempt->integration_id);
            $attempt = WhatsAppSignupAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            abort_unless($attempt->status === 'choose_phone' && $attempt->expires_at->isFuture()
                && $attempt->settings_revision === data_get($row->config, 'settings_revision'), 409);
            $payload = $attempt->payload ?? [];
            abort_unless(collect($payload['phones'] ?? [])->contains(fn ($phone) => (string) $phone['id'] === $phoneId), 422);
            $payload['phone_number_id'] = $phoneId;
            $attempt->update(['payload' => $payload, 'status' => 'queued', 'step' => 'verify_phone', 'details' => null]);
        });
        $this->dispatch($attempt);
    }

    public function dispatch(WhatsAppSignupAttempt $attempt): void
    {
        try {
            CompleteWhatsAppSignup::dispatch($attempt->id);
        } catch (Throwable $exception) {
            // Durable queued attempt remains eligible for the existing minute scheduler.
        }
    }

    public function complete(string $id): void
    {
        $snapshot = WhatsAppSignupAttempt::query()->find($id);
        if (! $snapshot) {
            return;
        }
        $attempt = DB::transaction(function () use ($snapshot): ?WhatsAppSignupAttempt {
            $row = CoreIntegration::query()->lockForUpdate()->find($snapshot->integration_id);
            $attempt = WhatsAppSignupAttempt::query()->lockForUpdate()->find($snapshot->id);
            if (! $row || ! $attempt || $attempt->status !== 'queued') {
                return null;
            }
            if (! $attempt->expires_at->isFuture() || ! $row->isActive()
                || $attempt->settings_revision !== data_get($row->config, 'settings_revision')) {
                $attempt->update(['status' => 'failed', 'payload' => null, 'details' => ['message' => 'Bağlantı süresi doldu veya ayarlar değişti. Yeniden bağlayın.']]);

                return null;
            }
            $attempt->update(['status' => 'running']);

            return $attempt;
        });
        if (! $attempt) {
            return;
        }
        try {
            $user = User::query()->find($attempt->user_id);
            $this->connection->authorize($user);
            $row = CoreIntegration::query()->findOrFail($attempt->integration_id);
            $secrets = $this->connection->secrets($row);
            $appId = (string) data_get($row->config, 'app_id');
            $secret = (string) ($secrets['app_secret'] ?? '');
            if ($attempt->mode !== 'subscription') {
                $payload = $attempt->payload ?? [];
                $token = (string) ($payload['access_token'] ?? '');
                if ($token === '') {
                    $body = $this->graph->request('GET', 'oauth/access_token', [
                        'client_id' => $appId, 'client_secret' => $secret, 'code' => (string) ($payload['code'] ?? ''),
                    ]);
                    $token = is_string($body['access_token'] ?? null) ? $body['access_token'] : '';
                    if ($token === '') {
                        throw new WhatsAppGraphException(['message' => 'Meta erişim tokenı döndürmedi. Bağlantıyı yeniden başlatın.']);
                    }
                    unset($payload['code']);
                    $payload['access_token'] = $token;
                    $attempt->update(['payload' => $payload, 'step' => 'verify_token']);
                }
                $debug = $this->graph->request('GET', 'debug_token', ['input_token' => $token], $appId.'|'.$secret, $secret);
                if (data_get($debug, 'data.is_valid') !== true || (string) data_get($debug, 'data.app_id') !== $appId) {
                    throw new WhatsAppGraphException(['message' => 'Token geçerli değil veya farklı bir Meta uygulamasına ait. App ID / App Secret eşleşmesini kontrol edin.']);
                }
                foreach (['whatsapp_business_management', 'whatsapp_business_messaging'] as $scope) {
                    if (! in_array($scope, (array) data_get($debug, 'data.scopes', []), true)) {
                        throw new WhatsAppGraphException(['message' => 'Gerekli izin verilmedi: '.$scope.'. Meta erişim seviyesini kontrol edip yeniden bağlayın.']);
                    }
                }
                $wabaId = (string) $payload['waba_id'];
                $attempt->update(['step' => 'verify_phone']);
                $list = $this->graph->request('GET', $wabaId.'/phone_numbers', ['fields' => 'id,display_phone_number,verified_name', 'limit' => 100], $token, $secret);
                $phones = collect($list['data'] ?? [])->filter(fn ($phone) => is_array($phone) && preg_match('/^[0-9]{5,40}$/', (string) ($phone['id'] ?? '')))
                    ->map(fn ($phone) => ['id' => (string) $phone['id'], 'display_phone_number' => (string) ($phone['display_phone_number'] ?? ''), 'verified_name' => (string) ($phone['verified_name'] ?? '')])->values()->all();
                if ($phones === []) {
                    throw new WhatsAppGraphException(['message' => 'Seçilen WABA altında erişilebilir telefon numarası bulunamadı. Meta numara kaydını ve izinlerini kontrol edin.']);
                }
                $phoneId = (string) ($payload['phone_number_id'] ?? '');
                if ($phoneId === '') {
                    $payload['phones'] = $phones;
                    $attempt->update(['status' => 'choose_phone', 'payload' => $payload, 'details' => ['message' => 'Meta numara seçimini iletmedi. Bağlamak istediğiniz numarayı seçin.']]);

                    return;
                }
                $phone = collect($phones)->first(fn ($phone) => $phone['id'] === $phoneId);
                if (! $phone) {
                    throw new WhatsAppGraphException(['message' => 'Seçilen numara bu WABA için dönen telefon listesinde bulunamadı. WABA ve numara seçimini kontrol edin.']);
                }
                $number = preg_replace('/[^0-9]/', '', $phone['display_phone_number']);
                if (! preg_match('/^[0-9]{7,20}$/', $number)) {
                    throw new WhatsAppGraphException(['message' => 'Meta geçerli bir işletme telefon numarası döndürmedi.']);
                }
                DB::transaction(function () use ($attempt, $wabaId, $phoneId, $number, $token, $debug): void {
                    $row = CoreIntegration::query()->lockForUpdate()->findOrFail($attempt->integration_id);
                    $current = WhatsAppSignupAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                    if ($current->status !== 'running' || $current->settings_revision !== data_get($row->config, 'settings_revision')) {
                        throw new WhatsAppGraphException(['message' => 'Bağlantı sırasında ayarlar değişti. Yeniden bağlayın.']);
                    }
                    $binding = ['waba_id' => $wabaId, 'phone_number_id' => $phoneId];
                    $changed = $this->connection->assertBindingAvailable($row, $binding);
                    $config = $row->config ?? [];
                    if ($changed) {
                        unset($config['history_state'], $config['echo_seen_at'], $config['last_receipt_at'], $config['last_message_received_at']);
                    }
                    $config = array_merge($config, $binding, [
                        'business_phone' => $number, 'connection_check' => 'verified', 'connection_error' => null,
                        'connection_check_request_id' => (string) Str::uuid(), 'connection_checked_at' => now()->toIso8601String(),
                        'subscription_state' => 'not_checked', 'subscription_error' => null,
                        'connected_via' => 'embedded_signup', 'signup_completed_at' => now()->toIso8601String(),
                        'coexistence_state' => $attempt->mode === 'coexistence' ? 'signup_reported' : 'not_requested',
                        'token_expires_at' => data_get($debug, 'data.expires_at'),
                    ]);
                    $secrets = $this->connection->secrets($row);
                    $secrets['access_token'] = $token;
                    $row->credentials()->updateOrCreate(['credential_type' => CoreIntegrationCredential::TYPE_PROVIDER], [
                        'encrypted_payload' => $secrets, 'refreshed_at' => now(),
                    ]);
                    $row->update(['config' => $config]);
                    $current->update(['step' => 'subscribe', 'payload' => null]);
                });
            }
            $attempt->refresh();
            $row->refresh();
            $secrets = $this->connection->secrets($row);
            $wabaId = (string) data_get($row->config, 'waba_id');
            $token = (string) ($secrets['access_token'] ?? '');
            if ($attempt->mode === 'subscription') {
                $debug = $this->graph->request('GET', 'debug_token', ['input_token' => $token], $appId.'|'.$secret, $secret);
                if (data_get($debug, 'data.is_valid') !== true || (string) data_get($debug, 'data.app_id') !== $appId) {
                    throw new WhatsAppGraphException(['message' => 'Token kayıtlı WhatsApp uygulamasıyla eşleşmiyor. Hesabı yeniden bağlayın.']);
                }
            }
            $result = $this->graph->request('POST', $wabaId.'/subscribed_apps', [], $token, $secret);
            if (($result['success'] ?? false) !== true) {
                throw new WhatsAppGraphException(['message' => 'Meta webhook aboneliğini onaylamadı.']);
            }
            $subscriptions = $this->graph->request('GET', $wabaId.'/subscribed_apps', [], $token, $secret);
            if (! $this->graph->subscribed($subscriptions, $appId)) {
                throw new WhatsAppGraphException(['message' => 'Uygulama, WABA abonelik listesinde doğrulanamadı.']);
            }
            $this->finish($attempt, 'completed', ['message' => 'Hesap ve WABA aboneliği doğrulandı. Gelen gerçek mesajlar ayrıca izleniyor.']);
        } catch (ValidationException $exception) {
            $this->finish($attempt, 'failed', ['message' => collect($exception->errors())->flatten()->first()]);
        } catch (WhatsAppGraphException $exception) {
            $this->finish($attempt, 'failed', $exception->details);
        } catch (Throwable $exception) {
            $this->finish($attempt, 'failed', ['message' => 'Bağlantı tamamlanamadı. Ayarları ve kuyruk hizmetini kontrol edip yeniden deneyin.']);
        }
    }

    private function finish(WhatsAppSignupAttempt $attempt, string $status, array $details): void
    {
        DB::transaction(function () use ($attempt, $status, $details): void {
            $row = CoreIntegration::query()->lockForUpdate()->find($attempt->integration_id);
            $current = WhatsAppSignupAttempt::query()->lockForUpdate()->find($attempt->id);
            if (! $row || ! $current || $current->status !== 'running') {
                return;
            }
            $subscription = $current->step === 'subscribe' || $current->mode === 'subscription';
            $current->update(['status' => $status === 'failed' && $subscription ? 'partial' : $status, 'payload' => null, 'details' => $details]);
            if ($subscription && $current->settings_revision === data_get($row->config, 'settings_revision')) {
                $config = $row->config ?? [];
                $config['subscription_state'] = $status === 'completed' ? 'verified' : 'failed';
                $config['subscription_error'] = $status === 'completed' ? null : $details;
                $config['subscription_checked_at'] = now()->toIso8601String();
                $row->update(['config' => $config]);
            }
        });
    }

    private function expireUnsubmitted(CoreIntegration $row): void
    {
        WhatsAppSignupAttempt::query()->where('integration_id', $row->id)->whereIn('status', ['prepared', 'choose_phone'])
            ->update(['status' => 'expired', 'payload' => null, 'updated_at' => now()]);
    }
}

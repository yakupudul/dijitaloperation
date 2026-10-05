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
    /** Accounts read from the token's scopes when Meta did not say which one was chosen. */
    private const MAX_SHARED_ACCOUNTS = 20;

    public function __construct(private WhatsAppConnection $connection, private WhatsAppGraph $graph) {}

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
            ->whereIn('status', ['exchanging', 'queued', 'running'])->exists()) {
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

    /**
     * The Meta popup finished. Meta's authorization code lives only ~30 seconds, so it is exchanged right here
     * (one Graph call) before the rest of the connection continues in the background. A popup that sent no account
     * selection (event CODE_ONLY) still connects: the job finds the shared WhatsApp account from the token.
     */
    public function submit(WhatsAppSignupAttempt $attempt, array $data): void
    {
        $proceed = DB::transaction(function () use ($attempt, $data): bool {
            $row = CoreIntegration::query()->lockForUpdate()->findOrFail($attempt->integration_id);
            $attempt = WhatsAppSignupAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            abort_unless($attempt->expires_at->isFuture() && $attempt->settings_revision === data_get($row->config, 'settings_revision'), 409);
            if (in_array($attempt->status, ['exchanging', 'queued', 'running', 'completed'], true)) {
                return false;
            }
            abort_unless(in_array($attempt->status, ['prepared', 'cancelled'], true), 409);
            $wabaId = $data['waba_id'] ?? null;
            if ($wabaId !== null) {
                $this->connection->assertBindingAvailable($row, ['waba_id' => $wabaId, 'phone_number_id' => $data['phone_number_id'] ?? data_get($row->config, 'phone_number_id', '')]);
            }
            $attempt->update([
                'status' => 'exchanging', 'step' => 'exchange_code', 'details' => null,
                'payload' => ['code' => $data['code'], 'waba_id' => $wabaId, 'phone_number_id' => $data['phone_number_id'] ?? null,
                    // Meta reported how the popup finished but without an account id: keep the kind (e.g. app onboarding).
                    'event' => $data['event'] === 'CODE_ONLY' && filled($data['finish_event'] ?? null) ? $data['finish_event'] : $data['event']],
            ]);

            return true;
        });
        if (! $proceed) {
            return;
        }
        $attempt->refresh();
        $payload = $attempt->payload ?? [];
        try {
            $row = CoreIntegration::query()->findOrFail($attempt->integration_id);
            $secrets = $this->connection->secrets($row);
            $body = $this->graph->request('GET', 'oauth/access_token', [
                'client_id' => (string) data_get($row->config, 'app_id'), 'client_secret' => (string) ($secrets['app_secret'] ?? ''),
                'code' => (string) ($payload['code'] ?? ''),
            ]);
            $token = is_string($body['access_token'] ?? null) ? $body['access_token'] : '';
            if ($token === '') {
                throw new WhatsAppGraphException(['message' => 'Meta erişim anahtarı döndürmedi. Bağlantıyı yeniden başlatın.']);
            }
            unset($payload['code']);
            $payload['access_token'] = $token;
            $next = ['status' => 'queued', 'step' => 'verify_token', 'payload' => $payload];
        } catch (WhatsAppGraphException $exception) {
            $next = ['status' => 'failed', 'payload' => null, 'details' => [...$exception->details, 'stage' => 'exchange_code']];
        } catch (Throwable $exception) {
            report($exception);
            $next = ['status' => 'failed', 'payload' => null, 'details' => ['message' => 'Meta yetkisi alınamadı. Bağlantıyı yeniden başlatın.']];
        }
        WhatsAppSignupAttempt::query()->whereKey($attempt->id)->where('status', 'exchanging')->first()?->update($next);
        if ($next['status'] === 'queued') {
            $this->dispatch($attempt);
        }
    }

    /**
     * The popup ended without a result (closed, cancelled at a step, or Meta showed an error). Kept on the attempt so
     * the screen says where it stopped; the same attempt can still be finished from the connect page.
     */
    public function report(WhatsAppSignupAttempt $attempt, array $data): void
    {
        DB::transaction(function () use ($attempt, $data): void {
            $attempt = WhatsAppSignupAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if (! in_array($attempt->status, ['prepared', 'cancelled'], true) || ! $attempt->expires_at->isFuture()) {
                return;
            }
            $step = filled($data['current_step'] ?? null) ? (string) $data['current_step'] : null;
            $error = filled($data['error_message'] ?? null) ? (string) $data['error_message'] : null;
            $domain = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'app.moximu.com');
            $allowed = 'Meta uygulamasında Facebook Login for Business → Ayarlar bölümündeki "Allowed Domains" ve "Valid OAuth redirect URIs" listelerinde https://'.$domain.' olmalı.';
            $message = match (true) {
                $error !== null => 'Meta bağlantı penceresinde hata gösterdi: '.$error,
                $data['event'] === 'LOGIN_REFUSED' => 'Facebook penceresi açılır açılmaz sonuçsuz döndü: ya tarayıcı açılır pencereyi engelledi ya da alan adı Meta\'da izinli değil. '.$allowed,
                $data['event'] === 'NO_CODE' => 'Meta hesap seçimini bildirdi ama yetki kodunu vermedi. '.$allowed,
                $step !== null => 'Meta penceresi "'.WhatsAppErrorText::step($step).'" adımında kapatıldı; bağlantı tamamlanmadı.',
                $data['event'] === 'POPUP_CLOSED' => 'Meta penceresi kapandı ama Meta hiçbir sonuç iletmedi. Penceredeki adımların hepsini (numara doğrulama dahil) bitirip son ekranda "Bitti" deyin.',
                default => 'Meta bağlantısı tamamlanmadı.',
            };
            $attempt->update(['status' => 'cancelled', 'details' => array_filter([
                'message' => $message, 'event' => $data['event'], 'meta_step' => $step, 'meta_error' => $error,
                'error_id' => $data['error_id'] ?? null, 'session_id' => $data['session_id'] ?? null,
            ], fn ($value) => $value !== null && $value !== '')]);
        });
    }

    public function selectPhone(WhatsAppSignupAttempt $attempt, string $phoneId): void
    {
        DB::transaction(function () use ($attempt, $phoneId): void {
            $row = CoreIntegration::query()->lockForUpdate()->findOrFail($attempt->integration_id);
            $attempt = WhatsAppSignupAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            abort_unless($attempt->status === 'choose_phone' && $attempt->expires_at->isFuture()
                && $attempt->settings_revision === data_get($row->config, 'settings_revision'), 409);
            $payload = $attempt->payload ?? [];
            $phone = collect($payload['phones'] ?? [])->first(fn ($phone) => (string) $phone['id'] === $phoneId);
            abort_unless($phone !== null, 422);
            $payload['phone_number_id'] = $phoneId;
            $payload['waba_id'] = (string) ($phone['waba_id'] ?? $payload['waba_id'] ?? '');
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
                $attempt->update(['step' => 'verify_phone']);
                $reportedWaba = preg_match('/^[0-9]{5,40}$/', (string) ($payload['waba_id'] ?? '')) === 1;
                $wabaIds = $this->sharedAccounts($payload, $debug);
                $phones = [];
                foreach ($wabaIds as $candidate) {
                    $list = $this->graph->request('GET', $candidate.'/phone_numbers', ['fields' => 'id,display_phone_number,verified_name', 'limit' => 100], $token, $secret);
                    foreach (collect($list['data'] ?? [])->filter(fn ($phone) => is_array($phone) && preg_match('/^[0-9]{5,40}$/', (string) ($phone['id'] ?? ''))) as $phone) {
                        $phones[] = ['id' => (string) $phone['id'], 'display_phone_number' => (string) ($phone['display_phone_number'] ?? ''), 'verified_name' => (string) ($phone['verified_name'] ?? ''), 'waba_id' => $candidate];
                    }
                }
                if ($phones === []) {
                    throw new WhatsAppGraphException(['message' => 'Paylaşılan WhatsApp hesabında numara bulunamadı. Meta penceresinde numarayı ekleyip doğrulama adımını bitirdiğinizden emin olun.']);
                }
                $phoneId = (string) ($payload['phone_number_id'] ?? '');
                // A single number is taken as is only when it surely belongs to the account chosen in this popup:
                // reported by Meta, or the token covers just one account. Otherwise the operator confirms it.
                if ($phoneId === '' && count($phones) === 1 && ($reportedWaba || count($wabaIds) === 1)) {
                    $phoneId = $phones[0]['id'];
                }
                if ($phoneId === '') {
                    $payload['phones'] = $phones;
                    $message = match (true) {
                        $reportedWaba => 'Meta birden fazla numara paylaştı. Bağlamak istediğiniz numarayı seçin.',
                        count($wabaIds) >= self::MAX_SHARED_ACCOUNTS => 'Meta hangi hesabı seçtiğinizi bildirmedi ve çok sayıda hesap paylaştı; ilk '.self::MAX_SHARED_ACCOUNTS.' hesabın numaraları listelendi. Bağlamak istediğiniz numarayı seçin.',
                        default => 'Meta hangi hesabı seçtiğinizi bildirmedi. Bağlamak istediğiniz numarayı seçin.',
                    };
                    $attempt->update(['status' => 'choose_phone', 'payload' => $payload, 'details' => ['message' => $message]]);

                    return;
                }
                $phone = collect($phones)->first(fn ($phone) => $phone['id'] === $phoneId);
                if (! $phone) {
                    throw new WhatsAppGraphException(['message' => 'Seçilen numara, paylaşılan WhatsApp hesabının numaraları arasında yok. Bağlantıyı yeniden başlatın.']);
                }
                $wabaId = $phone['waba_id'];
                $number = preg_replace('/[^0-9]/', '', $phone['display_phone_number']);
                if (! preg_match('/^[0-9]{7,20}$/', $number)) {
                    throw new WhatsAppGraphException(['message' => 'Meta geçerli bir işletme telefon numarası döndürmedi.']);
                }
                $coexistence = match (true) {
                    ($payload['event'] ?? '') === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING' => 'signup_reported',
                    $attempt->mode !== 'coexistence' => 'not_requested',
                    ($payload['event'] ?? '') === 'FINISH' => 'not_used',
                    default => 'unknown',
                };
                if ($coexistence !== 'signup_reported') {
                    // Read the path from the number itself: a business-app number, or a Cloud API number that was
                    // never registered (MoxDOP does not register numbers, so no message would ever arrive).
                    $platform = $this->phonePlatform($phoneId, $token, $secret);
                    if (($platform['is_on_biz_app'] ?? null) === true) {
                        $coexistence = 'signup_reported';
                    } elseif (($platform['platform_type'] ?? null) === 'NOT_APPLICABLE') {
                        throw new WhatsAppGraphException(['message' => $attempt->mode === 'coexistence'
                            ? 'Meta penceresinde numara yeni bir Cloud API numarası olarak eklendi ama Meta\'da kaydı tamamlanmadı; bu haliyle mesaj gelmez (MoxDOP numara kaydı yapmaz). Telefondaki WhatsApp Business numaranızı bağlamak için yeniden deneyin ve penceredeki "Mevcut WhatsApp Business uygulamanızı bağlayın" seçeneğini seçin.'
                            : 'Numara Meta\'da Cloud API\'ye henüz kaydedilmemiş; bu haliyle mesaj gelmez (MoxDOP numara kaydı yapmaz). Numarayı Meta tarafında kaydettirip yeniden bağlayın.']);
                    }
                }
                DB::transaction(function () use ($attempt, $wabaId, $phoneId, $number, $token, $debug, $coexistence): void {
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
                        'coexistence_state' => $coexistence, 'history_sync' => null, 'history_sync_error' => null,
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
            $message = $this->connection->historyDeadline($row) !== null
                ? 'Numara bağlandı ve mesaj aboneliği doğrulandı. Telefondaki geçmiş mesajları almak için 24 saat içinde WhatsApp ekranındaki "Geçmiş mesajları al" düğmesine basın.'
                : 'Numara bağlandı ve mesaj aboneliği doğrulandı. Gelen ilk mesaj burada görünecek.';
            $this->finish($attempt, 'completed', ['message' => $message]);
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

    /**
     * WhatsApp accounts the operator shared in the popup: the one Meta reported, else the accounts the token was
     * granted on (debug_token granular scopes) when the popup sent no account selection.
     *
     * @return list<string>
     */
    private function sharedAccounts(array $payload, array $debug): array
    {
        $reported = (string) ($payload['waba_id'] ?? '');
        if (preg_match('/^[0-9]{5,40}$/', $reported)) {
            return [$reported];
        }
        $ids = collect((array) data_get($debug, 'data.granular_scopes', []))
            ->filter(fn ($scope) => is_array($scope) && in_array($scope['scope'] ?? null, ['whatsapp_business_management', 'whatsapp_business_messaging'], true))
            ->flatMap(fn (array $scope) => (array) ($scope['target_ids'] ?? []))
            ->map(fn ($id) => (string) $id)->filter(fn (string $id) => preg_match('/^[0-9]{5,40}$/', $id) === 1)
            ->unique()->take(self::MAX_SHARED_ACCOUNTS)->values()->all();
        if ($ids === []) {
            throw new WhatsAppGraphException(['message' => 'Meta hangi WhatsApp hesabının paylaşıldığını bildirmedi. Bağlantıyı yeniden başlatıp penceredeki adımların hepsini bitirin.']);
        }

        return $ids;
    }

    /**
     * Coexistence, on the operator's click: asks Meta for the WhatsApp Business app's chat history, which Meta shares
     * only within 24 hours of onboarding (contacts sync is not asked: MoxDOP does not use it). The messages arrive
     * later as history webhooks; a failure here does not undo the connection and can be retried inside the window.
     */
    public function requestHistory(User $user): bool
    {
        $this->connection->authorize($user);
        $row = $this->connection->integration();
        if (! $row || $this->connection->historyDeadline($row) === null) {
            throw ValidationException::withMessages(['history' => 'Geçmiş mesajlar yalnız WhatsApp Business uygulamasıyla bağlanan numara için, bağlantıdan sonraki 24 saat içinde istenebilir.']);
        }
        $secrets = $this->connection->secrets($row);
        $token = (string) ($secrets['access_token'] ?? '');
        $secret = (string) ($secrets['app_secret'] ?? '');
        $phoneId = (string) data_get($row->config, 'phone_number_id');
        $state = 'requested';
        $error = null;
        try {
            $this->graph->request('POST', $phoneId.'/smb_app_data', ['messaging_product' => 'whatsapp', 'sync_type' => 'history'], $token, $secret);
        } catch (WhatsAppGraphException $exception) {
            $state = 'failed';
            $error = $exception->details;
        }
        DB::transaction(function () use ($row, $state, $error): void {
            $current = CoreIntegration::query()->lockForUpdate()->find($row->id);
            if ($current) {
                $current->update(['config' => [...($current->config ?? []), 'history_sync' => $state, 'history_sync_error' => $error, 'history_sync_requested_at' => now()->toIso8601String()]]);
            }
        });

        return $state === 'requested';
    }

    /**
     * The number's platform as Meta reports it (is_on_biz_app, platform_type), or [] when Meta does not answer: the
     * check then does not block the connection.
     *
     * @return array<string, mixed>
     */
    private function phonePlatform(string $phoneId, string $token, string $secret): array
    {
        try {
            return $this->graph->request('GET', $phoneId, ['fields' => 'platform_type,is_on_biz_app'], $token, $secret);
        } catch (WhatsAppGraphException) {
            return [];
        }
    }

    private function expireUnsubmitted(CoreIntegration $row): void
    {
        WhatsAppSignupAttempt::query()->where('integration_id', $row->id)->whereIn('status', ['prepared', 'cancelled', 'choose_phone'])
            ->update(['status' => 'expired', 'payload' => null, 'updated_at' => now()]);
    }
}

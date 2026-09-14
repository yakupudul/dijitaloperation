<?php

namespace App\Jobs\WhatsApp;

use App\Models\CoreIntegration;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppGraph;
use App\Services\WhatsApp\WhatsAppGraphException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckWhatsAppConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;
    public int $timeout = 90;
    public int $tries = 1;

    public function __construct(public int $integrationId, public ?string $requestId = null)
    {
        $this->onConnection('redis')->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'wa-check:'.$this->integrationId.':'.$this->requestId;
    }

    public function handle(WhatsAppConnection $connection): void
    {
        $integration = CoreIntegration::query()->find($this->integrationId);
        if (! $integration?->isActive()) {
            return;
        }
        $requestId = $this->requestId ?? data_get($integration->config, 'connection_check_request_id');
        if ($requestId !== data_get($integration->config, 'connection_check_request_id')) {
            return;
        }
        $state = 'failed';
        $error = null;
        $subscription = 'not_checked';
        $subscriptionError = null;
        $graph = app(WhatsAppGraph::class);
        try {
            $phoneId = (string) data_get($integration->config, 'phone_number_id');
            $wabaId = (string) data_get($integration->config, 'waba_id');
            $appId = (string) data_get($integration->config, 'app_id', '');
            $secrets = $connection->secrets($integration);
            $token = (string) ($secrets['access_token'] ?? '');
            $secret = (string) ($secrets['app_secret'] ?? '');
            if ($token === '' || ! preg_match('/^[0-9]{5,40}$/', $phoneId) || ! preg_match('/^[0-9]{5,40}$/', $wabaId)) {
                throw new WhatsAppGraphException(['message' => 'Access Token, WABA ID veya Phone Number ID eksik. Hesabı bağlayın veya manuel bilgileri kaydedin.']);
            }
            $phone = $graph->request('GET', $phoneId, ['fields' => 'id,display_phone_number'], $token, $secret);
            $number = preg_replace('/[^0-9]/', '', (string) ($phone['display_phone_number'] ?? ''));
            if ((string) ($phone['id'] ?? '') !== $phoneId) {
                throw new WhatsAppGraphException(['message' => 'Meta farklı bir Phone Number ID döndürdü.']);
            }
            if ($number !== (string) data_get($integration->config, 'business_phone')) {
                $state = 'phone_mismatch';
                throw new WhatsAppGraphException(['message' => 'Kayıtlı işletme numarası Meta ile eşleşmiyor. Meta numarası: '.$number]);
            }
            $phones = $graph->request('GET', $wabaId.'/phone_numbers', ['fields' => 'id', 'limit' => 100], $token, $secret);
            if (! collect($phones['data'] ?? [])->contains(fn ($item) => (string) ($item['id'] ?? '') === $phoneId)) {
                throw new WhatsAppGraphException(['message' => 'Phone Number ID, kayıtlı WABA için dönen listede bulunamadı. Hesap ve numara eşleşmesini kontrol edin.']);
            }
            if ($appId !== '') {
                $debug = $graph->request('GET', 'debug_token', ['input_token' => $token], $appId.'|'.$secret, $secret);
                if (data_get($debug, 'data.is_valid') !== true || (string) data_get($debug, 'data.app_id') !== $appId) {
                    throw new WhatsAppGraphException(['message' => 'Token geçersiz veya kayıtlı App ID ile eşleşmiyor.']);
                }
            }
            $state = 'verified';
            if ($appId !== '') {
                try {
                    $body = $graph->request('GET', $wabaId.'/subscribed_apps', [], $token, $secret);
                    $subscription = $graph->subscribed($body, $appId) ? 'verified' : 'missing';
                } catch (WhatsAppGraphException $exception) {
                    $subscription = 'failed';
                    $subscriptionError = $exception->details;
                }
            } else {
                $subscription = 'app_id_missing';
            }
        } catch (WhatsAppGraphException $exception) {
            $error = $exception->details;
        } catch (Throwable $exception) {
            $error = ['message' => 'Kontrol tamamlanamadı. Kuyruk hizmetini ve bağlantı ayarlarını kontrol edin.'];
        }
        DB::transaction(function () use ($state, $error, $subscription, $subscriptionError, $requestId): void {
            $current = CoreIntegration::query()->lockForUpdate()->find($this->integrationId);
            if (! $current) {
                return;
            }
            $config = $current->config ?? [];
            if (($config['connection_check_request_id'] ?? null) !== $requestId) {
                return;
            }
            $config['connection_check'] = $state;
            $config['connection_error'] = $error;
            $config['connection_checked_at'] = now()->toIso8601String();
            $config['subscription_state'] = $subscription;
            $config['subscription_error'] = $subscriptionError;
            $config['subscription_checked_at'] = now()->toIso8601String();
            $current->update(['config' => $config]);
        });
    }
}

<?php

namespace App\Jobs\WhatsApp;

use App\Models\CoreIntegration;
use App\Services\WhatsApp\WhatsAppConnection;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class CheckWhatsAppConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;
    public int $timeout = 45;
    public int $tries = 1;

    public function __construct(public int $integrationId)
    {
        $this->onConnection('redis')->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'wa-check:'.$this->integrationId;
    }

    public function handle(WhatsAppConnection $connection): void
    {
        $integration = CoreIntegration::query()->find($this->integrationId);
        if (! $integration?->isActive()) {
            return;
        }
        $state = 'failed';
        try {
            $version = (string) config('whatsapp.graph_version', 'v23.0');
            if (! preg_match('/^v[0-9]+\.[0-9]+$/', $version)) {
                throw new \RuntimeException('invalid_graph_version');
            }
            $phoneId = (string) data_get($integration->config, 'phone_number_id');
            $token = (string) ($connection->secrets($integration)['access_token'] ?? '');
            $response = Http::acceptJson()->withToken($token)->connectTimeout(5)->timeout(20)
                ->withOptions(['allow_redirects' => false])
                ->get('https://graph.facebook.com/'.$version.'/'.$phoneId, ['fields' => 'id,display_phone_number']);
            if ($response->successful() && (string) $response->json('id') === $phoneId) {
                $number = preg_replace('/[^0-9]/', '', (string) $response->json('display_phone_number'));
                $state = $number === (string) data_get($integration->config, 'business_phone') ? 'verified' : 'phone_mismatch';
            }
        } catch (Throwable $exception) {
            $state = 'failed';
        }
        DB::transaction(function () use ($state): void {
            $current = CoreIntegration::query()->lockForUpdate()->find($this->integrationId);
            if (! $current) {
                return;
            }
            $config = $current->config ?? [];
            $config['connection_check'] = $state;
            $config['connection_checked_at'] = now()->toIso8601String();
            $current->update(['config' => $config]);
        });
    }
}

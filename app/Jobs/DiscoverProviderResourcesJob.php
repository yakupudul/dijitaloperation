<?php

namespace App\Jobs;

use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Integrations\Google\DiscoverGoogleResourcesService;
use App\Services\Integrations\Meta\DiscoverMetaResourcesService;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Faz 13: Google / Meta account discovery (several provider APIs) runs in the background instead of inside the page
 * request. The result is kept for an hour so the page can show it; `ProviderDiscovery::started()` marks it running.
 */
final class DiscoverProviderResourcesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $provider, public int $userId) {}

    public static function cacheKey(string $provider): string
    {
        return 'provider-discovery:'.$provider;
    }

    public function handle(): void
    {
        $integration = CoreIntegration::query()->where('provider', $this->provider)->first();
        $user = User::query()->find($this->userId);
        try {
            $result = match (true) {
                ! $integration instanceof CoreIntegration => ['ok' => false, 'message' => 'Entegrasyon bulunamadı.'],
                $this->provider === ProviderRegistry::GOOGLE => app(DiscoverGoogleResourcesService::class)->discover(
                    $integration->fresh(['authorizationCredential', 'providerCredential']) ?? $integration, $user),
                $this->provider === ProviderRegistry::META => app(DiscoverMetaResourcesService::class)->refreshInventory(
                    $integration->fresh(['providerCredential']) ?? $integration, $user),
                default => ['ok' => false, 'message' => 'Desteklenmeyen sağlayıcı.'],
            };
        } catch (Throwable $exception) {
            report($exception);
            $result = ['ok' => false, 'message' => $exception->getMessage()];
        }
        Cache::put(self::cacheKey($this->provider), ['state' => 'done', 'finished_at' => now()->toIso8601String(), 'result' => $result], now()->addHour());
    }
}

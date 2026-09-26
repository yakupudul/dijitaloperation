<?php

namespace App\Services\Integrations\Discovery;

use App\Jobs\DiscoverProviderResourcesJob;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Assistant\PushNotifier;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Daily account discovery (Google, Meta) so new ad accounts / properties and lost access are noticed without anyone
 * pressing "keşfet". What changed is pushed to the operator; the command center lists unbound and lost accounts.
 */
final class ScheduledDiscovery
{
    public function __construct(private readonly PushNotifier $push) {}

    /** @return array<string, array{new: int, lost: int, ok: bool}> */
    public function run(): array
    {
        $admin = User::query()->where('is_active', true)->role(Roles::ADMIN)->orderBy('id')->first();
        if ($admin === null) {
            return [];
        }
        $out = [];
        foreach ([ProviderRegistry::GOOGLE, ProviderRegistry::META] as $provider) {
            $integration = CoreIntegration::query()->where('provider', $provider)->where('status', CoreIntegration::STATUS_ACTIVE)->first();
            if ($integration === null) {
                continue;
            }
            $before = CoreExternalResource::query()->where('integration_id', $integration->id)->pluck('status', 'id')->all();
            try {
                (new DiscoverProviderResourcesJob($provider, (int) $admin->id))->handle();
                $result = Cache::get(DiscoverProviderResourcesJob::cacheKey($provider));
                $ok = (bool) data_get($result, 'result.ok', true);
            } catch (Throwable $error) {
                report($error);
                $ok = false;
            }
            $after = CoreExternalResource::query()->where('integration_id', $integration->id)->get(['id', 'status', 'display_name', 'external_id']);
            $new = $after->filter(fn ($r): bool => ! array_key_exists($r->id, $before));
            $lost = $after->filter(fn ($r): bool => ($before[$r->id] ?? null) === CoreExternalResource::STATUS_AVAILABLE && $r->status === CoreExternalResource::STATUS_UNAVAILABLE);
            $label = $provider === ProviderRegistry::GOOGLE ? 'Google' : 'Meta';
            if ($new->isNotEmpty()) {
                $this->push->send('discovery-new:'.$provider.':'.now()->format('Ymd'), $label.': '.$new->count().' yeni hesap bulundu',
                    $new->take(5)->map(fn ($r): string => (string) ($r->display_name ?: $r->external_id))->implode(', ').' — bir markaya bağlayın.', 'info', route('operator.command-center', ['source' => 'coverage']));
            }
            if ($lost->isNotEmpty()) {
                $this->push->send('discovery-lost:'.$provider.':'.now()->format('Ymd'), $label.': '.$lost->count().' hesaba erişim kaybedildi',
                    $lost->take(5)->map(fn ($r): string => (string) ($r->display_name ?: $r->external_id))->implode(', '), 'critical', route('operator.command-center', ['source' => 'coverage']));
            }
            $out[$provider] = ['new' => $new->count(), 'lost' => $lost->count(), 'ok' => $ok];
        }

        return $out;
    }
}

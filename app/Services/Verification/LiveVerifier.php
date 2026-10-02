<?php

namespace App\Services\Verification;

use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Services\Integrations\DataForSeo\DataForSeoAccountService;
use App\Services\Integrations\DataForSeo\DataForSeoCredentialResolver;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\Meta\MetaApiClient;
use App\Services\Integrations\Meta\MetaConnectionService;
use App\Services\Integrations\Meta\MetaCredentialResolver;
use App\Services\Integrations\Meta\MetaException;
use App\Services\Integrations\Meta\MetaOperatorMessages;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Meta\MetaAdAccountId;
use App\Support\Integrations\ProviderRegistry;
use App\Support\ServiceScope;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * moxdop:verify:live — proves every connection path works with the cheapest read-only call:
 * Google token refresh, then per bound account GA4 1-day runReport, Search Console sites.get, Google Ads
 * `SELECT customer.id FROM customer LIMIT 1`, Business Profile location get; Meta /me and ad account
 * `id,account_status`; DataForSEO free appendix/user_data; WordPress connector signed status.
 * Nothing is written to any provider. Each result is one live_checks row (no secrets in messages).
 */
final class LiveVerifier
{
    public const string OK = 'ok';

    public const string FAIL = 'fail';

    public const string SKIPPED = 'skipped';

    /** Meta ad account_status values that mean the account cannot deliver ads. */
    private const array META_ACCOUNT_STATUS = [1 => 'ACTIVE', 2 => 'DISABLED', 3 => 'UNSETTLED', 7 => 'PENDING_RISK_REVIEW', 8 => 'PENDING_SETTLEMENT', 9 => 'IN_GRACE_PERIOD', 100 => 'PENDING_CLOSURE', 101 => 'CLOSED'];

    private const array GOOGLE_CAPABILITIES = ['ga4', 'search_console', 'google_ads', 'google_business_profile'];

    public function __construct(
        private readonly GoogleOAuthService $googleOAuth,
        private readonly GoogleApiClient $google,
        private readonly MetaConnectionService $metaConnection,
        private readonly MetaCredentialResolver $metaCredentials,
        private readonly MetaApiClient $meta,
        private readonly DataForSeoAccountService $dataForSeo,
        private readonly DataForSeoCredentialResolver $dataForSeoCredentials,
        private readonly WordPressConnectorClient $wordpress,
    ) {}

    /** @return array{ok: int, fail: int, skipped: int} */
    public function run(): array
    {
        $stats = [self::OK => 0, self::FAIL => 0, self::SKIPPED => 0];
        $record = function (array $result) use (&$stats): void {
            $stats[$result['status']]++;
            DB::table('live_checks')->insert([
                ...$result,
                'message' => $result['message'] !== null ? Str::limit(preg_replace('/\s+/', ' ', (string) $result['message']) ?? '', 480) : null,
                'checked_at' => now(),
            ]);
        };

        $bindings = $this->boundResources();
        foreach (CoreIntegration::query()->where('status', CoreIntegration::STATUS_ACTIVE)->orderBy('id')->get() as $integration) {
            try {
                match ($integration->provider) {
                    ProviderRegistry::GOOGLE => $this->google($integration, $bindings, $record),
                    ProviderRegistry::META => $this->meta($integration, $bindings, $record),
                    ProviderRegistry::DATAFORSEO => $record($this->dataForSeo($integration)),
                    default => null,
                };
            } catch (Throwable $error) {
                report($error);
                $record($this->result($integration->provider, 'integration', 'integration', $integration->id, (string) $integration->name, self::FAIL, null, 'Doğrulama çalıştırılamadı: '.class_basename($error)));
            }
        }

        foreach ($this->wordpressConnections() as $connection) {
            $record($this->wordpress($connection));
        }

        DB::table('live_checks')->where('checked_at', '<', now()->subDays(max(1, (int) config('moxdop-verification.live.retention_days', 30))))->delete();

        return $stats;
    }

    /**
     * Latest result per check (for Sistem Sağlığı and Komuta merkezi).
     *
     * @return list<array{check_key: string, provider: string, capability: string, subject_type: string, subject_id: ?int, label: string, status: string, latency_ms: ?int, message: ?string, checked_at: string}>
     */
    public static function latest(): array
    {
        $ids = DB::table('live_checks')->selectRaw('max(id) as id')->groupBy('check_key');

        return DB::table('live_checks')->whereIn('id', $ids)->orderBy('provider')->orderBy('capability')->orderBy('label')->get()
            ->map(fn (object $row): array => [
                'check_key' => (string) $row->check_key, 'provider' => (string) $row->provider, 'capability' => (string) $row->capability,
                'subject_type' => (string) $row->subject_type, 'subject_id' => $row->subject_id !== null ? (int) $row->subject_id : null,
                'label' => (string) $row->label, 'status' => (string) $row->status, 'latency_ms' => $row->latency_ms !== null ? (int) $row->latency_ms : null,
                'message' => $row->message !== null ? (string) $row->message : null, 'checked_at' => (string) $row->checked_at,
            ])->values()->all();
    }

    /**
     * Available external resources used by at least one active binding on an operational asset (service scope),
     * grouped by integration. Token checks of the integrations themselves always run.
     *
     * @return Collection<int, Collection<int, array{resource: CoreExternalResource, capability: string}>>
     */
    private function boundResources(): Collection
    {
        return CoreAssetBinding::query()->with('externalResource')
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->whereIn('digital_asset_id', app(ServiceScope::class)->assetIdQuery())
            ->get()
            ->filter(fn (CoreAssetBinding $binding): bool => $binding->externalResource !== null)
            ->unique(fn (CoreAssetBinding $binding): string => $binding->external_resource_id.'|'.$binding->capability)
            ->map(fn (CoreAssetBinding $binding): array => ['resource' => $binding->externalResource, 'capability' => (string) $binding->capability])
            ->groupBy(fn (array $row): int => (int) $row['resource']->integration_id);
    }

    /** @param Collection<int, Collection<int, array{resource: CoreExternalResource, capability: string}>> $bindings */
    private function google(CoreIntegration $integration, Collection $bindings, callable $record): void
    {
        $status = GoogleAuthStatus::for($integration);
        $name = 'Google · '.($integration->name ?: 'bağlantı');
        if (in_array($status, [GoogleAuthStatus::NOT_CONFIGURED, GoogleAuthStatus::DISABLED], true)) {
            $record($this->result('google', 'token', 'integration', $integration->id, $name, self::SKIPPED, null, 'Bağlantı yapılandırılmamış.'));

            return;
        }

        $started = hrtime(true);
        $token = $status === GoogleAuthStatus::CONNECTED ? $this->googleOAuth->refreshAccessToken($integration, force: true) : null;
        $tokenOk = $token !== null && $token !== '';
        $record($this->result('google', 'token', 'integration', $integration->id, $name, $tokenOk ? self::OK : self::FAIL, $this->elapsed($started),
            $tokenOk ? 'Erişim anahtarı yenilendi.' : 'Erişim anahtarı yenilenemedi ('.GoogleAuthStatus::label(GoogleAuthStatus::for($integration->fresh() ?? $integration)).'). Google bağlantısını yeniden yetkilendirin.'));

        foreach ($bindings->get((int) $integration->id, collect()) as ['resource' => $resource, 'capability' => $capability]) {
            if (! in_array($capability, self::GOOGLE_CAPABILITIES, true)) {
                continue;
            }
            $label = ProviderRegistry::capabilityLabel($capability).' · '.($resource->display_name ?: $resource->external_id);
            if (! $tokenOk || $resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
                $record($this->result('google', $capability, 'external_resource', $resource->id, $label, self::SKIPPED, null,
                    $tokenOk ? 'Hesaba erişim kaybedilmiş (keşifte görünmüyor).' : 'Google anahtarı yenilenemediği için denenmedi.'));

                continue;
            }
            $record($this->googleResource($integration, $resource, $capability, $label));
        }
    }

    /** @return array<string, mixed> */
    private function googleResource(CoreIntegration $integration, CoreExternalResource $resource, string $capability, string $label): array
    {
        $started = hrtime(true);
        try {
            $externalId = trim((string) $resource->external_id);
            $response = match ($capability) {
                'ga4' => $this->google->post($integration, 'https://analyticsdata.googleapis.com/v1beta/properties/'.preg_replace('#^properties/#', '', $externalId).':runReport', [
                    'dateRanges' => [['startDate' => 'yesterday', 'endDate' => 'yesterday']],
                    'metrics' => [['name' => 'sessions']],
                    'limit' => 1,
                ], 'ga4'),
                'search_console' => $this->google->get($integration, 'https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($externalId), [], 'search_console'),
                'google_ads' => $this->googleAds($integration, $resource),
                default => $this->google->get($integration, 'https://mybusinessbusinessinformation.googleapis.com/v1/'.$this->locationName($externalId), ['readMask' => 'name,title'], 'google_business_profile'),
            };
        } catch (Throwable $error) {
            return $this->result('google', $capability, 'external_resource', $resource->id, $label, self::FAIL, $this->elapsed($started), $this->safeError($error));
        }

        $ok = $response->successful();

        return $this->result('google', $capability, 'external_resource', $resource->id, $label, $ok ? self::OK : self::FAIL, $this->elapsed($started),
            $ok ? $this->googleOkMessage($capability, $response) : $this->googleHttpError($response));
    }

    private function googleAds(CoreIntegration $integration, CoreExternalResource $resource): Response
    {
        $customerId = preg_replace('/\D+/', '', (string) $resource->external_id) ?? '';
        $metadata = is_array($resource->metadata) ? $resource->metadata : [];
        $login = preg_replace('/\D+/', '', (string) ($metadata['login_customer_id'] ?? $metadata['manager_customer_id'] ?? $customerId)) ?: $customerId;

        return $this->google->searchAds($integration, $customerId, 'SELECT customer.id FROM customer LIMIT 1', $login);
    }

    private function locationName(string $externalId): string
    {
        if (preg_match('#(?:^|/)locations/([^/]+)$#', $externalId, $match) !== 1) {
            throw new \RuntimeException('İşletme Profili konum kimliği geçersiz.');
        }

        return 'locations/'.$match[1];
    }

    private function googleOkMessage(string $capability, Response $response): string
    {
        return match ($capability) {
            'ga4' => 'Dünkü oturum: '.number_format((float) ($response->json('rows.0.metricValues.0.value') ?? 0), 0, ',', '.'),
            'search_console' => 'Yetki: '.(string) ($response->json('permissionLevel') ?? '—'),
            'google_ads' => 'Hesap okunabiliyor.',
            default => 'Konum okunabiliyor: '.Str::limit((string) ($response->json('title') ?? ''), 80),
        };
    }

    private function googleHttpError(Response $response): string
    {
        $status = (string) ($response->json('error.status') ?? $response->json('0.error.status') ?? '');
        $message = (string) ($response->json('error.message') ?? $response->json('0.error.message') ?? '');

        return 'HTTP '.$response->status().($status !== '' ? ' '.$status : '').($message !== '' ? ': '.Str::limit($message, 200) : '');
    }

    /** @param Collection<int, Collection<int, array{resource: CoreExternalResource, capability: string}>> $bindings */
    private function meta(CoreIntegration $integration, Collection $bindings, callable $record): void
    {
        $name = 'Meta · '.($integration->name ?: 'bağlantı');
        if (! $this->metaCredentials->isConfigured($integration)) {
            $record($this->result('meta', 'token', 'integration', $integration->id, $name, self::SKIPPED, null, 'Bağlantı yapılandırılmamış.'));

            return;
        }
        $started = hrtime(true);
        $test = $this->metaConnection->testConnection($integration);
        $record($this->result('meta', 'token', 'integration', $integration->id, $name, $test['ok'] ? self::OK : self::FAIL, $this->elapsed($started), $test['ok'] ? 'Erişim anahtarı geçerli.' : $test['message']));

        foreach ($bindings->get((int) $integration->id, collect()) as ['resource' => $resource, 'capability' => $capability]) {
            if ($capability !== 'meta_ads') {
                continue;
            }
            $label = 'Meta Ads · '.($resource->display_name ?: $resource->external_id);
            if (! $test['ok'] || $resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
                $record($this->result('meta', 'meta_ads', 'external_resource', $resource->id, $label, self::SKIPPED, null,
                    $test['ok'] ? 'Hesaba erişim kaybedilmiş (keşifte görünmüyor).' : 'Meta anahtarı geçersiz olduğu için denenmedi.'));

                continue;
            }
            $started = hrtime(true);
            try {
                $account = $this->meta->get($integration, MetaAdAccountId::toApiForm((string) $resource->external_id), ['fields' => 'id,account_status']);
                $code = (int) ($account['account_status'] ?? 0);
                $active = $code === 1;
                $record($this->result('meta', 'meta_ads', 'external_resource', $resource->id, $label, $active ? self::OK : self::FAIL, $this->elapsed($started),
                    $active ? 'Hesap aktif.' : 'Hesap okunuyor ama reklam yayınlayamaz: '.(self::META_ACCOUNT_STATUS[$code] ?? 'durum '.$code).'.'));
            } catch (MetaException $error) {
                $record($this->result('meta', 'meta_ads', 'external_resource', $resource->id, $label, self::FAIL, $this->elapsed($started), MetaOperatorMessages::forException($error)));
            } catch (Throwable $error) {
                $record($this->result('meta', 'meta_ads', 'external_resource', $resource->id, $label, self::FAIL, $this->elapsed($started), $this->safeError($error)));
            }
        }
    }

    /** @return array<string, mixed> */
    private function dataForSeo(CoreIntegration $integration): array
    {
        $name = 'DataForSEO · '.($integration->name ?: 'hesap');
        if (! $this->dataForSeoCredentials->isConfigured($integration)) {
            return $this->result('dataforseo', 'seo_data', 'integration', $integration->id, $name, self::SKIPPED, null, 'Bağlantı yapılandırılmamış.');
        }
        $started = hrtime(true);
        // Free /v3/appendix/user_data only.
        $test = $this->dataForSeo->testConnection($integration);

        return $this->result('dataforseo', 'seo_data', 'integration', $integration->id, $name, $test['ok'] ? self::OK : self::FAIL, $this->elapsed($started), $test['message']);
    }

    /** @return Collection<int, CoreConnection> */
    private function wordpressConnections(): Collection
    {
        return CoreConnection::query()->with(['credential', 'digitalAsset'])->where('type', 'wordpress_connector')->where('enabled', true)
            ->whereIn('digital_asset_id', app(ServiceScope::class)->assetIdQuery())->orderBy('id')->get()
            ->filter(fn (CoreConnection $connection): bool => data_get($connection->config, 'pairing_state') === 'paired'
                && $connection->digitalAsset !== null && (string) $connection->digitalAsset->status?->value === 'active');
    }

    /** @return array<string, mixed> */
    private function wordpress(CoreConnection $connection): array
    {
        $label = 'WordPress · '.($connection->digitalAsset?->domain ?: $connection->digitalAsset?->name ?: '#'.$connection->id);
        $started = hrtime(true);
        try {
            $data = $this->wordpress->status($connection);

            return $this->result('wordpress', 'connector', 'connection', $connection->id, $label, self::OK, $this->elapsed($started),
                'İmzalı durum yanıtı alındı'.(is_string($data['plugin_version'] ?? null) ? ' (eklenti '.$data['plugin_version'].')' : '').'.');
        } catch (Throwable $error) {
            return $this->result('wordpress', 'connector', 'connection', $connection->id, $label, self::FAIL, $this->elapsed($started), $this->safeError($error));
        }
    }

    /** @return array<string, mixed> */
    private function result(string $provider, string $capability, string $subjectType, ?int $subjectId, string $label, string $status, ?int $latency, ?string $message): array
    {
        return [
            'check_key' => $provider.':'.$capability.':'.$subjectType.':'.($subjectId ?? 0),
            'provider' => $provider,
            'capability' => $capability,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'label' => Str::limit($label, 250),
            'status' => $status,
            'latency_ms' => $latency,
            'message' => $message,
        ];
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    /** Exception text without tokens: known client messages are safe; anything that looks like a secret is cut. */
    private function safeError(Throwable $error): string
    {
        $message = preg_replace('/(access_token|token|secret|key|password)=[^&\s]+/i', '$1=***', $error->getMessage()) ?? '';

        return $message !== '' ? Str::limit($message, 300) : class_basename($error);
    }
}

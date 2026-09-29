<?php

namespace App\Services\Gbp;

use App\Ai\Agents\GbpPostAgent;
use App\Jobs\DraftGbpPostJob;
use App\Models\AiProduction;
use App\Models\DigitalAsset;
use App\Services\Advisor\Gbp\GbpAdvisorInputCollector;
use App\Services\Ai\AiCostEstimator;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Archive\ProductionArchive;
use App\Services\Compliance\SectorPackRegistry;
use App\Support\Ai\AiRouteKeys;
use App\Support\ServiceScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * On click, an AI draft for the next Business Profile post from the brand's services, service areas, the searches
 * that find the profile and the recent posts. The draft is archived (kind `gbp.post`) and loaded into the post form;
 * nothing is published without Admin approval (ADR-073).
 */
final class GbpPostDrafter
{
    public const string KIND = 'gbp.post';

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly ProductionArchive $archive,
        private readonly GbpAdvisorInputCollector $collector,
    ) {}

    public function queue(DigitalAsset $asset, string $topic = ''): void
    {
        if ($this->routes->resolve(AiRouteKeys::GBP_POST_DRAFT)->isEmpty()) {
            throw ValidationException::withMessages(['post' => 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).']);
        }
        Cache::put($this->stateKey((int) $asset->id), 'running', now()->addMinutes(10));
        DraftGbpPostJob::dispatch((int) $asset->id, mb_substr(trim($topic), 0, 120));
    }

    public function write(int $assetId, string $topic = ''): void
    {
        $asset = DigitalAsset::query()->with('brand')->find($assetId);
        try {
            if ($asset === null || ! app(ServiceScope::class)->isAssetOperational($asset->id)) {
                throw ServiceScope::notServed();
            }
            $route = $this->routes->resolve(AiRouteKeys::GBP_POST_DRAFT);
            if ($route->isEmpty()) {
                throw new \RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.');
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $response = (array) (new GbpPostAgent)->prompt('CONTEXT_JSON'."\n".json_encode($this->context($asset, $topic), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels, timeout: 90)->toArray();
            $draft = self::clean($response);
            if ($draft['body'] === '') {
                throw new \RuntimeException('AI geçerli bir gönderi metni döndürmedi; tekrar deneyin.');
            }
            $this->archive->record(self::KIND, $asset, $draft + ['provider' => $route->primaryProvider(), 'model' => $route->primaryModel(), 'prompt_version' => GbpPostAgent::PROMPT_VERSION],
                ['brand_id' => $asset->brand_id, 'digital_asset_id' => $asset->id, 'title' => 'Profil gönderisi · '.$draft['title']]);
            Cache::forget($this->stateKey($assetId));
        } catch (Throwable $exception) {
            Cache::put($this->stateKey($assetId), 'failed: '.mb_substr($exception->getMessage(), 0, 200), now()->addHour());
        }
    }

    /**
     * Google rejects phone numbers and links in the text; over-long parts are cut.
     *
     * @param  array<string, mixed>  $raw
     * @return array{title: string, body: string, action_type: string, service: string}
     */
    public static function clean(array $raw): array
    {
        $text = static fn (mixed $value, int $max): string => mb_substr(trim(strip_tags(is_string($value) ? $value : '')), 0, $max);
        $body = $text($raw['body'] ?? '', 1250);
        if (preg_match('~https?://|www\.|\d{3}[\s-]?\d{3}[\s-]?\d{2,4}~iu', $body) === 1) {
            $body = '';
        }

        return [
            'title' => $text($raw['title'] ?? '', 120),
            'body' => $body,
            'action_type' => in_array($raw['action_type'] ?? null, ['LEARN_MORE', 'BOOK', 'CALL', 'ORDER', 'SIGN_UP'], true) ? (string) $raw['action_type'] : 'LEARN_MORE',
            'service' => $text($raw['service'] ?? '', 120),
        ];
    }

    /** Latest draft of the last day, not yet used / discarded. */
    public function latest(DigitalAsset $asset): ?AiProduction
    {
        $draft = $this->archive->fresh(self::KIND, $asset, 1);

        return $draft !== null && $draft->status === AiProduction::STATUS_NEW ? $draft : null;
    }

    public function state(int $assetId): ?string
    {
        $state = Cache::get($this->stateKey($assetId));

        return is_string($state) ? $state : null;
    }

    public function estimate(): ?string
    {
        return app(AiCostEstimator::class)->label(AiRouteKeys::GBP_POST_DRAFT, 3000, 500);
    }

    /** @return array<string, mixed> */
    private function context(DigitalAsset $asset, string $topic): array
    {
        $input = $this->collector->collect($asset);
        $resourceId = app(GbpDailyWorkspace::class)->resource($asset)?->id;
        $recent = $resourceId !== null ? DB::table('gbp_posts')->where('external_resource_id', $resourceId)->orderByDesc('create_time')->limit(5)->pluck('summary')->all() : [];
        $calendar = [];

        return [
            'business' => $input['location']['title'] ?? $asset->brand?->name,
            'primary_category' => $input['location']['primary_category'] ?? null,
            'additional_categories' => $input['location']['additional_categories'] ?? [],
            'services' => array_values(array_filter(array_map(static fn (array $o): string => (string) ($o['name'] ?? ''), $input['offerings'] ?? []))),
            'service_areas' => $input['service_areas'] ?? [],
            'top_searches' => array_slice(array_column($input['keywords']['items'] ?? [], 'keyword'), 0, 15),
            'recent_posts' => array_values(array_map(static fn ($s): string => mb_substr((string) $s, 0, 200), array_filter(array_merge($recent, $calendar)))),
            'compliance' => $asset->brand !== null ? app(SectorPackRegistry::class)->rulesForBrand($asset->brand)->pluck('message')->unique()->values()->take(8)->all() : [],
            'topic' => $topic !== '' ? $topic : null,
        ];
    }

    private function stateKey(int $assetId): string
    {
        return 'gbp-post-draft:'.$assetId;
    }
}

<?php

namespace App\Services\Meta;

use App\Ai\Agents\MetaStrategyPlanAgent;
use App\Jobs\Meta\DraftMetaStrategyPlanJob;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\User;
use App\Services\Ads\AdServiceStats;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Compliance\SectorPackRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Strateji öner (/meta/strateji): for one service, city and result type, the Meta campaigns of other brands that pass
 * the winner threshold (30 days, AdServiceStats → ad_campaign_stats with each campaign's recipe), cheapest first; a
 * rule-built "kazanan tarifi" from the best five; the chosen brand's own campaigns beside them; library saves; and on
 * request a plan draft written by Claude (texts only, numbers from the rules) that lands in the brand's Meta
 * Yapılacaklar. Result types are never mixed; nothing is written to Meta.
 */
class MetaStrategy
{
    /** A winner: at least this much spend (TRY) and this many results of its type in 30 days. */
    public const float MIN_SPEND = 2000.0;

    public const int MIN_RESULTS = 10;

    /** The recipe is built from this many cheapest winners. */
    public const int TOP = 5;

    /** The city list is used when it has at least this many winners; else all cities. */
    public const int MIN_CITY_WINNERS = 3;

    public const int LIST_LIMIT = 20;

    /** @var array<string, string> */
    public const array TYPES = ['leads' => 'Form', 'messages' => 'Mesaj', 'purchases' => 'Satış'];

    /** @var array<string, string> */
    public const array DESTINATIONS = ['form' => 'Anında form', 'mesaj' => 'Mesaj (WhatsApp / Messenger / Instagram)', 'site' => 'Web sitesi'];

    /** @var array<string, string> */
    public const array OPTIMIZATIONS = ['LEAD_GENERATION' => 'Form', 'QUALITY_LEAD' => 'Nitelikli form', 'CONVERSATIONS' => 'Sohbet', 'OFFSITE_CONVERSIONS' => 'Web dönüşümü',
        'LINK_CLICKS' => 'Bağlantı tıklaması', 'LANDING_PAGE_VIEWS' => 'Açılış sayfası görüntüleme', 'REACH' => 'Erişim', 'IMPRESSIONS' => 'Gösterim', 'VALUE' => 'Değer'];

    public function __construct(
        private readonly MetaCampaignServices $services,
        private readonly AiTaskQueue $tasks,
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
    ) {}

    public static function planStateKey(int $brandId, int $serviceId, string $type): string
    {
        return 'meta-strategy-plan:'.$brandId.':'.$serviceId.':'.$type;
    }

    /**
     * @param  array{brand?: string, service?: string, city?: string, type?: string}  $filters
     * @return array<string, mixed>
     */
    public function strategy(array $filters): array
    {
        if (! Schema::hasTable('ad_campaign_stats') || ! Schema::hasColumn('ad_campaign_stats', 'profile')) {
            return ['ready' => false];
        }
        $type = array_key_exists($filters['type'] ?? '', self::TYPES) ? $filters['type'] : 'leads';
        $rows = DB::table('ad_campaign_stats')->where('channel', 'meta')->get();
        $brands = Brand::query()->whereIn('id', $rows->pluck('brand_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $target = ($filters['brand'] ?? '') !== '' ? Brand::query()->find((int) $filters['brand']) : null;
        $offerings = $target !== null ? $this->services->offerings($target) : [];
        $cities = $brands->map(fn (Brand $b): string => AdServiceStats::city($b))->all();

        $serviceIds = [];
        foreach ($rows as $r) {
            foreach ((array) json_decode((string) $r->services, true) as $s) {
                if (isset($s['service_id'])) {
                    $serviceIds[] = (int) $s['service_id'];
                }
            }
        }
        $serviceIds = array_merge($serviceIds, array_map('intval', array_filter(array_column($offerings, 'service_id'))));
        $serviceNames = MetaDesk::serviceNames($serviceIds);
        asort($serviceNames);
        $serviceId = ($filters['service'] ?? '') !== '' ? (int) $filters['service'] : null;
        if ($serviceId === null && $offerings !== []) {
            $main = array_values(array_filter($offerings, fn (array $o): bool => $o['main'] && $o['service_id'] !== null));
            $serviceId = $main[0]['service_id'] ?? (array_values(array_filter(array_column($offerings, 'service_id')))[0] ?? null);
        }
        $city = $filters['city'] ?? null;
        if ($city === null) {
            $city = $target !== null ? AdServiceStats::city($target) : '';
        }

        $options = [
            'brands' => Brand::query()->whereIn('id', DigitalAsset::query()->where('type', 'meta_ads')->whereNotNull('brand_id')->pluck('brand_id'))->orderBy('name')->pluck('name', 'id')->all(),
            'services' => $serviceNames,
            'cities' => collect($cities)->filter()->unique(fn (string $c): string => mb_strtolower($c))->sort()->values()->all(),
            'types' => self::TYPES,
        ];
        $base = ['ready' => true, 'type' => $type, 'service_id' => $serviceId, 'city' => (string) $city, 'brand' => $target, 'options' => $options,
            'service_name' => $serviceId !== null ? ($serviceNames[$serviceId] ?? 'Hizmet #'.$serviceId) : null,
            'asset_id' => $target !== null ? DigitalAsset::query()->where('brand_id', $target->id)->where('type', 'meta_ads')->orderBy('id')->value('id') : null,
            'period_end' => $rows->max('period_end')];
        if ($serviceId === null) {
            return $base + ['winners' => [], 'recipe' => null, 'own' => [], 'scope' => '', 'other_currency' => 0, 'pool' => 0, 'plan' => null];
        }

        $winners = [];
        $own = [];
        $otherCurrency = 0;
        foreach ($rows as $r) {
            if ($r->result_type !== $type || ! in_array($serviceId, array_map('intval', array_column((array) json_decode((string) $r->services, true), 'service_id')), true)) {
                continue;
            }
            $row = $this->row($r, $brands[$r->brand_id] ?? null, $cities[$r->brand_id] ?? '');
            if ($target !== null && (int) $r->brand_id === (int) $target->id) {
                $own[] = $row;

                continue;
            }
            if (! in_array(strtoupper((string) $r->currency), ['TRY', ''], true)) {
                $otherCurrency++;

                continue;
            }
            if ($row['spend'] >= self::MIN_SPEND && $row['results'] >= self::MIN_RESULTS && $row['cpr'] !== null) {
                $winners[] = $row;
            }
        }
        $local = $city !== '' ? array_values(array_filter($winners, fn (array $w): bool => mb_strtolower($w['city']) === mb_strtolower((string) $city))) : [];
        [$pool, $scope] = $city !== '' && count($local) >= self::MIN_CITY_WINNERS ? [$local, (string) $city] : [$winners, 'tüm şehirler'];
        usort($pool, fn (array $a, array $b): int => [$a['cpr'], -$a['results']] <=> [$b['cpr'], -$b['results']]);
        usort($own, fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);
        $recipe = $this->recipe(array_slice($pool, 0, self::TOP));
        foreach ($own as $i => $o) {
            $own[$i]['diff'] = $recipe !== null ? MetaScreen::change($o['cpr'], $recipe['cpr']) : null;
        }
        $saved = DB::table('ad_library_items')->whereIn('campaign_id', array_column($pool, 'campaign_id') ?: [''])->get(['kind', 'digital_asset_id', 'campaign_id'])
            ->map(fn ($s): string => $s->kind.'|'.$s->digital_asset_id.'|'.$s->campaign_id)->all();
        foreach ($pool as $i => $w) {
            $pool[$i]['top'] = $i < self::TOP;
            $pool[$i]['saved'] = ['text' => in_array('text|'.$w['asset_id'].'|'.$w['campaign_id'], $saved, true), 'targeting' => in_array('targeting|'.$w['asset_id'].'|'.$w['campaign_id'], $saved, true)];
        }

        return $base + [
            'winners' => array_slice($pool, 0, self::LIST_LIMIT), 'pool' => count($pool), 'recipe' => $recipe, 'own' => $own, 'scope' => $scope,
            'other_currency' => $otherCurrency,
            'plan' => $target !== null ? Cache::get(self::planStateKey((int) $target->id, $serviceId, $type)) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function row(object $r, ?Brand $brand, string $city): array
    {
        $results = (float) $r->results;

        return ['id' => (int) $r->id, 'brand_id' => (int) $r->brand_id, 'brand' => (string) ($brand?->name ?? ''), 'city' => $city, 'asset_id' => (int) $r->digital_asset_id,
            'campaign_id' => (string) $r->campaign_id, 'name' => (string) $r->name, 'status' => (string) $r->status, 'spend' => (float) $r->spend, 'results' => $results,
            'cpr' => $r->cpr !== null ? (float) $r->cpr : null, 'currency' => (string) $r->currency, 'period_end' => (string) $r->period_end,
            'profile' => (array) json_decode((string) ($r->profile ?? ''), true)];
    }

    /**
     * Kazanan tarifi: what most of the cheapest winners share, with counts. Rules only.
     *
     * @param  list<array<string, mixed>>  $top
     * @return array<string, mixed>|null
     */
    public function recipe(array $top): ?array
    {
        if ($top === []) {
            return null;
        }
        $n = count($top);
        $profiles = array_column($top, 'profile');
        $targeting = array_values(array_filter(array_column($profiles, 'targeting')));
        $bestAds = array_values(array_filter(array_column($profiles, 'best_ad')));
        $interests = array_count_values(array_merge(...array_map(fn (array $t): array => array_values(array_unique((array) ($t['interests'] ?? []))), $targeting ?: [[]])));
        arsort($interests);
        $budgets = array_values(array_filter(array_column($profiles, 'budget'), fn ($b): bool => is_numeric($b) && $b > 0));
        $bodies = array_values(array_filter(array_map(fn (array $a): int => mb_strlen((string) ($a['body'] ?? '')), $bestAds)));
        $optimization = self::mode(array_map(fn (array $p): string => (string) (($p['optimization'] ?? [])[0] ?? ''), $profiles));
        $destination = self::mode(array_column($profiles, 'destination'));
        $age = self::mode(array_column($targeting, 'age'));
        $gender = self::mode(array_column($targeting, 'genders'));
        $placements = self::mode(array_column($targeting, 'placements'));
        $objective = self::mode(array_column($profiles, 'objective'));
        $advantage = count(array_filter($targeting, fn (array $t): bool => (bool) ($t['advantage'] ?? false)));
        $video = count(array_filter($bestAds, fn (array $a): bool => (bool) ($a['video'] ?? false)));
        $common = array_slice(array_keys(array_filter($interests, fn (int $c): bool => $c >= 2)), 0, 8);

        $lines = [];
        $of = fn (int $count): string => ' ('.$count.'/'.$n.')';
        if ($objective !== null) {
            $lines[] = ['label' => 'Hedef', 'value' => $objective[0].$of($objective[1])];
        }
        if ($optimization !== null) {
            $lines[] = ['label' => 'Optimizasyon', 'value' => (self::OPTIMIZATIONS[$optimization[0]] ?? $optimization[0]).$of($optimization[1])];
        }
        if ($destination !== null) {
            $lines[] = ['label' => 'Sonuç yeri', 'value' => (self::DESTINATIONS[$destination[0]] ?? $destination[0]).$of($destination[1])];
        }
        if ($budgets !== []) {
            $lines[] = ['label' => 'Günlük bütçe', 'value' => 'ortanca '.number_format(AdServiceStats::median(array_map('floatval', $budgets)), 0, ',', '.').' TRY'];
        }
        if ($age !== null) {
            $lines[] = ['label' => 'Yaş', 'value' => $age[0].$of($age[1])];
        }
        if ($gender !== null) {
            $lines[] = ['label' => 'Cinsiyet', 'value' => $gender[0].$of($gender[1])];
        }
        if ($targeting !== []) {
            $lines[] = ['label' => 'Kitle', 'value' => $advantage * 2 >= count($targeting) ? 'Advantage+ kitle'.$of($advantage) : 'Elle hedefleme'.$of(count($targeting) - $advantage)];
        }
        if ($common !== []) {
            $lines[] = ['label' => 'Ortak ilgi alanları', 'value' => implode(', ', $common)];
        }
        if ($placements !== null) {
            $lines[] = ['label' => 'Yerleşim', 'value' => $placements[0].$of($placements[1])];
        }
        if ($bestAds !== []) {
            $lines[] = ['label' => 'En iyi reklam', 'value' => ($video * 2 >= count($bestAds) ? 'video' : 'görsel').$of($video * 2 >= count($bestAds) ? $video : count($bestAds) - $video)
                .($bodies !== [] ? ' · metin ortanca '.(int) AdServiceStats::median(array_map('floatval', $bodies)).' karakter' : '')];
        }

        return ['count' => $n, 'cpr' => AdServiceStats::median(array_column($top, 'cpr')), 'budget' => $budgets !== [] ? AdServiceStats::median(array_map('floatval', $budgets)) : null,
            'lines' => $lines, 'interests' => $common, 'brands' => count(array_unique(array_column($top, 'brand_id')))];
    }

    /**
     * @param  list<string>  $values
     * @return array{0: string, 1: int}|null the most common non-empty value and its count
     */
    private static function mode(array $values): ?array
    {
        $counts = array_count_values(array_filter(array_map('strval', $values), fn (string $v): bool => $v !== ''));
        if ($counts === []) {
            return null;
        }
        arsort($counts);

        return [(string) array_key_first($counts), (int) reset($counts)];
    }

    /* ---------------- plan draft ---------------- */

    /** Queues the plan draft for the brand's Meta account. @return array{status: string, message: string} */
    public function requestPlan(Brand $brand, int $serviceId, string $type, string $city): array
    {
        $assetId = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'meta_ads')->orderBy('id')->value('id');
        if ($assetId === null) {
            return ['status' => 'failed', 'message' => 'Markanın bağlı Meta reklam hesabı yok; plan Yapılacaklar’a yazılamaz.'];
        }
        $state = ['status' => 'running', 'message' => 'Plan taslağı hazırlanıyor; hazır olunca markanın Meta › Yapılacaklar listesine düşer.'];
        Cache::put(self::planStateKey((int) $brand->id, $serviceId, $type), $state, now()->addDay());
        DraftMetaStrategyPlanJob::dispatch((int) $brand->id, (int) $assetId, $serviceId, array_key_exists($type, self::TYPES) ? $type : 'leads', mb_substr($city, 0, 80));

        return $state;
    }

    /**
     * Builds the plan (Claude queue or provider) and stores it as a Yapılacaklar suggestion. Null while Claude has not
     * answered. Throws when there is nothing to plan from.
     */
    public function draftPlan(DigitalAsset $asset, int $serviceId, string $type, string $city): ?int
    {
        $brand = $asset->brand;
        if ($brand === null) {
            throw new RuntimeException('Hesap bir markaya bağlı değil.');
        }
        $view = $this->strategy(['brand' => (string) $brand->id, 'service' => (string) $serviceId, 'city' => $city, 'type' => $type]);
        if (($view['recipe'] ?? null) === null) {
            throw new RuntimeException('Bu hizmet ve sonuç türünde kazanan kampanya yok; plan için örnek bulunamadı.');
        }
        $offering = collect($this->services->offerings($brand))->firstWhere('service_id', $serviceId);
        $data = [
            'brand' => $brand->name, 'city' => $city !== '' ? $city : AdServiceStats::city($brand), 'service' => $offering['name'] ?? $view['service_name'],
            'result_type' => mb_strtolower(self::TYPES[$type]),
            'recipe' => array_map(fn (array $l): string => $l['label'].': '.$l['value'], $view['recipe']['lines']),
            'winners' => array_map(fn (array $w): array => ['spend' => $w['spend'], 'results' => $w['results'], 'cost' => $w['cpr'],
                'settings' => array_intersect_key($w['profile'], array_flip(['objective', 'optimization', 'destination', 'adsets', 'ads'])),
                'targeting' => $w['profile']['targeting'] ?? null,
                'best_ad' => isset($w['profile']['best_ad']) ? array_intersect_key($w['profile']['best_ad'], array_flip(['title', 'body', 'video', 'form'])) : null],
                array_slice($view['winners'], 0, self::TOP)),
            'own_campaigns' => array_map(fn (array $o): array => ['campaign' => $o['name'], 'status' => $o['status'], 'spend' => $o['spend'], 'results' => $o['results'], 'cost' => $o['cpr'],
                'settings' => array_intersect_key($o['profile'], array_flip(['objective', 'optimization', 'destination'])), 'targeting' => $o['profile']['targeting'] ?? null], array_slice($view['own'], 0, 5)),
            'pages' => $offering !== null ? OfferingPage::query()->join('pages', 'pages.id', '=', 'offering_pages.page_id')->where('offering_pages.brand_offering_id', $offering['id'])
                ->limit(5)->get(['pages.title', 'pages.url'])->map(fn ($p): array => ['title' => (string) $p->title, 'url' => (string) $p->url])->all() : [],
            'compliance' => app(SectorPackRegistry::class)->rulesForBrand($brand)->pluck('message')->unique()->values()->take(12)->all(),
        ];
        $answer = $this->ask($data);
        if ($answer === null) {
            return null;
        }

        return app(MetaSuggestions::class)->replaceGroup($asset, 'plan:'.$serviceId.'-'.$type, [$this->suggestion($answer, $view, $data)]);
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>  $view
     * @param  array<string, mixed>  $data
     * @return array{key: string, title: string, reason: string, priority: int, evidence: array<mixed>, action_type: string, action: array<string, mixed>}
     */
    private function suggestion(array $answer, array $view, array $data): array
    {
        $recipe = $view['recipe'];
        $label = mb_strtolower(self::TYPES[$view['type']]);
        $money = fn (float $v): string => number_format($v, 0, ',', '.').' TRY';
        $text = [trim((string) ($answer['campaign_name'] ?? '')), trim((string) ($answer['summary'] ?? '')), '', 'Yapı:', trim((string) ($answer['structure'] ?? ''))];
        if ($recipe['budget'] !== null) {
            $text[] = 'Günlük bütçe: '.$money($recipe['budget']).' (kazananların ortancası)';
        }
        $text[] = 'Beklenen '.$label.' başı maliyet: yaklaşık '.$money($recipe['cpr']).' ('.$recipe['count'].' kazananın ortancası, '.$view['scope'].')';
        $text[] = '';
        $text[] = 'Reklam setleri:';
        foreach ((array) ($answer['adsets'] ?? []) as $set) {
            $text[] = '• '.trim((string) ($set['name'] ?? '')).': '.trim((string) ($set['audience'] ?? ''));
        }
        $text[] = '';
        $text[] = 'Reklamlar:';
        foreach (array_values((array) ($answer['ads'] ?? [])) as $i => $ad) {
            $text[] = ($i + 1).') '.trim((string) ($ad['angle'] ?? '')).' · '.trim((string) ($ad['headline'] ?? ''));
            $text[] = trim((string) ($ad['primary_text'] ?? ''));
        }
        if (($answer['watch'] ?? []) !== []) {
            $text[] = '';
            $text[] = 'İlk 7 gün:';
            foreach ((array) $answer['watch'] as $line) {
                $text[] = '• '.trim((string) $line);
            }
        }
        $ads = array_values((array) ($answer['ads'] ?? []));

        return [
            'key' => 'plan', 'title' => mb_substr('Strateji planı · '.$data['service'].' · '.$label, 0, 160),
            'reason' => mb_substr(trim((string) ($answer['summary'] ?? '')), 0, 240), 'priority' => 2,
            'evidence' => array_map(fn (array $l): array => [$l['label'] => $l['value']], $recipe['lines']),
            'action_type' => 'meta_plan',
            'action' => ['text' => mb_substr(implode("\n", $text), 0, 3000), 'service' => $data['service'],
                'headline' => (string) ($ads[0]['headline'] ?? ''), 'primary_text' => (string) ($ads[0]['primary_text'] ?? '')],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null null while Claude has not answered
     */
    private function ask(array $data): ?array
    {
        $agent = new MetaStrategyPlanAgent;
        $answer = $this->tasks->delegatedCall($agent, $data);
        if ($answer === 'queued') {
            return null;
        }
        if ($answer === 'error') {
            throw new RuntimeException('Claude plan taslağını yazamadı.');
        }
        if (is_array($answer)) {
            return $answer;
        }
        $route = $this->routes->resolve(MetaStrategyPlanAgent::OPERATION);
        if ($route->isEmpty()) {
            throw new RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.');
        }
        $this->runtime->prepare(array_keys($route->providerModels));

        return (array) $agent->prompt("DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            provider: $route->providerModels, timeout: 180)->toArray();
    }

    /* ---------------- library ---------------- */

    /**
     * Saves a winner's best ad text or targeting to Kütüphaneler. False when it is already there.
     */
    public function save(int $statId, string $kind, ?User $user): bool
    {
        $r = DB::table('ad_campaign_stats')->where('channel', 'meta')->find($statId);
        if ($r === null || ! in_array($kind, ['text', 'targeting'], true)) {
            return false;
        }
        $profile = (array) json_decode((string) ($r->profile ?? ''), true);
        $payload = $kind === 'text'
            ? ($profile['best_ad'] ?? null)
            : (isset($profile['targeting']) ? ['targeting' => $profile['targeting'], 'optimization' => $profile['optimization'] ?? [], 'destination' => $profile['destination'] ?? '',
                'objective' => $profile['objective'] ?? '', 'budget' => $profile['budget'] ?? null] : null);
        if ($payload === null) {
            return false;
        }
        $services = (array) json_decode((string) $r->services, true);
        $fingerprint = hash('sha256', $kind.'|'.$r->digital_asset_id.'|'.$r->campaign_id.'|'.json_encode($payload));
        if (DB::table('ad_library_items')->where('fingerprint', $fingerprint)->exists()) {
            return false;
        }
        $title = $kind === 'text' ? ((string) ($payload['title'] ?? '') ?: mb_substr((string) ($payload['body'] ?? ''), 0, 80)) : $r->name;
        DB::table('ad_library_items')->insert([
            'kind' => $kind, 'channel' => 'meta', 'service_id' => $services[0]['service_id'] ?? null, 'result_type' => $r->result_type, 'brand_id' => $r->brand_id,
            'digital_asset_id' => $r->digital_asset_id, 'campaign_id' => $r->campaign_id, 'campaign_name' => $r->name, 'title' => mb_substr($title, 0, 300),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'spend' => $r->spend, 'results' => $r->results, 'cpr' => $r->cpr, 'currency' => $r->currency,
            'period_end' => $r->period_end, 'fingerprint' => $fingerprint, 'saved_by' => $user?->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return true;
    }
}

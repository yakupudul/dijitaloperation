<?php

namespace App\Services\Brand;

use App\Ai\Agents\BrandDataReviewAgent;
use App\Jobs\Brand\RunBrandDataReviewJob;
use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Models\BrandMemory;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Measurement\BrandConversionDictionary;
use App\Services\Repair\RepairDesk;
use App\Support\Options\LocationOptions;
use RuntimeException;
use Throwable;

/**
 * Claude marka denetimi (yakup, 2026-10-11): every night after the rules' Marka verisi denetimi, an active brand whose
 * services, places, conversion signals or page languages changed is read by Claude (Haiku in the recommended plan). Its
 * answer is an allow-list of changes — rename / archive a service, set the ★, count or stop counting a conversion
 * signal, remove or add a place — each with the evidence it cites. A PHP check drops unknown operations, unknown ids and
 * anything without evidence; what is left waits on the Onarım masası as `brand_data` rows (source "Claude marka
 * denetimi"). Nothing is applied without approval and nothing is written outside MoxDOP. The call can wait for Claude
 * (MCP): status queued, RunBrandDataReviewJob runs again with the answer.
 */
final class BrandDataReview
{
    public const string KIND = 'data_review';

    /** @var list<string> */
    public const array OPS = [
        BrandDataAudit::RENAME_SERVICE, BrandDataAudit::ARCHIVE_SERVICE, BrandDataAudit::SET_MAIN,
        BrandDataAudit::CONVERSION_COUNTS, BrandDataAudit::REMOVE_AREA, BrandDataAudit::ADD_AREA,
    ];

    public const int MAX_OPERATIONS = 10;

    public function __construct(
        private readonly BrandDataAudit $audit,
        private readonly BrandConversionDictionary $conversions,
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly AiTaskQueue $tasks,
    ) {}

    public static function queue(Brand $brand, bool $force = false): void
    {
        RunBrandDataReviewJob::dispatch((int) $brand->id, $force);
    }

    /** @return array{status: string, proposed?: int, dropped?: int, message?: string} status: reviewed | queued | unchanged | empty | no_ai | not_operational */
    public function run(Brand $brand, bool $force = false): array
    {
        if (! $brand->isOperational()) {
            return ['status' => 'not_operational'];
        }
        $input = $this->input($brand);
        if ($input['services'] === [] && $input['areas'] === [] && $input['conversions'] === []) {
            return ['status' => 'empty'];
        }
        $hash = hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $previous = self::stored($brand);
        if (! $force && ($previous['hash'] ?? null) === $hash) {
            return ['status' => 'unchanged'];
        }
        // The brand's data can move while Claude reads it: the call is found again by its slot (validate() checks the
        // answer against the input of this run).
        $raw = $this->tasks->delegatedCall(new BrandDataReviewAgent, $input, 'review');
        if ($raw === 'queued') {
            return ['status' => 'queued'];
        }
        if ($raw === 'error') {
            throw new RuntimeException('Claude bu denetimi yapamadı; tekrar deneyin.');
        }
        if ($raw === null) {
            $route = $this->routes->resolve(BrandDataReviewAgent::OPERATION);
            if ($route->isEmpty()) {
                return ['status' => 'no_ai', 'message' => 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.'];
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $raw = (array) (new BrandDataReviewAgent)->prompt("DATA_JSON\n".json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels, timeout: 180)->toArray();
        }

        $operations = (array) ($raw['operations'] ?? []);
        $items = $this->validate($operations, $input);
        [$proposed] = $this->audit->store($brand, $items, BrandDataAudit::SOURCE_CLAUDE);
        $this->save($brand, ['hash' => $hash, 'reviewed_at' => now()->toIso8601String(), 'proposed' => count($items), 'dropped' => count($operations) - count($items), 'error' => null]);

        return ['status' => 'reviewed', 'proposed' => $proposed, 'dropped' => count($operations) - count($items)];
    }

    /** @return array{status: string, proposed?: int, dropped?: int, message?: string} */
    public function runSafely(int $brandId, bool $force = false): array
    {
        $brand = Brand::query()->find($brandId);
        if ($brand === null) {
            return ['status' => 'missing'];
        }
        try {
            return $this->run($brand, $force);
        } catch (Throwable $exception) {
            report($exception);
            $this->save($brand, ['failed_at' => now()->toIso8601String(), 'error' => mb_substr($exception->getMessage(), 0, 300)] + (self::stored($brand) ?? []));

            return ['status' => 'failed', 'message' => $exception->getMessage()];
        }
    }

    /**
     * What Claude reads: the brand's services with their evidence, places, profile addresses, places the site and searches
     * name, conversion signals and page languages. The same input gives the same hash: an unchanged brand is skipped.
     *
     * @return array{brand: string, pages_read: int, languages: array<string, int>, services: list<array<string, mixed>>, areas: list<array<string, mixed>>, profiles: list<array<string, mixed>>, place_mentions: list<array<string, mixed>>, conversions: list<array<string, mixed>>}
     */
    public function input(Brand $brand): array
    {
        $facts = $this->audit->reviewFacts($brand);
        $sites = DigitalAsset::query()->operational()->where('brand_id', $brand->id)->where('type', 'website')->pluck('id')->all();
        $languages = Page::query()->whereIn('website_asset_id', $sites ?: [0])->whereNotNull('language')->groupBy('language')
            ->selectRaw('language, count(*) as n')->orderByDesc('n')->pluck('n', 'language')->map(fn ($n): int => (int) $n)->all();
        $conversions = BrandConversionSource::query()->where('brand_id', $brand->id)->orderBy('id')->limit(60)->get()
            ->map(fn (BrandConversionSource $row): array => ['id' => (int) $row->id, 'source' => (string) $row->source, 'label' => (string) $row->label,
                'type' => (string) $row->conversion_type, 'counts' => (bool) $row->counts, 'attention' => $this->conversions->isEngagementRow($row)])->values()->all();

        return ['brand' => (string) $brand->name, 'pages_read' => $facts['pages_read'], 'languages' => $languages, 'services' => $facts['services'],
            'areas' => $facts['areas'], 'profiles' => $facts['profiles'], 'place_mentions' => $facts['place_mentions'], 'conversions' => $conversions];
    }

    /**
     * Keeps only allow-listed operations on known targets with evidence, as BrandDataAudit items.
     *
     * @param  array<int, mixed>  $operations
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public function validate(array $operations, array $input): array
    {
        $services = collect($input['services'])->keyBy('id');
        $areas = collect($input['areas'])->keyBy('id');
        $conversions = collect($input['conversions'])->keyBy('id');
        $places = collect($input['place_mentions'])->concat($input['profiles'])
            ->mapWithKeys(fn (array $p): array => [BrandDataAudit::placeKey((string) $p['city'], $p['district'] ?? null) => true])->all();
        $items = [];
        foreach ($operations as $operation) {
            if (! is_array($operation) || ! in_array($operation['op'] ?? null, self::OPS, true)) {
                continue;
            }
            $evidence = array_values(array_slice(array_filter(array_map(fn ($line): string => is_scalar($line) ? mb_substr(trim((string) $line), 0, 200) : '', (array) ($operation['evidence'] ?? []))), 0, 3));
            $reason = mb_substr(trim((string) ($operation['reason'] ?? '')), 0, 240);
            if ($evidence === [] || $reason === '') {
                continue;
            }
            $id = (int) ($operation['target_id'] ?? 0);
            $item = match ($operation['op']) {
                BrandDataAudit::RENAME_SERVICE => $this->rename($services->get($id), trim((string) ($operation['new_name'] ?? '')), $services->all()),
                BrandDataAudit::ARCHIVE_SERVICE => ($s = $services->get($id)) !== null && ! $s['main'] && ! $s['locked']
                    ? $this->item('service:'.$id, BrandDataAudit::ARCHIVE_SERVICE, $s['name'].' hizmeti arşivlensin', RepairDesk::MEDIUM, $s['name'], 'Arşive alınır (Marka › Ayarlar\'dan geri açılabilir)', ['offering_id' => $id]) : null,
                BrandDataAudit::SET_MAIN => ($s = $services->get($id)) !== null && ! $s['main']
                    ? $this->item('main:'.$id, BrandDataAudit::SET_MAIN, $s['name'].' ana hizmet (★) olsun', RepairDesk::MEDIUM,
                        $services->where('main', true)->pluck('name')->implode(', ') ?: '★ yok', '★ '.$s['name'], ['offering_id' => $id]) : null,
                BrandDataAudit::CONVERSION_COUNTS => ($c = $conversions->get($id)) !== null && is_bool($operation['counts'] ?? null) && $operation['counts'] !== $c['counts']
                    ? $this->item('conversion:'.$id, BrandDataAudit::CONVERSION_COUNTS, $c['label'].($operation['counts'] ? ' müşteri sayılsın' : ' müşteri sayılmasın'), RepairDesk::LOW,
                        $c['counts'] ? 'Toplama giriyor' : 'Toplama girmiyor', $operation['counts'] ? 'Toplama girer' : 'Toplama girmez', ['conversion_id' => $id, 'counts' => $operation['counts']]) : null,
                BrandDataAudit::REMOVE_AREA => ($a = $areas->get($id)) !== null && $areas->count() > 1
                    ? $this->item('area:'.$id, BrandDataAudit::REMOVE_AREA, $a['name'].($a['branch'] ? ' (şube)' : '').' kaldırılsın', RepairDesk::MEDIUM, $a['name'], 'Kaldırılır (arşive alınır)', ['area_id' => $id]) : null,
                BrandDataAudit::ADD_AREA => $this->addArea((string) ($operation['city'] ?? ''), (string) ($operation['district'] ?? ''), $places, $areas->all()),
            };
            if ($item === null || isset($items[$item['op'].'|'.$item['key']])) {
                continue;
            }
            $items[$item['op'].'|'.$item['key']] = $item + ['reason' => $reason, 'evidence' => $evidence];
            if (count($items) >= self::MAX_OPERATIONS) {
                break;
            }
        }

        return array_values($items);
    }

    /**
     * @param  array<string, mixed>|null  $service
     * @param  array<int, array<string, mixed>>  $all
     * @return array<string, mixed>|null
     */
    private function rename(?array $service, string $name, array $all): ?array
    {
        $fold = fn (string $value): string => LocationOptions::fold($value);
        if ($service === null || ! $service['own_name'] || mb_strlen($name) < 2 || mb_strlen($name) > 120 || $fold($name) === $fold((string) $service['name'])
            || collect($all)->contains(fn (array $s): bool => $s['id'] !== $service['id'] && $fold((string) $s['name']) === $fold($name))) {
            return null;
        }

        return $this->item('rename:'.$service['id'], BrandDataAudit::RENAME_SERVICE, $service['name'].' hizmetinin adı «'.$name.'» olsun', RepairDesk::MEDIUM,
            $service['name'], $name.' (eski ad eş anlam olarak kalır)', ['offering_id' => (int) $service['id'], 'new_name' => $name]);
    }

    /**
     * A place the pages, searches or a Business Profile name, not a place of the brand yet, resolved to Turkish il / ilçe.
     *
     * @param  array<string, bool>  $places
     * @param  array<int, array<string, mixed>>  $areas
     * @return array<string, mixed>|null
     */
    private function addArea(string $city, string $district, array $places, array $areas): ?array
    {
        try {
            $area = LocationOptions::normalizeArea('TR', $city, $district !== '' ? $district : null);
        } catch (Throwable) {
            return null;
        }
        $city = (string) $area['city_name'];
        $district = $area['district_name'] !== null && $area['district_name'] !== '' ? (string) $area['district_name'] : null;
        $key = BrandDataAudit::placeKey($city, $district);
        if (! isset($places[$key]) || collect($areas)->contains(fn (array $a): bool => BrandDataAudit::placeKey((string) $a['city'], $a['district']) === $key)) {
            return null;
        }
        $place = implode(', ', array_filter([$district, $city]));

        return $this->item('page:'.$key, BrandDataAudit::ADD_AREA, $place.' hizmet bölgesi olarak eklensin', RepairDesk::LOW, '—', $place.' (hizmet bölgesi)',
            ['city' => $city, 'district' => $district, 'physical' => false]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{key: string, op: string, title: string, priority: int, risk: string, before: string, after: string, params: array<string, mixed>}
     */
    private function item(string $key, string $op, string $title, string $risk, string $before, string $after, array $params): array
    {
        return ['key' => $key, 'op' => $op, 'title' => $title, 'priority' => 3, 'risk' => $risk, 'before' => $before, 'after' => $after, 'params' => $params];
    }

    /** @return array<string, mixed>|null */
    public static function stored(Brand $brand): ?array
    {
        $data = BrandMemory::query()->where('brand_id', $brand->id)->where('kind', self::KIND)->value('data');
        $data = is_string($data) ? json_decode($data, true) : $data;

        return is_array($data) ? $data : null;
    }

    /** @param  array<string, mixed>  $data */
    private function save(Brand $brand, array $data): void
    {
        $row = BrandMemory::query()->where('brand_id', $brand->id)->where('kind', self::KIND)->first()
            ?? new BrandMemory(['brand_id' => $brand->id, 'kind' => self::KIND, 'ref_type' => 'brand', 'ref_id' => $brand->id]);
        $row->forceFill(['summary' => null, 'data' => $data])->save();
    }
}

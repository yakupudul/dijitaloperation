<?php

namespace App\Services\Gbp;

use App\Ai\Agents\GbpProfilePlanAgent;
use App\Models\AiProduction;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Archive\ProductionArchive;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\SeoTasks\SeoText;
use RuntimeException;

/**
 * "Kategori ve hizmetler" (yakup, 2026-10-05): the operator writes the categories and services the profile should
 * have; this prepares them for Google and the Admin's "Gönder" adds the chosen ones (GbpWriter, undoable).
 *
 *  1. every category line is searched in Google's own category list (Türkiye, Turkish);
 *  2. AI picks the matching Google category per line and, per service line, the category it belongs to and Google's
 *     predefined service type when one exists (else a free-form service with a short description);
 *  3. the answer is checked against the data: only candidate / profile category ids, only that category's service
 *     type ids (their official name wins), at most 9 additional categories, ≤ 120 / 300 characters, no contact data,
 *     no blocking sector-compliance hit; what is already on the profile is marked and cannot be sent.
 *
 * The plan is stored in the production archive (kind `gbp.profile_plan`). Primary category, removals and edits of
 * existing items stay on Google (yakup, 2026-10-05: additions only).
 */
final class GbpProfilePlanner
{
    public const string KIND = 'gbp.profile_plan';

    public const int CATEGORY_LINES_MAX = 10;

    public const int SERVICE_LINES_MAX = 40;

    public const int ADDITIONAL_MAX = 9;

    public const int NAME_MAX = 120;

    public const int DESCRIPTION_MAX = 300;

    private const string CONTACT_PATTERN = '~https?://|www\.|[\w.+-]+@[\w-]+\.[\w.]+|\+?\d[\d\s().-]{8,}\d~iu';

    public function __construct(
        private readonly GbpCategoryCatalog $catalog,
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly AiTaskQueue $tasks,
        private readonly ProductionArchive $archive,
    ) {}

    /**
     * One entry per non-empty line, trimmed, unique, capped.
     *
     * @return list<string>
     */
    public static function lines(string $text, int $max): array
    {
        $out = [];
        foreach (preg_split('/[\r\n]+/u', $text) ?: [] as $line) {
            $line = mb_substr(trim(preg_replace('/\s+/u', ' ', ltrim($line, " \t-*•·")) ?? ''), 0, self::NAME_MAX);
            if ($line !== '' && ! isset($out[SeoText::fold($line)])) {
                $out[SeoText::fold($line)] = $line;
            }
        }

        return array_slice(array_values($out), 0, $max);
    }

    /**
     * Prepares the plan; null while the call waits for Claude (the job runs again when it answered).
     *
     * @param  array{categories?: list<string>, services?: list<string>}  $params
     */
    public function plan(DigitalAsset $asset, array $params): ?string
    {
        $categoryLines = array_slice(array_values(array_filter(array_map('strval', (array) ($params['categories'] ?? [])))), 0, self::CATEGORY_LINES_MAX);
        $serviceLines = array_slice(array_values(array_filter(array_map('strval', (array) ($params['services'] ?? [])))), 0, self::SERVICE_LINES_MAX);
        if ($categoryLines === [] && $serviceLines === []) {
            throw new RuntimeException('Kategori ya da hizmet yazın.');
        }
        $brand = $asset->brand ?? throw new RuntimeException('Varlık bir markaya bağlı değil.');
        [$integration, $locationName] = $this->catalog->location((int) $asset->id);
        $current = $this->catalog->current($integration, $locationName);
        $primaryId = (string) data_get($current['categories'], 'primaryCategory.name', '');
        if ($primaryId === '') {
            throw new RuntimeException('Profilin birincil kategorisi yok; önce Google’da birincil kategori seçin.');
        }
        $additionalIds = array_values(array_filter(array_map(fn ($c): string => (string) data_get($c, 'name', ''), (array) data_get($current['categories'], 'additionalCategories', []))));
        $catalog = $this->catalog->batch($integration, [$primaryId, ...$additionalIds]);
        $requests = [];
        foreach ($categoryLines as $line) {
            $candidates = $this->catalog->search($integration, $line);
            foreach ($candidates as $candidate) {
                $catalog[$candidate['id']] ??= $candidate;
            }
            $requests[] = ['line' => $line, 'candidates' => array_map(fn (array $c): array => ['id' => $c['id'], 'name' => $c['name']], $candidates)];
        }
        $profileServices = self::serviceLabels($current['serviceItems'], $catalog);
        $shown = [$primaryId => (string) data_get($current['categories'], 'primaryCategory.displayName', '')];
        foreach ((array) data_get($current['categories'], 'additionalCategories', []) as $category) {
            $shown[(string) data_get($category, 'name', '')] = (string) data_get($category, 'displayName', '');
        }
        foreach ($shown as $id => $name) {
            $catalog[$id] ??= ['id' => $id, 'name' => $name !== '' ? $name : $id, 'service_types' => []];
        }
        $named = fn (string $id): array => ['id' => $id, 'name' => $catalog[$id]['name']];
        $data = [
            'business' => $brand->name,
            'primary_category' => $named($primaryId),
            'additional_categories' => array_map($named, $additionalIds),
            'profile_services' => array_values($profileServices),
            'category_requests' => $requests,
            'catalog' => array_map(fn (array $c): array => ['name' => $c['name'], 'service_types' => array_map(fn (array $t): array => ['id' => $t['id'], 'name' => $t['name']], $c['service_types'])], $catalog),
            'service_requests' => $serviceLines,
            'offerings' => array_column(app(GbpAssistant::class)->offerings($brand), 'name'),
            'compliance' => app(SectorPackRegistry::class)->rulesForBrand($brand)->pluck('message')->unique()->values()->take(12)->all(),
        ];
        [$raw, $versionId] = $this->ask($data);
        if ($raw === null) {
            return null;
        }
        $plan = self::validate($raw, $brand, $requests, $serviceLines, $catalog, $primaryId, $additionalIds, $profileServices, $current['serviceItems']);
        $plan['input'] = ['categories' => $categoryLines, 'services' => $serviceLines];
        $plan['prompt_version_id'] = $versionId;
        $plan['created_at'] = now()->toIso8601String();
        $this->archive->record(self::KIND, $asset, $plan, ['brand_id' => $brand->id, 'digital_asset_id' => $asset->id,
            'title' => 'Kategori ve hizmetler · '.count($plan['categories']).' kategori, '.count($plan['services']).' hizmet', 'prompt_version' => 'gbp-profile-plan']);
        $new = fn (array $rows): int => count(array_filter($rows, fn (array $r): bool => $r['status'] === 'new'));

        return $new($plan['categories']).' kategori, '.$new($plan['services']).' hizmet eklenmeye hazır'.($plan['skipped'] !== [] ? '; '.count($plan['skipped']).' satır atlandı' : '').'.';
    }

    /** Latest plan of the last two weeks that was not sent or discarded. */
    public function latest(DigitalAsset $asset): ?AiProduction
    {
        $plan = $this->archive->fresh(self::KIND, $asset);

        return $plan !== null && $plan->status === AiProduction::STATUS_NEW ? $plan : null;
    }

    /**
     * Keeps only what Google and the data support (see the class note).
     *
     * @param  array<mixed>  $raw
     * @param  list<array{line: string, candidates: list<array{id: string, name: string}>}>  $requests
     * @param  list<string>  $serviceLines
     * @param  array<string, array{id: string, name: string, service_types: list<array{id: string, name: string}>}>  $catalog
     * @param  list<string>  $additionalIds
     * @param  array<string, string>  $profileServices  folded name => name
     * @param  list<array<string, mixed>>  $serviceItems
     * @return array{primary: array{id: string, name: string}, categories: list<array<string, mixed>>, services: list<array<string, mixed>>, skipped: list<array{line: string, reason: string}>}
     */
    public static function validate(array $raw, ?Brand $brand, array $requests, array $serviceLines, array $catalog, string $primaryId, array $additionalIds, array $profileServices, array $serviceItems): array
    {
        $skipped = [];
        $answers = [];
        foreach ((array) ($raw['categories'] ?? []) as $row) {
            if (is_array($row)) {
                $answers[SeoText::fold((string) ($row['line'] ?? ''))] ??= $row;
            }
        }
        $categories = [];
        $slots = self::ADDITIONAL_MAX - count($additionalIds);
        foreach ($requests as $request) {
            $row = $answers[SeoText::fold($request['line'])] ?? null;
            $id = trim((string) ($row['category_id'] ?? ''));
            $reason = self::line((string) ($row['reason'] ?? ''));
            if ($request['candidates'] === [] || ! in_array($id, array_column($request['candidates'], 'id'), true)) {
                $skipped[] = ['line' => $request['line'], 'reason' => $request['candidates'] === [] ? 'Google’ın kategori listesinde bu adla kategori yok; başka bir adla deneyin.' : ($reason !== '' ? $reason : 'Uygun Google kategorisi bulunamadı.')];

                continue;
            }
            if (isset($categories[$id])) {
                continue;
            }
            $status = $id === $primaryId || in_array($id, $additionalIds, true) ? 'exists' : ($slots > 0 ? 'new' : 'limit');
            if ($status === 'new') {
                $slots--;
            }
            $categories[$id] = ['id' => $id, 'name' => $catalog[$id]['name'] ?? $id, 'line' => $request['line'], 'reason' => $reason, 'status' => $status];
        }
        $usable = array_flip([$primaryId, ...$additionalIds, ...array_keys(array_filter($categories, fn (array $c): bool => $c['status'] === 'new'))]);
        $existingTypes = array_flip(array_filter(array_map(fn ($i): string => (string) data_get($i, 'structuredServiceItem.serviceTypeId', ''), $serviceItems)));
        $answers = [];
        foreach ((array) ($raw['services'] ?? []) as $row) {
            if (is_array($row)) {
                $answers[SeoText::fold((string) ($row['line'] ?? ''))] ??= $row;
            }
        }
        $services = [];
        foreach ($serviceLines as $line) {
            $row = $answers[SeoText::fold($line)] ?? null;
            $categoryId = trim((string) ($row['category_id'] ?? ''));
            if ($row === null || ! isset($usable[$categoryId])) {
                $skipped[] = ['line' => $line, 'reason' => self::line((string) ($row['reason'] ?? '')) ?: 'Profilin kategorilerinden hiçbirine uymuyor; önce uygun kategoriyi ekleyin.'];

                continue;
            }
            $typeId = trim((string) ($row['service_type_id'] ?? ''));
            $type = collect($catalog[$categoryId]['service_types'] ?? [])->firstWhere('id', $typeId);
            $name = $type !== null ? (string) $type['name'] : self::line((string) ($row['name'] ?? ''), self::NAME_MAX);
            $description = self::description((string) ($row['description'] ?? ''));
            if ($name === '' || preg_match(self::CONTACT_PATTERN, $name) === 1) {
                $skipped[] = ['line' => $line, 'reason' => 'AI geçerli bir hizmet adı döndürmedi.'];

                continue;
            }
            $blocking = GbpAssistant::blockingHits($brand, $name.' '.$description);
            if ($blocking !== []) {
                $skipped[] = ['line' => $line, 'reason' => 'Sektör uyum kuralına takıldı: '.implode(', ', $blocking).'.'];

                continue;
            }
            $key = $type !== null ? $typeId : SeoText::fold($name);
            if (isset($services[$key])) {
                continue;
            }
            $exists = ($type !== null && isset($existingTypes[$typeId])) || isset($profileServices[SeoText::fold($name)]);
            $services[$key] = ['line' => $line, 'category_id' => $categoryId, 'category' => $catalog[$categoryId]['name'] ?? $categoryId,
                'service_type_id' => $type !== null ? $typeId : null, 'name' => $name, 'description' => $description,
                'reason' => self::line((string) ($row['reason'] ?? '')), 'status' => $exists ? 'exists' : 'new'];
        }

        return ['primary' => ['id' => $primaryId, 'name' => $catalog[$primaryId]['name'] ?? $primaryId], 'categories' => array_values($categories),
            'services' => array_values($services), 'skipped' => $skipped];
    }

    /** Description as sent: ≤ 300 characters cut at a sentence end, no contact data (else empty). */
    public static function description(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        if (mb_strlen($text) > self::DESCRIPTION_MAX) {
            $cut = mb_substr($text, 0, self::DESCRIPTION_MAX);
            $end = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '! '), (int) mb_strrpos($cut, '? '));
            $text = $end > 0 ? mb_substr($cut, 0, $end + 1) : rtrim(mb_substr($cut, 0, self::DESCRIPTION_MAX - 1)).'…';
        }

        return preg_match(self::CONTACT_PATTERN, $text) === 1 ? '' : $text;
    }

    /**
     * Names of the profile's service items (free-form label, or the predefined type's name from the catalog).
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, array{id: string, name: string, service_types: list<array{id: string, name: string}>}>  $catalog
     * @return array<string, string> folded name => name
     */
    public static function serviceLabels(array $items, array $catalog): array
    {
        $types = [];
        foreach ($catalog as $category) {
            foreach ($category['service_types'] as $type) {
                $types[$type['id']] = $type['name'];
            }
        }
        $out = [];
        foreach ($items as $item) {
            $typeId = (string) data_get($item, 'structuredServiceItem.serviceTypeId', '');
            $name = (string) (data_get($item, 'freeFormServiceItem.label.displayName') ?? ($types[$typeId] ?? str_replace(['job_type_id:', '_'], ['', ' '], $typeId)));
            if (trim($name) !== '') {
                $out[SeoText::fold($name)] = $name;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?array<mixed>, 1: ?int} structured answer (null while Claude has not answered) and prompt version
     */
    private function ask(array $data): array
    {
        $agent = new GbpProfilePlanAgent;
        $answer = $this->tasks->delegatedCall($agent, $data);
        if ($answer === 'queued') {
            return [null, null];
        }
        if ($answer === 'error') {
            throw new RuntimeException('Claude bu hazırlığı yapamadı; tekrar deneyin.');
        }
        if (is_array($answer)) {
            return [$answer, $agent->promptVersionId()];
        }
        $route = $this->routes->resolve(GbpProfilePlanAgent::OPERATION);
        if ($route->isEmpty()) {
            throw new RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.');
        }
        $this->runtime->prepare(array_keys($route->providerModels));
        $response = (array) $agent->prompt("DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            provider: $route->providerModels, timeout: 120)->toArray();

        return [$response, $agent->promptVersionId()];
    }

    private static function line(string $text, int $max = 240): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? ''), 0, $max);
    }
}

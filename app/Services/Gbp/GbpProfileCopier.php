<?php

namespace App\Services\Gbp;

use App\Ai\Agents\GbpBranchServicesAgent;
use App\Models\AiProduction;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Archive\ProductionArchive;
use App\Services\Brand\BrandPack;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\SeoTasks\SeoText;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * One branch's categories and services onto the brand's other branches (yakup, 2026-10-13: "Çiğli şubesine
 * tanımladığımız tüm hizmet kategori ve hizmetleri, sadece açıklamaları farklı olacak şekilde diğer şubeler için de
 * hazırla gönder"). Read live from Google on both sides; what a target lacks is added with the source's exact
 * category ids, service types and names, and a description AI writes for that branch (GbpBranchServicesAgent).
 * Each target becomes a `gbp.profile_plan` record and an ADR-077 write (additions only, undoable on the profile's
 * "Kategori ve hizmetler" tab). When Claude writes the descriptions over MCP (AiTaskQueue) the send runs in
 * SendGbpBranchCopyJob, which waits for Claude and sends once the descriptions are in.
 */
final class GbpProfileCopier
{
    public function __construct(
        private readonly GbpCategoryCatalog $catalog,
        private readonly GbpDesk $desk,
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly ProductionArchive $archive,
        private readonly ExternalWriteService $writes,
        private readonly AiTaskQueue $tasks,
    ) {}

    /** Whether the descriptions are written by Claude over MCP (the send then waits in SendGbpBranchCopyJob). */
    public function waitsForClaude(): bool
    {
        return $this->tasks->delegated(GbpBranchServicesAgent::OPERATION);
    }

    /**
     * The source's live categories (primary first) and services, with Google's names.
     *
     * @return array{categories: list<array{id: string, name: string}>, services: list<array{category_id: string, service_type_id: ?string, name: string, category: string, description: string}>}
     */
    public function source(DigitalAsset $source): array
    {
        [$integration, $locationName] = $this->catalog->location((int) $source->id);
        $current = $this->catalog->current($integration, $locationName);
        $categories = [];
        foreach ([(array) data_get($current['categories'], 'primaryCategory', []), ...(array) data_get($current['categories'], 'additionalCategories', [])] as $category) {
            $id = (string) data_get($category, 'name', '');
            if ($id !== '') {
                $categories[$id] = ['id' => $id, 'name' => (string) data_get($category, 'displayName', $id)];
            }
        }
        $catalog = $this->catalog->batch($integration, array_keys($categories));
        $types = [];
        foreach ($catalog as $id => $category) {
            foreach ($category['service_types'] as $type) {
                $types[$type['id']] ??= ['category_id' => $id, 'name' => $type['name']];
            }
        }
        $services = [];
        foreach ($current['serviceItems'] as $item) {
            $typeId = (string) data_get($item, 'structuredServiceItem.serviceTypeId', '');
            if ($typeId !== '') {
                $categoryId = $types[$typeId]['category_id'] ?? '';
                $name = $types[$typeId]['name'] ?? str_replace(['job_type_id:', '_'], ['', ' '], $typeId);
                $description = (string) data_get($item, 'structuredServiceItem.description', '');
            } else {
                $categoryId = (string) data_get($item, 'freeFormServiceItem.category', '');
                $name = trim((string) data_get($item, 'freeFormServiceItem.label.displayName', ''));
                $description = (string) data_get($item, 'freeFormServiceItem.label.description', '');
            }
            if ($name !== '' && ($typeId !== '' || isset($categories[$categoryId]))) {
                $services[] = ['category_id' => $categoryId, 'service_type_id' => $typeId !== '' ? $typeId : null, 'name' => $name,
                    'category' => $categories[$categoryId]['name'] ?? '', 'description' => trim($description)];
            }
        }

        return ['categories' => array_values($categories), 'services' => $services];
    }

    /**
     * What one target lacks of the source: categories in the source's order while the profile has room, then the
     * services whose category the target has or gets.
     *
     * @param  array{categories: list<array{id: string, name: string}>, services: list<array{category_id: string, service_type_id: ?string, name: string, category: string, description: string}>}  $source
     * @return array{categories: list<array{id: string, name: string, status: string}>, services: list<array<string, mixed>>, skipped: list<array{line: string, reason: string}>, primary: array{id: string, name: string}}
     */
    public function missing(DigitalAsset $target, array $source): array
    {
        [$integration, $locationName] = $this->catalog->location((int) $target->id);
        $current = $this->catalog->current($integration, $locationName);
        $primaryId = (string) data_get($current['categories'], 'primaryCategory.name', '');
        if ($primaryId === '') {
            throw new RuntimeException('Profilin birincil kategorisi yok; önce Google’da birincil kategori seçin.');
        }
        $additionalIds = array_values(array_filter(array_map(fn ($c): string => (string) data_get($c, 'name', ''), (array) data_get($current['categories'], 'additionalCategories', []))));
        $has = array_flip([$primaryId, ...$additionalIds]);
        $slots = GbpProfilePlanner::ADDITIONAL_MAX - count($additionalIds);
        $categories = [];
        $skipped = [];
        foreach ($source['categories'] as $category) {
            if (isset($has[$category['id']])) {
                continue;
            }
            if ($slots <= 0) {
                $skipped[] = ['line' => $category['name'], 'reason' => 'Profil en çok '.GbpProfilePlanner::ADDITIONAL_MAX.' ek kategori alıyor; yer kalmadı.'];

                continue;
            }
            $slots--;
            $categories[] = ['id' => $category['id'], 'name' => $category['name'], 'line' => $category['name'], 'reason' => 'Kaynak şubede var.', 'status' => 'new'];
            $has[$category['id']] = true;
        }
        $types = array_flip(array_filter(array_map(fn ($i): string => (string) data_get($i, 'structuredServiceItem.serviceTypeId', ''), $current['serviceItems'])));
        $labels = array_flip(array_filter(array_map(fn ($i): string => SeoText::fold((string) data_get($i, 'freeFormServiceItem.label.displayName', '')), $current['serviceItems'])));
        $services = [];
        foreach ($source['services'] as $service) {
            if ($service['service_type_id'] !== null ? isset($types[$service['service_type_id']]) : isset($labels[SeoText::fold($service['name'])])) {
                continue;
            }
            if ($service['category_id'] !== '' && ! isset($has[$service['category_id']])) {
                $skipped[] = ['line' => $service['name'], 'reason' => '«'.$service['category'].'» kategorisi eklenemedi.'];

                continue;
            }
            $services[] = ['line' => $service['name'], 'category_id' => $service['category_id'], 'category' => $service['category'],
                'service_type_id' => $service['service_type_id'], 'name' => $service['name'], 'description' => '', 'source_description' => $service['description'],
                'reason' => 'Kaynak şubede var.', 'status' => 'new'];
        }

        return ['primary' => ['id' => $primaryId, 'name' => (string) data_get($current['categories'], 'primaryCategory.displayName', $primaryId)],
            'categories' => $categories, 'services' => $services, 'skipped' => $skipped];
    }

    /**
     * Writes this branch's descriptions, records the plan and sends it (ADR-077 write, queued). Null while Claude has
     * not written the descriptions yet (nothing is recorded or sent; the job runs again when Claude answered).
     *
     * @param  array{categories: list<array<string, mixed>>, services: list<array<string, mixed>>, skipped: list<array{line: string, reason: string}>, primary: array{id: string, name: string}}  $missing
     * @return array{action: ExternalWriteAction, without_description: int}|null
     */
    public function send(User $user, DigitalAsset $source, DigitalAsset $target, array $missing): ?array
    {
        $brand = $target->loadMissing('brand')->brand ?? throw new RuntimeException('Varlık bir markaya bağlı değil.');
        $described = $missing['services'] !== [] ? $this->describe($source, $target, $missing['services']) : [[], null];
        if ($described === null) {
            return null;
        }
        [$descriptions, $versionId] = $described;
        $withoutDescription = 0;
        $services = [];
        foreach ($missing['services'] as $i => $service) {
            $description = GbpProfilePlanner::description((string) ($descriptions['s'.$i] ?? ''));
            if ($description !== '' && (SeoText::fold($description) === SeoText::fold((string) $service['source_description'])
                || GbpAssistant::blockingHits($brand, $service['name'].' '.$description) !== [])) {
                $description = '';
            }
            if ($description === '') {
                $withoutDescription++;
            }
            unset($service['source_description']);
            $services[] = [...$service, 'description' => $description];
        }
        $plan = $this->archive->record(GbpProfilePlanner::KIND, $target, [
            'primary' => $missing['primary'], 'categories' => $missing['categories'], 'services' => $services, 'skipped' => $missing['skipped'],
            'input' => ['copied_from' => ['asset_id' => (int) $source->id, 'name' => GbpDesk::shortName((string) $source->name)]],
            'prompt_version_id' => $versionId, 'prepared_at' => now()->toIso8601String(), 'created_at' => now()->toIso8601String(),
        ], ['brand_id' => $brand->id, 'digital_asset_id' => $target->id, 'prompt_version' => 'gbp-branch-services',
            'title' => 'Kategori ve hizmetler · '.GbpDesk::shortName((string) $source->name).' şubesinden · '.count($missing['categories']).' kategori, '.count($services).' hizmet']);
        if (! $plan instanceof AiProduction) {
            throw new RuntimeException('Hazırlık kaydedilemedi.');
        }
        try {
            $action = $this->writes->requestProfileUpdate($user, $target, $plan, array_column($missing['categories'], 'id'), array_keys($services));
        } catch (ValidationException $exception) {
            throw new RuntimeException((string) collect($exception->errors())->flatten()->first());
        }

        return ['action' => $action, 'without_description' => $withoutDescription];
    }

    /**
     * @param  list<array<string, mixed>>  $services
     * @return array{0: array<string, string>, 1: ?int}|null description per key ("s{index}") and prompt version; null while Claude has not answered
     */
    private function describe(DigitalAsset $source, DigitalAsset $target, array $services): ?array
    {
        $brand = $target->brand;
        $snapshot = $this->desk->snapshots([(int) $target->id])[(int) $target->id] ?? [];
        $data = [
            'business' => (string) $brand?->name,
            'branch' => ['name' => GbpDesk::shortName((string) $target->name), 'area' => (string) ($snapshot['area'] ?? ''), 'address' => (string) ($snapshot['address_text'] ?? '')],
            'source_branch' => GbpDesk::shortName((string) $source->name),
            'services' => array_map(fn (int $i, array $s): array => ['key' => 's'.$i, 'name' => $s['name'], 'category' => $s['category'],
                'source_description' => mb_substr((string) $s['source_description'], 0, 300)], array_keys($services), $services),
            'offerings' => array_column(app(GbpAssistant::class)->offerings($brand), 'name'),
            'compliance' => BrandPack::rules($brand),
        ];
        $agent = new GbpBranchServicesAgent;
        // Named by the target: the live snapshot and the offerings can drift while Claude writes.
        $answer = $this->tasks->delegatedCall($agent, $data, 'target:'.$target->id);
        if ($answer === 'queued') {
            return null;
        }
        if ($answer === 'error') {
            throw new RuntimeException('Açıklamalar yazılamadı: Claude bu işi yapamadı.');
        }
        if (! is_array($answer)) {
            $route = $this->routes->resolve(GbpBranchServicesAgent::OPERATION);
            if ($route->isEmpty()) {
                throw new RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.');
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            try {
                $answer = (array) $agent->prompt("DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    provider: $route->providerModels, timeout: 180)->toArray();
            } catch (Throwable $exception) {
                throw new RuntimeException('Açıklamalar yazılamadı: '.mb_substr($exception->getMessage(), 0, 200));
            }
        }
        $out = [];
        foreach ((array) ($answer['services'] ?? []) as $row) {
            if (is_array($row) && is_string($row['key'] ?? null)) {
                $out[$row['key']] = (string) ($row['description'] ?? '');
            }
        }

        return [$out, $agent->promptVersionId()];
    }
}

<?php

namespace App\Services\Gbp;

use App\Ai\Agents\GbpDescriptionAgent;
use App\Ai\Agents\GbpPostFromPageAgent;
use App\Ai\Agents\GbpProfilePlanAgent;
use App\Ai\Agents\GbpServicesCompareAgent;
use App\Jobs\Gbp\RunGbpAssistantJob;
use App\Models\AiProduction;
use App\Models\Brand;
use App\Models\BrandMemory;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Archive\ProductionArchive;
use App\Services\Compliance\ComplianceAuditor;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\SeoTasks\SeoText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Faz 7 AI operations of the İşletme Profili screen, each one queued call on an operator click, operational brands only:
 *  - `gbp.services_compare`: approved offerings vs profile categories / services → suggestions (missing services with
 *    the exact offering name, category notes on existing categories; business-name keyword advice is dropped);
 *  - `gbp.description`: proposed description (≤ 750 characters, no links / phones, sector compliance, only this
 *    profile's own area: a text naming another branch's district is dropped) → suggestion
 *    with the current and the proposed text (operator copies it to Google, no API write);
 *  - `gbp.post_from_page`: one post from a page of the brand's site (≤ 1500 characters, CTA = page URL, compliance) →
 *    draft in the production archive; publishing is the ADR-073 Admin write;
 *  - `gbp.profile_plan`: the operator's category / service list prepared for Google (GbpProfilePlanner) → plan in the
 *    production archive; the Admin's "Gönder" adds the chosen items (ADR-077).
 * AI output is validated against the input before it is stored; a failed check stores nothing.
 */
final class GbpAssistant
{
    public const string OP_SERVICES = 'services_compare';

    public const string OP_DESCRIPTION = 'description';

    public const string OP_POST = 'post_from_page';

    public const string OP_PROFILE = 'profile_plan';

    public const array OPERATIONS = [
        self::OP_SERVICES => GbpServicesCompareAgent::class,
        self::OP_DESCRIPTION => GbpDescriptionAgent::class,
        self::OP_POST => GbpPostFromPageAgent::class,
        self::OP_PROFILE => GbpProfilePlanAgent::class,
    ];

    public const int DESCRIPTION_MAX = 750;

    public const int POST_MAX = 1500;

    /** Archive kind of page-based post drafts (loaded into the Gönderiler form). */
    public const string POST_KIND = 'gbp.post_from_page';

    private const string CONTACT_PATTERN = '~https?://|www\.|[\w.+-]+@[\w-]+\.[\w.]+|\+?\d[\d\s().-]{8,}\d~iu';

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly GbpStandardInput $input,
        private readonly GbpSuggestions $suggestions,
        private readonly ProductionArchive $archive,
    ) {}

    /**
     * @param  array{page_id?: int, categories?: list<string>, services?: list<string>}  $params
     *
     * @throws ValidationException
     */
    public function queue(DigitalAsset $asset, string $operation, array $params = []): void
    {
        if (! isset(self::OPERATIONS[$operation])) {
            throw ValidationException::withMessages(['gbp' => 'Bilinmeyen işlem.']);
        }
        $asset->loadMissing('brand.customer');
        if ($asset->brand === null || ! $asset->brand->isOperational()) {
            throw ValidationException::withMessages(['gbp' => 'Marka operasyonel değil; AI çalışmaz.']);
        }
        $route = $this->agent($operation)::OPERATION;
        if (! app(AiTaskQueue::class)->delegated($route) && $this->routes->resolve($route)->isEmpty()) {
            throw ValidationException::withMessages(['gbp' => 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).']);
        }
        if ($operation === self::OP_POST && $this->page($asset, (int) ($params['page_id'] ?? 0)) === null) {
            throw ValidationException::withMessages(['gbp' => 'Sayfa seçin.']);
        }
        if ($operation === self::OP_PROFILE && ($params['categories'] ?? []) === [] && ($params['services'] ?? []) === []) {
            throw ValidationException::withMessages(['gbp' => 'Eklenecek kategori ya da hizmet yazın.']);
        }
        Cache::put(self::stateKey((int) $asset->id, $operation), ['status' => 'running', 'at' => now()->toIso8601String()], now()->addMinutes(15));
        RunGbpAssistantJob::dispatch((int) $asset->id, $operation, $params);
    }

    /** Executed by the job; the outcome is kept for the screen (running → ready | failed with a Turkish message; still running while Claude has not answered). */
    public function run(int $assetId, string $operation, array $params = []): void
    {
        try {
            $asset = DigitalAsset::query()->with('brand.customer')->find($assetId);
            if ($asset === null || $asset->brand === null || ! $asset->brand->isOperational()) {
                throw new RuntimeException('Marka operasyonel değil; AI çalışmaz.');
            }
            $message = match ($operation) {
                self::OP_SERVICES => $this->compareServices($asset),
                self::OP_DESCRIPTION => $this->proposeDescription($asset),
                self::OP_POST => $this->postFromPage($asset, (int) ($params['page_id'] ?? 0)),
                self::OP_PROFILE => app(GbpProfilePlanner::class)->plan($asset, $params),
                default => throw new RuntimeException('Bilinmeyen işlem.'),
            };
            if ($message === null) {
                Cache::put(self::stateKey($assetId, $operation), ['status' => 'running', 'message' => 'Claude sırasında; yanıtlayınca burada görünür.', 'at' => now()->toIso8601String()], now()->addDay());

                return;
            }
            Cache::put(self::stateKey($assetId, $operation), ['status' => 'ready', 'message' => $message, 'at' => now()->toIso8601String()], now()->addDay());
        } catch (Throwable $exception) {
            Cache::put(self::stateKey($assetId, $operation), ['status' => 'failed', 'message' => mb_substr($exception->getMessage(), 0, 300), 'at' => now()->toIso8601String()], now()->addDay());
        }
    }

    /** @return array{status: string, message?: string}|null */
    public function state(int $assetId, string $operation): ?array
    {
        $state = Cache::get(self::stateKey($assetId, $operation));

        return is_array($state) ? $state : null;
    }

    public static function stateKey(int $assetId, string $operation): string
    {
        return 'gbp-assistant:'.$assetId.':'.$operation;
    }

    public function compareServices(DigitalAsset $asset): string
    {
        $profile = $this->profile($asset);
        $offerings = $this->offerings($asset->brand);
        if ($offerings === []) {
            throw new RuntimeException('Markanın onaylı hizmeti yok (Marka › Ayarlar › Hizmetler).');
        }
        [$raw, $versionId] = $this->call(self::OP_SERVICES, [
            'offerings' => $offerings,
            'primary_category' => $profile['primary_category'],
            'additional_categories' => $profile['additional_categories'],
            'profile_services' => $profile['services'],
        ]);
        $valid = self::validateComparison($raw, $offerings, $profile);
        $items = [];
        foreach ($valid['missing_services'] as $row) {
            $items[] = ['key' => 'service:'.SeoText::fold($row['name']), 'title' => 'Hizmet ekle: '.$row['name'],
                'reason' => $row['reason'] !== '' ? $row['reason'] : 'Markanın hizmeti; profilin hizmet listesinde yok.',
                'priority' => $row['priority'] === 'main' ? 1 : 2,
                'evidence' => [['hizmet' => $row['name'], 'öncelik' => $row['priority'] === 'main' ? 'Ana' : 'İkincil', 'profil_hizmetleri' => $profile['services'] === [] ? 'veri yok' : implode(', ', array_slice($profile['services'], 0, 15))]],
                'action_type' => 'gbp_add_service', 'action' => ['name' => $row['name']], 'prompt_version_id' => $versionId];
        }
        $this->suggestions->replaceGroup($asset, 'service', $items);
        $notes = [];
        foreach ($valid['category_notes'] as $row) {
            $notes[] = ['key' => 'category:'.($row['category'] !== '' ? SeoText::fold($row['category']) : '_:'.substr(hash('sha256', SeoText::fold($row['note'])), 0, 12)),
                'title' => $row['category'] !== '' ? 'Kategori: '.$row['category'] : 'Kategori eksik', 'reason' => $row['note'], 'priority' => 2,
                'evidence' => [['birincil' => $profile['primary_category'] ?: 'veri yok', 'ek' => $profile['additional_categories'] === [] ? '—' : implode(', ', $profile['additional_categories'])]],
                'action_type' => 'gbp_category', 'action' => ['category' => $row['category'], 'note' => $row['note']], 'prompt_version_id' => $versionId];
        }
        $this->suggestions->replaceGroup($asset, 'category', $notes);

        return count($items).' eksik hizmet, '.count($notes).' kategori notu.';
    }

    /**
     * Keeps only what the data supports: service names copied exactly from the offerings and not already on the profile,
     * notes on an existing category (or a general "missing category" note), never business-name keyword advice.
     *
     * @param  array<mixed>  $raw
     * @param  list<array{name: string, priority: string}>  $offerings
     * @param  array{primary_category: string, additional_categories: list<string>, services: list<string>}  $profile
     * @return array{missing_services: list<array{name: string, reason: string, priority: string}>, category_notes: list<array{category: string, note: string}>}
     */
    public static function validateComparison(array $raw, array $offerings, array $profile): array
    {
        $byName = [];
        foreach ($offerings as $offering) {
            $byName[$offering['name']] = $offering['priority'];
        }
        $onProfile = array_map(fn (string $s): string => SeoText::fold($s), $profile['services']);
        $missing = [];
        foreach ((array) ($raw['missing_services'] ?? []) as $row) {
            $name = is_array($row) ? trim((string) ($row['name'] ?? '')) : '';
            if (! isset($byName[$name]) || in_array(SeoText::fold($name), $onProfile, true) || isset($missing[$name])) {
                continue;
            }
            $missing[$name] = ['name' => $name, 'reason' => self::line((string) ($row['reason'] ?? '')), 'priority' => $byName[$name]];
        }
        uasort($missing, fn (array $a, array $b): int => ($a['priority'] === 'main' ? 0 : 1) <=> ($b['priority'] === 'main' ? 0 : 1));
        $categories = [];
        foreach (array_filter(array_merge([$profile['primary_category']], $profile['additional_categories'])) as $category) {
            $categories[SeoText::fold($category)] = $category;
        }
        $notes = [];
        foreach ((array) ($raw['category_notes'] ?? []) as $row) {
            $category = is_array($row) ? trim((string) ($row['category'] ?? '')) : '';
            $note = is_array($row) ? self::line((string) ($row['note'] ?? '')) : '';
            if ($note === '' || ($category !== '' && ! isset($categories[SeoText::fold($category)])) || self::namesBusinessName($note)) {
                continue;
            }
            $notes[] = ['category' => $category !== '' ? $categories[SeoText::fold($category)] : '', 'note' => $note];
        }

        return ['missing_services' => array_values($missing), 'category_notes' => array_slice($notes, 0, 5)];
    }

    public function proposeDescription(DigitalAsset $asset): string
    {
        $profile = $this->profile($asset);
        $brand = $asset->brand;
        $offerings = $this->offerings($brand);
        if ($offerings === []) {
            throw new RuntimeException('Markanın onaylı hizmeti yok (Marka › Ayarlar › Hizmetler).');
        }
        $place = $this->place($asset);
        [$raw, $versionId] = $this->call(self::OP_DESCRIPTION, [
            'business' => $profile['title'] ?: $brand->name,
            'categories' => array_values(array_filter(array_merge([$profile['primary_category']], $profile['additional_categories']))),
            'current_description' => $profile['description'],
            'brand_profile' => $this->memory($brand),
            'offerings' => $offerings,
            'area' => $place['area'],
            'areas' => $place['single'] ? $this->areas($brand) : [],
            'compliance' => $this->complianceRules($brand),
        ]);
        $description = $this->checkedText($brand, (string) ($raw['description'] ?? ''), self::DESCRIPTION_MAX, 'Açıklama');
        $other = array_values(array_filter($place['siblings'], fn (string $district): bool => SeoText::containsPhrase($description, $district)));
        if ($other !== []) {
            throw new RuntimeException('Açıklama başka bir şubenin bölgesini anıyor ('.implode(', ', $other).'); tekrar deneyin.');
        }
        $this->suggestions->replaceGroup($asset, 'description', [[
            'key' => 'description', 'title' => 'Açıklamayı güncelle',
            'reason' => self::line((string) ($raw['reason'] ?? '')) ?: 'Önerilen açıklama '.mb_strlen($description).' karakter.',
            'priority' => 2,
            'evidence' => [['mevcut_uzunluk' => mb_strlen($profile['description']), 'önerilen_uzunluk' => mb_strlen($description)]],
            'action_type' => 'gbp_description', 'action' => ['current' => $profile['description'], 'proposed' => $description],
            'prompt_version_id' => $versionId,
        ]]);

        return 'Açıklama önerisi hazır ('.mb_strlen($description).' karakter).';
    }

    public function postFromPage(DigitalAsset $asset, int $pageId): string
    {
        $page = $this->page($asset, $pageId) ?? throw new RuntimeException('Sayfa bulunamadı.');
        $brand = $asset->brand;
        $resourceId = app(GbpDailyWorkspace::class)->resource($asset)?->id;
        [$raw, $versionId] = $this->call(self::OP_POST, [
            'business' => $brand->name,
            'offerings' => array_column($this->offerings($brand), 'name'),
            'page' => ['title' => (string) ($page->title ?: $page->h1), 'category' => $page->category, 'summary' => (string) $page->content_summary,
                'text' => $page->aiText(6000)],
            'recent_posts' => $resourceId !== null ? DB::table('gbp_posts')->where('external_resource_id', $resourceId)->orderByDesc('create_time')->limit(5)
                ->pluck('summary')->map(fn ($s): string => mb_substr((string) $s, 0, 200))->all() : [],
            'compliance' => $this->complianceRules($brand),
        ]);
        $text = $this->checkedText($brand, (string) ($raw['text'] ?? ''), self::POST_MAX, 'Gönderi');
        $action = in_array($raw['action_type'] ?? null, ['LEARN_MORE', 'BOOK', 'CALL', 'SIGN_UP'], true) ? (string) $raw['action_type'] : 'LEARN_MORE';
        $this->archive->record(self::POST_KIND, $asset, ['body' => $text, 'action_type' => $action, 'url' => (string) $page->url, 'page_id' => (int) $page->id,
            'page_title' => (string) ($page->title ?: $page->path), 'prompt_version_id' => $versionId, 'prompt_version' => 'gbp-post-from-page'],
            ['brand_id' => $brand->id, 'digital_asset_id' => $asset->id, 'title' => 'Profil gönderisi · '.mb_substr((string) ($page->title ?: $page->path), 0, 80)]);

        return 'Gönderi taslağı hazır.';
    }

    /** Latest unused page-post draft of the last day. */
    public function latestPost(DigitalAsset $asset): ?AiProduction
    {
        $draft = $this->archive->fresh(self::POST_KIND, $asset, 1);

        return $draft !== null && $draft->status === AiProduction::STATUS_NEW ? $draft : null;
    }

    /**
     * Pages of the brand's site(s) that can be shared: service and blog pages (uncategorized ones until Faz 4 labels
     * them), indexable, newest change first.
     *
     * @return list<array{id: int, title: string, url: string, category: ?string, language: string}>
     */
    public function shareablePages(DigitalAsset $asset, int $limit = 200): array
    {
        if ($asset->brand_id === null) {
            return [];
        }

        return $this->pagesQuery($asset)->orderByRaw("CASE WHEN category = 'hizmet' THEN 0 WHEN category = 'blog' THEN 1 ELSE 2 END")
            ->orderByDesc('changed_at')->orderBy('id')->limit($limit)->get(['id', 'title', 'url', 'path', 'category', 'language'])
            ->map(fn (Page $p): array => ['id' => (int) $p->id, 'title' => (string) ($p->title ?: $p->path), 'url' => (string) $p->url, 'category' => $p->category, 'language' => strtolower((string) $p->language)])->all();
    }

    /**
     * Text checks shared by description and post: trimmed to the limit at a sentence end, no links / phones / e-mail,
     * no blocking sector-compliance hit.
     */
    private function checkedText(Brand $brand, string $text, int $max, string $what): string
    {
        $text = trim(preg_replace("/[ \t]+/u", ' ', strip_tags($text)) ?? '');
        if (mb_strlen($text) > $max) {
            $cut = mb_substr($text, 0, $max);
            $end = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, ".\n"), (int) mb_strrpos($cut, '! '), (int) mb_strrpos($cut, '? '));
            $text = $end > $max / 2 ? mb_substr($cut, 0, $end + 1) : '';
        }
        if (mb_strlen($text) < 40) {
            throw new RuntimeException('AI geçerli bir metin döndürmedi; tekrar deneyin.');
        }
        if (preg_match(self::CONTACT_PATTERN, $text) === 1) {
            throw new RuntimeException($what.' bağlantı, telefon ya da e-posta içeriyor; tekrar deneyin.');
        }
        $blocking = self::blockingHits($brand, $text);
        if ($blocking !== []) {
            throw new RuntimeException($what.' sektör uyum kuralına takıldı: '.implode(', ', $blocking).'. Tekrar deneyin.');
        }

        return $text;
    }

    /** @return list<string> «phrase» (rule) of the high / medium hits of the brand's sector rules */
    public static function blockingHits(?Brand $brand, string $text): array
    {
        $out = [];
        foreach (app(ComplianceAuditor::class)->checkForBrand($brand, $text, 'gbp') as $hit) {
            if (in_array($hit['rule']->severity, ['high', 'medium'], true)) {
                $out[] = '«'.$hit['matched'].'» ('.$hit['rule']->label.')';
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<mixed>, 1: ?int} structured response and the prompt version used
     */
    private function call(string $operation, array $data): array
    {
        $class = $this->agent($operation);
        $route = $this->routes->resolve($class::OPERATION);
        if ($route->isEmpty()) {
            throw new RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.');
        }
        $this->runtime->prepare(array_keys($route->providerModels));
        $agent = new $class;
        $response = (array) $agent->prompt("DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            provider: $route->providerModels, timeout: 120)->toArray();

        return [$response, $agent->promptVersionId()];
    }

    /** @return class-string<GbpServicesCompareAgent|GbpDescriptionAgent|GbpPostFromPageAgent|GbpProfilePlanAgent> */
    private function agent(string $operation): string
    {
        return self::OPERATIONS[$operation];
    }

    /** @return array{title: string, primary_category: string, additional_categories: list<string>, description: string, services: list<string>} */
    private function profile(DigitalAsset $asset): array
    {
        $resource = app(GbpDailyWorkspace::class)->resource($asset);
        $location = $resource !== null ? $this->input->location((int) $resource->id) : null;
        if ($location === null) {
            throw new RuntimeException('Profil verisi yok; önce İşletme Profili verisini çekin.');
        }

        return [
            'title' => $location['title'], 'primary_category' => $location['primary_category'], 'additional_categories' => $location['additional_categories'],
            'description' => $location['description'], 'services' => array_values(array_unique($this->input->services((int) $resource->id)['labels'])),
        ];
    }

    /** @return list<array{name: string, priority: string}> approved (active) offerings, main first */
    public function offerings(?Brand $brand): array
    {
        if ($brand === null) {
            return [];
        }

        return BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName'])->where('brand_id', $brand->id)->where('status', 'active')
            ->orderByRaw("CASE WHEN priority = 'main' THEN 0 ELSE 1 END")->orderBy('id')->get()
            ->map(fn (BrandOffering $o): array => ['name' => $o->displayName(), 'priority' => $o->priority === 'main' ? 'main' : 'secondary'])
            ->unique('name')->values()->all();
    }

    /** @return array<string, mixed> approved brand info from the brand memory profile rows */
    private function memory(Brand $brand): array
    {
        $out = [];
        foreach (BrandMemory::query()->where('brand_id', $brand->id)->where('kind', 'profile')->orderBy('id')->limit(10)->get() as $row) {
            $out[(string) ($row->ref_type ?: 'profile')] = array_filter(['summary' => $row->summary, 'data' => $row->data]);
        }

        return $out;
    }

    /**
     * The profile's own area from its address ("Çankaya, Ankara") and the districts of the brand's other profiles that
     * are not this one's (a description must not name them). The brand's service areas are this profile's own only
     * when the brand has a single profile.
     *
     * @return array{area: string, siblings: list<string>, single: bool}
     */
    private function place(DigitalAsset $asset): array
    {
        $desk = app(GbpDesk::class);
        $locations = $desk->locations((int) $asset->brand_id);
        $snapshots = $desk->snapshots($locations->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $address = (array) ($snapshots[$asset->id]['address'] ?? []);
        $own = array_filter([SeoText::fold((string) ($address['sublocality'] ?? '')), SeoText::fold((string) ($address['locality'] ?? ''))]);
        $siblings = [];
        foreach ($locations as $location) {
            $district = trim((string) ($snapshots[$location->id]['address']['sublocality'] ?? ''));
            if ((int) $location->id !== (int) $asset->id && $district !== '' && ! in_array(SeoText::fold($district), $own, true)) {
                $siblings[SeoText::fold($district)] = $district;
            }
        }

        return ['area' => (string) ($snapshots[$asset->id]['area'] ?? ''), 'siblings' => array_values($siblings), 'single' => $locations->count() <= 1];
    }

    /** @return list<array{name: string, physical_branch: bool}> */
    private function areas(Brand $brand): array
    {
        return BrandServiceArea::query()->where('brand_id', $brand->id)->orderByDesc('physical_branch')->orderBy('id')->limit(30)->get()
            ->map(fn (BrandServiceArea $a): array => ['name' => $a->displayName(), 'physical_branch' => (bool) $a->physical_branch])->all();
    }

    /** @return list<string> */
    private function complianceRules(Brand $brand): array
    {
        return app(SectorPackRegistry::class)->rulesForBrand($brand)->pluck('message')->unique()->values()->take(12)->all();
    }

    private function page(DigitalAsset $asset, int $pageId): ?Page
    {
        return $pageId > 0 && $asset->brand_id !== null ? $this->pagesQuery($asset)->whereKey($pageId)->first() : null;
    }

    /** @return Builder<Page> */
    private function pagesQuery(DigitalAsset $asset): Builder
    {
        $sites = DigitalAsset::query()->where('brand_id', $asset->brand_id)->where('type', 'website')->select('id');

        return Page::query()->whereIn('website_asset_id', $sites)->where('is_indexable', true)
            ->where(fn ($q) => $q->whereIn('category', ['hizmet', 'blog'])->orWhereNull('category'));
    }

    /** Advice to put keywords / places into the business name (suspension risk) is never shown. */
    private static function namesBusinessName(string $note): bool
    {
        $folded = SeoText::fold($note);

        return str_contains($folded, 'isletme adi') || str_contains($folded, 'isletme ismi') || str_contains($folded, 'firma adi') || str_contains($folded, 'business name');
    }

    private static function line(string $text): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? ''), 0, 240);
    }
}

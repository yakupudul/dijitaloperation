<?php

namespace App\Services\BrandSetup;

use App\Enums\OfferingStatus;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandOfferingName;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\User;
use App\Services\Brand\BrandGaps;
use App\Services\BrandIntelligence\BrandContextActivityRecorder;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Catalog\ServiceNameQuality;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\Analysis\SitePagesReader;
use App\Services\Site\SiteText;
use App\Support\BrandIntelligence\IdentityLabelNormalizer;
use App\Support\Options\LocationOptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Hizmet adları sitenin ana dilinde ve düzgün (yakup, 2026-10-12: Avrupadent's Turkish site listed "Aesthetic Dentistry",
 * "Orthodontics"…; Dentocare "/CAM Diş Tedavileri", "diş çekimi"; OPC's ★ "İmplant Tedavisi" linked only /de/ and /en/
 * pages). Part of the nightly language clean-up, rules only (no AI), on active services the operator has not locked:
 *
 * 1. Main-language page: a service whose every linked page is a translation gets the main-language page of that
 *    translation (Polylang / WPML link in the WordPress inventory; else the one main-language service page carrying the
 *    service's own name). The foreign links stay.
 * 2. Wrong-language name: a name no main-language page title carries, but a linked translated page does (or the linked
 *    catalog service's primary name is in the main language; an English-only name on a Turkish site as a last signal).
 *    When another active service already covers it (same catalog service, same name, or a shared main-language page
 *    carrying that service's name) it is archived as a duplicate and its own main-language links move over; otherwise it
 *    takes the catalog's main-language name or its main-language page's title. A ★ service is only ever renamed.
 * 3. Broken name: "/CAM …" is repaired from the catalog name or a page title containing it; an all-lowercase name gets
 *    its first letter capitalised (Turkish i → İ).
 *
 * Nothing is deleted. Every rename / archive is written to the brand's activity log as the automatic user.
 */
final class ServiceNameRepair
{
    /** English words a Turkish service name never carries (a secondary signal, Turkish sites only). */
    private const array ENGLISH_MARKERS = ['and', 'of', 'the', 'for', 'with', 'treatment', 'treatments', 'dentistry', 'dental', 'surgery',
        'therapy', 'care', 'aesthetic', 'cosmetic', 'restorative', 'diagnostic', 'oral', 'gum', 'tooth', 'teeth', 'sensitivity'];

    /** @var array<int, string> site id → main language */
    private array $mains = [];

    /** @var Collection<int, Page> */
    private Collection $pages;

    /** @var array<int, array<int, bool>> offering id → page id → locked */
    private array $links = [];

    /** @var array<int, array<int, int>> site id → translated object id → main-language object id */
    private array $translations = [];

    /** @var array<int, array<int, int>> site id → WordPress object id → page id */
    private array $pageByObject = [];

    /** @var array<int, array<string, int>> site id → page path → WordPress object id */
    private array $objectByPath = [];

    /** @var array<string, true>|null */
    private ?array $mainTitles = null;

    /** @var array<int, string> page id → cleaned title */
    private array $titles = [];

    private string $main = 'tr';

    private ?User $actor = null;

    public function __construct(
        private readonly BrandOfferingService $offerings,
        private readonly BrandContextActivityRecorder $activity,
        private readonly IdentityLabelNormalizer $normalizer,
    ) {}

    /**
     * @param  array<int, string>  $mains  site id → main language of the brand's websites (first one is the brand's)
     * @return array{linked: int, renamed: int, archived: int}
     */
    public function repair(Brand $brand, array $mains): array
    {
        $out = ['linked' => 0, 'renamed' => 0, 'archived' => 0];
        if ($mains === []) {
            return $out;
        }
        $this->mains = $mains;
        $this->main = (string) reset($mains);
        $this->actor ??= BrandAutofill::systemUser();
        $this->pages = Page::query()->whereIn('website_asset_id', array_keys($mains))
            ->get(['id', 'website_asset_id', 'url', 'path', 'language', 'title', 'h1', 'category', 'wp_post_id'])->keyBy('id');
        $this->mainTitles = null;
        $this->titles = [];
        $this->indexTranslations();
        $this->loadLinks($brand);

        foreach ($this->editable($brand) as $offering) {
            $out['linked'] += $this->linkMainLanguagePage($offering);
        }
        $this->loadLinks($brand);
        foreach ($this->editable($brand) as $offering) {
            $result = $this->fixLanguage($brand, $offering);
            if ($result !== null) {
                $out[$result]++;
            }
        }
        foreach ($this->editable($brand) as $offering) {
            if ($this->fixBrokenName($offering)) {
                $out['renamed']++;
            }
        }

        return $out;
    }

    /** @return Collection<int, BrandOffering> */
    private function editable(Brand $brand): Collection
    {
        return BrandOffering::query()->with(['primaryName', 'catalogItem.names'])->where('brand_id', $brand->id)
            ->where('status', OfferingStatus::Active->value)->where('locked', false)->orderBy('id')->get();
    }

    private function loadLinks(Brand $brand): void
    {
        $this->links = [];
        OfferingPage::query()->whereIn('brand_offering_id', BrandOffering::query()->where('brand_id', $brand->id)->where('status', OfferingStatus::Active->value)->select('id'))
            ->get(['brand_offering_id', 'page_id', 'locked'])
            ->each(function (OfferingPage $link): void {
                $this->links[(int) $link->brand_offering_id][(int) $link->page_id] = (bool) $link->locked;
            });
    }

    /** Rule 1: a service with only translated pages gets their main-language page. */
    private function linkMainLanguagePage(BrandOffering $offering): int
    {
        $linked = array_values(array_filter(array_map(fn (int $id): ?Page => $this->pages->get($id), array_keys($this->links[(int) $offering->id] ?? []))));
        if ($linked === [] || array_filter($linked, fn (Page $page): bool => ! $this->foreign($page)) !== []) {
            return 0;
        }
        $targets = [];
        foreach ($linked as $page) {
            if (($translation = $this->translationOf($page)) !== null) {
                $targets[$translation->id] = $translation;
            }
        }
        if ($targets === [] && ($page = $this->pageNamed($offering)) !== null) {
            $targets[$page->id] = $page;
        }
        foreach ($targets as $page) {
            OfferingPage::query()->firstOrCreate(['brand_offering_id' => $offering->id, 'page_id' => $page->id], ['source' => 'rule', 'locked' => false]);
        }

        return count($targets);
    }

    /**
     * Rule 2: a name in another language than the site's.
     *
     * @return 'renamed'|'archived'|null
     */
    private function fixLanguage(Brand $brand, BrandOffering $offering): ?string
    {
        $name = (string) $offering->primaryName?->raw_label;
        if ($name === '' || $this->onMainLanguagePage($name)) {
            return null;
        }
        $pages = array_values(array_filter(array_map(fn (int $id): ?Page => $this->pages->get($id), array_keys($this->links[(int) $offering->id] ?? []))));
        $foreignMatch = array_values(array_filter($pages, fn (Page $page): bool => $this->foreign($page) && $this->titleCarries($page, $name)));
        $catalogNames = $this->catalogMainNames($offering, $name);
        $primary = (string) $offering->catalogItem?->names->firstWhere('is_primary', true)?->raw_label;
        // The catalog's main-language name counts only for a name sharing none of its words and with no main-language
        // letters: "implant" under "İmplant Tedavisi" is a short form, not a translation.
        $catalogSignal = $primary !== '' && in_array($primary, $catalogNames, true) && SiteText::serviceScore($primary, $name) < 0.5
            && ($this->main !== 'tr' || preg_match('/[çğıöşüÇĞİÖŞÜ]/u', $name) !== 1);
        if ($foreignMatch === [] && ! $catalogSignal && ! $this->englishOnTurkishSite($name)) {
            return null;
        }

        // Main-language pages of the service: its own, and the translations of the pages carrying its foreign name first.
        $mainPages = [];
        foreach ([...$foreignMatch, ...$pages] as $page) {
            $candidate = $this->foreign($page) ? $this->translationOf($page) : $page;
            if ($candidate !== null) {
                $mainPages[$candidate->id] ??= $candidate;
            }
        }
        $names = [...$catalogNames];
        foreach ($mainPages as $page) {
            $names[] = $this->title($page);
        }
        $names = array_values(array_unique(array_filter($names, fn (string $label): bool => $label !== '' && SeoText::fold($label) !== SeoText::fold($name)
            && ServiceNameQuality::acceptable($label) && ! $this->englishOnTurkishSite($label))));

        $survivor = $this->coveringService($brand, $offering, $names, $mainPages);
        if ($survivor !== null && ! $offering->isMain()) {
            $this->archiveDuplicate($brand, $offering, $survivor, $name);

            return 'archived';
        }
        foreach ($names as $label) {
            if ($this->claimedElsewhere($offering, $label)) {
                continue;
            }

            return $this->rename($offering, $label) ? 'renamed' : null;
        }

        return null;
    }

    /** Rule 3: "/CAM Diş Tedavileri" → "CAD/CAM Diş Tedavileri"; "diş çekimi" → "Diş çekimi". */
    private function fixBrokenName(BrandOffering $offering): bool
    {
        $name = (string) $offering->primaryName?->raw_label;
        if ($name === '') {
            return false;
        }
        if (preg_match('/^[^\p{L}\p{N}]|[-–—|,&\/\'’]$/u', $name) === 1) {
            $core = trim($name, " \t-–—|,:;/&'’.");
            $candidates = $offering->catalogItem?->names->where('is_active', true)->pluck('raw_label')->map(fn ($l): string => (string) $l)->all() ?? [];
            $pages = collect(array_keys($this->links[(int) $offering->id] ?? []))->map(fn (int $id): ?Page => $this->pages->get($id))->filter()
                ->sortBy(fn (Page $page): int => $this->foreign($page) ? 1 : 0);
            foreach ($pages as $page) {
                $candidates[] = $this->title($page);
            }
            foreach ($candidates as $label) {
                if ($core !== '' && SeoText::fold($label) !== SeoText::fold($core) && SeoText::containsPhrase($label, $core)
                    && ServiceNameQuality::acceptable($label) && ! $this->claimedElsewhere($offering, $label)) {
                    return $this->rename($offering, $label);
                }
            }

            return false;
        }
        $capitalised = ServiceNameQuality::capitalised($name, $this->main);
        if ($capitalised === $name) {
            return false;
        }
        $catalogName = $offering->catalogItem?->names->first(fn ($n): bool => (bool) $n->is_primary && (string) $n->raw_label === $name);
        if ($catalogName !== null && $this->normalizer->normalize($capitalised) === (string) $catalogName->normalized_key) {
            $catalogName->forceFill(['raw_label' => $capitalised])->save();
        }

        return $this->rename($offering, $capitalised);
    }

    private function rename(BrandOffering $offering, string $label): bool
    {
        try {
            $this->offerings->renameLocal($offering, $label, $this->actor);
            $offering->unsetRelation('primaryName');

            return true;
        } catch (ValidationException) {
            return false;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Another active service the wrong-language one duplicates: same catalog service, a name it would take, or a shared
     * main-language page whose title carries that service's name.
     *
     * @param  list<string>  $names
     * @param  array<int, Page>  $mainPages
     */
    private function coveringService(Brand $brand, BrandOffering $offering, array $names, array $mainPages): ?BrandOffering
    {
        $others = BrandOffering::query()->with('primaryName')->where('brand_id', $brand->id)->where('status', OfferingStatus::Active->value)
            ->whereKeyNot($offering->id)->orderBy('id')->get();
        if ($offering->service_catalog_item_id !== null && ($same = $others->firstWhere('service_catalog_item_id', $offering->service_catalog_item_id)) !== null) {
            return $same;
        }
        $keys = array_map(fn (string $label): string => $this->normalizer->normalize($label), $names);
        foreach ($others as $other) {
            if ($other->primaryName !== null && in_array((string) $other->primaryName->normalized_key, $keys, true)) {
                return $other;
            }
        }
        foreach ($mainPages as $page) {
            foreach ($others as $other) {
                if (isset($this->links[(int) $other->id][(int) $page->id]) && $this->titleCarries($page, $other->displayName())) {
                    return $other;
                }
            }
        }

        return null;
    }

    /** Archives the duplicate; its unlocked main-language links the survivor lacks move over (foreign ones stay). */
    private function archiveDuplicate(Brand $brand, BrandOffering $offering, BrandOffering $survivor, string $name): void
    {
        DB::transaction(function () use ($brand, $offering, $survivor, $name): void {
            $moved = [];
            foreach ($this->links[(int) $offering->id] ?? [] as $pageId => $locked) {
                $page = $this->pages->get($pageId);
                if ($locked || $page === null || $this->foreign($page) || isset($this->links[(int) $survivor->id][$pageId])) {
                    continue;
                }
                OfferingPage::query()->where('brand_offering_id', $offering->id)->where('page_id', $pageId)->update(['brand_offering_id' => $survivor->id]);
                $this->links[(int) $survivor->id][$pageId] = false;
                unset($this->links[(int) $offering->id][$pageId]);
                $moved[] = $pageId;
            }
            $offering->forceFill(['status' => OfferingStatus::Archived->value, 'priority_rank' => null])->save();
            $this->activity->record($brand, 'OFFERING_ARCHIVED', BrandOffering::class, (int) $offering->id,
                ['reason' => 'language_duplicate', 'name' => $name, 'kept_offering_id' => (int) $survivor->id, 'moved_page_ids' => $moved], $this->actor);
        });
    }

    /** The name is taken by another service of the brand (the name claim is unique per brand). */
    private function claimedElsewhere(BrandOffering $offering, string $label): bool
    {
        return BrandOfferingName::query()->where('brand_id', $offering->brand_id)->where('normalized_key', $this->normalizer->normalize($label))
            ->where('brand_offering_id', '!=', $offering->id)->exists();
    }

    /**
     * Catalog names of the service that are in the site's main language (locale, a main-language page with that title,
     * or Turkish letters on a Turkish site), primary first.
     *
     * @return list<string>
     */
    private function catalogMainNames(BrandOffering $offering, string $name): array
    {
        $titles = $this->mainTitles();
        $out = [];
        foreach ($offering->catalogItem?->names->where('is_active', true)->sortByDesc('is_primary') ?? [] as $catalogName) {
            $label = trim((string) $catalogName->raw_label);
            if ($label === '' || SeoText::fold($label) === SeoText::fold($name)) {
                continue;
            }
            $locale = strtolower(substr((string) $catalogName->locale, 0, 2));
            if ($locale === $this->main || isset($titles[SeoText::fold($label)])
                || ($this->main === 'tr' && preg_match('/[çğıöşüÇĞİÖŞÜ]/u', $label) === 1 && ! $this->englishOnTurkishSite($label))) {
                $out[] = $label;
            }
        }

        return $out;
    }

    /** @return array<string, true> folded cleaned titles of the main-language pages */
    private function mainTitles(): array
    {
        if ($this->mainTitles === null) {
            $this->mainTitles = [];
            foreach ($this->pages as $page) {
                if (! $this->foreign($page)) {
                    $this->mainTitles[SeoText::fold($this->title($page))] = true;
                }
            }
        }

        return $this->mainTitles;
    }

    /** Some main-language page's title carries every distinctive word of the name: the name is the site's own. */
    private function onMainLanguagePage(string $name): bool
    {
        return $this->pages->contains(fn (Page $page): bool => ! $this->foreign($page) && $this->titleCarries($page, $name));
    }

    private function titleCarries(Page $page, string $name): bool
    {
        $title = $this->title($page);
        if ($title !== '' && SeoText::fold($title) === SeoText::fold($name)) {
            return true;
        }

        return SiteText::serviceScore(implode(' ', array_filter([(string) $page->title, (string) $page->h1, (string) SitePagesReader::slugTitle((string) $page->path)])), $name) >= 0.99;
    }

    private function englishOnTurkishSite(string $name): bool
    {
        if ($this->main !== 'tr' || preg_match('/[çğıöşüÇĞİÖŞÜ]/u', $name) === 1) {
            return false;
        }

        return array_intersect(explode(' ', SeoText::fold($name)), self::ENGLISH_MARKERS) !== [];
    }

    /** The one main-language service page whose title is the service's name (exact, else the only page carrying it). */
    private function pageNamed(BrandOffering $offering): ?Page
    {
        $names = array_values(array_filter([(string) $offering->primaryName?->raw_label, ...$this->catalogMainNames($offering, '')]));
        $taken = [];
        foreach ($this->links as $offeringId => $pages) {
            if ($offeringId !== (int) $offering->id) {
                $taken += $pages;
            }
        }
        $candidates = $this->pages->filter(fn (Page $page): bool => ! $this->foreign($page) && ! isset($taken[(int) $page->id])
            && ($page->category === 'hizmet' || SitePagesReader::pathCategory((string) $page->path) === 'hizmet'));
        foreach ($names as $name) {
            $exact = $candidates->first(fn (Page $page): bool => SeoText::fold($this->title($page)) === SeoText::fold($name));
            if ($exact !== null) {
                return $exact;
            }
        }
        foreach ($names as $name) {
            $carrying = $candidates->filter(fn (Page $page): bool => SiteText::serviceScore($this->title($page), $name) >= 0.99);
            if ($carrying->count() === 1) {
                return $carrying->first();
            }
        }

        return null;
    }

    /** "Kadıköy Tek Diş İmplant Tedavisi | OPC" → "Tek Diş İmplant Tedavisi". */
    private function title(Page $page): string
    {
        if (! isset($this->titles[(int) $page->id])) {
            $title = BrandGaps::serviceTitle((string) ($page->h1 ?: $page->title ?: SitePagesReader::slugTitle((string) $page->path)));
            $clean = trim(LocationOptions::stripServiceName($title)['text']);
            $this->titles[(int) $page->id] = $clean !== '' ? $clean : $title;
        }

        return $this->titles[(int) $page->id];
    }

    private function foreign(Page $page): bool
    {
        $main = $this->mains[(int) $page->website_asset_id] ?? null;

        return $main !== null && LanguageServices::foreign($page->language, (string) ($page->path ?: $page->url), $main);
    }

    /** The main-language page a translated page is a version of (Polylang / WPML translation link in the WordPress inventory). */
    private function translationOf(Page $page): ?Page
    {
        $site = (int) $page->website_asset_id;
        $objectId = $page->wp_post_id !== null ? (int) $page->wp_post_id : ($this->objectByPath[$site][SeoText::urlPath((string) ($page->path ?: $page->url))] ?? null);
        if ($objectId === null) {
            return null;
        }
        $mainObject = $this->translations[$site][$objectId] ?? null;
        $pageId = $mainObject !== null ? ($this->pageByObject[$site][$mainObject] ?? null) : null;
        $target = $pageId !== null ? $this->pages->get($pageId) : null;

        return $target !== null && ! $this->foreign($target) ? $target : null;
    }

    /** WordPress inventory rows carry `metadata.language` and `metadata.translations` ({lang: object id}). */
    private function indexTranslations(): void
    {
        $this->translations = [];
        $this->pageByObject = [];
        $this->objectByPath = [];
        foreach ($this->pages as $page) {
            if ($page->wp_post_id !== null) {
                $this->pageByObject[(int) $page->website_asset_id][(int) $page->wp_post_id] = (int) $page->id;
            }
        }
        if (! Schema::hasTable('website_cms_object_snapshot')) {
            return;
        }
        $rows = DB::table('website_cms_object_snapshot')->whereIn('digital_asset_id', array_keys($this->mains))->where('cms', 'wordpress')
            ->where('object_type', '!=', 'attachment')->whereNotNull('metadata')->orderByDesc('observed_at')->get(['digital_asset_id', 'object_id', 'permalink', 'metadata']);
        $paths = [];
        foreach ($this->pages as $page) {
            $paths[(int) $page->website_asset_id][SeoText::urlPath((string) ($page->path ?: $page->url))] = (int) $page->id;
        }
        foreach ($rows as $row) {
            $site = (int) $row->digital_asset_id;
            $object = (int) $row->object_id;
            $path = SeoText::urlPath((string) $row->permalink);
            if (isset($paths[$site][$path])) {
                $this->objectByPath[$site][$path] ??= $object;
                $this->pageByObject[$site][$object] ??= $paths[$site][$path];
            }
            $meta = json_decode((string) $row->metadata, true);
            $translations = is_array($meta['translations'] ?? null) ? $meta['translations'] : [];
            $language = strtolower(substr((string) ($meta['language'] ?? ''), 0, 2));
            $main = $this->mains[$site] ?? null;
            $byLanguage = [];
            foreach ($translations as $lang => $id) {
                if (is_numeric($id) && (int) $id > 0) {
                    $byLanguage[strtolower(substr((string) $lang, 0, 2))] = (int) $id;
                }
            }
            if ($language !== '') {
                $byLanguage[$language] = $object;
            }
            if ($main === null || ! isset($byLanguage[$main])) {
                continue;
            }
            foreach ($byLanguage as $lang => $id) {
                if ($lang !== $main) {
                    $this->translations[$site][$id] ??= $byLanguage[$main];
                }
            }
        }
    }
}

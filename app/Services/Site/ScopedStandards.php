<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\StandardFromDecisionAgent;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\SeoTasks\SeoText;
use App\Support\Roles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MoxDop\Website\Standards\UrlStandardEvaluator;
use MoxDop\Website\Standards\WebsiteStandardCatalog;
use Throwable;

/**
 * "Bu karardan standart öner": an approved suggestion → AI proposes a standard (title, rule, condition, exceptions,
 * scope URL / marka / sektör / genel) → the operator edits and approves → a versioned row in the standards library
 * (`website_standard_settings`, id `website:decision:*`). Scoped standards apply only where the scope matches.
 * Also the URL standards results of one page for the URL analysis pack (existing evaluator, stored page fields).
 */
final class ScopedStandards
{
    public const array SCOPES = ['url', 'brand', 'sector', 'general'];

    public const array SCOPE_LABELS = ['url' => 'URL', 'brand' => 'marka', 'sector' => 'sektör', 'general' => 'genel'];

    /** Previous versions kept per decision standard. */
    public const int HISTORY_LIMIT = 20;

    public function __construct(
        private readonly SiteAi $ai,
        private readonly WebsiteStandardCatalog $catalog,
    ) {}

    /** @return array{status: string} status: ready | not_approved | not_operational | no_provider | error | invalid */
    public function propose(Suggestion $suggestion): array
    {
        if (! in_array($suggestion->status, [Suggestion::APPROVED, Suggestion::APPLIED], true)) {
            return ['status' => 'not_approved'];
        }
        $brand = Brand::query()->with('customer', 'sectorCategory')->find($suggestion->brand_id);
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational'];
        }
        $page = $suggestion->page_id !== null ? Page::query()->find($suggestion->page_id, ['id', 'url', 'title', 'category']) : null;
        $result = $this->ai->run(new StandardFromDecisionAgent, [
            'decision' => ['type' => $suggestion->action_type, 'title' => $suggestion->title, 'reason' => $suggestion->reason, 'operator_note' => $suggestion->operator_note],
            'page' => $page !== null ? ['url' => $page->url, 'title' => $page->title, 'category' => $page->category] : null,
            'brand' => ['name' => $brand->name, 'sector' => $brand->sectorCategory?->name],
            'scopes' => self::SCOPE_LABELS,
        ]);
        if ($result['status'] !== 'ready') {
            return ['status' => $result['status']];
        }
        $data = $result['data'];
        $draft = [
            'title' => mb_substr(trim((string) ($data['title'] ?? '')), 0, 160), 'rule' => mb_substr(trim((string) ($data['rule'] ?? '')), 0, 1000),
            'condition' => mb_substr(trim((string) ($data['condition'] ?? '')), 0, 500), 'exceptions' => mb_substr(trim((string) ($data['exceptions'] ?? '')), 0, 500),
            'scope' => in_array($data['scope'] ?? null, self::SCOPES, true) ? $data['scope'] : 'brand',
        ];
        $evidence = new SiteEvidence($page !== null ? [(string) $page->url] : []);
        if (mb_strlen($draft['title']) < 3 || mb_strlen($draft['rule']) < 10 || ! $evidence->grounded($draft['rule'].' '.$draft['condition'].' '.$draft['exceptions'])) {
            return ['status' => 'invalid'];
        }
        if ($draft['scope'] === 'url' && $page === null) {
            $draft['scope'] = 'brand';
        }
        $suggestion->forceFill(['action' => array_merge((array) $suggestion->action, ['standard_draft' => $draft]), 'prompt_version_id' => $suggestion->prompt_version_id])->save();

        return ['status' => 'ready'];
    }

    /**
     * The operator's (edited) standard, saved as version 1 of a new library row with its scope.
     *
     * @param  array{title: string, rule: string, condition?: string, exceptions?: string, scope: string}  $fields
     */
    public function save(Suggestion $suggestion, array $fields, User $user): string
    {
        $this->authorize($user);
        $fields = $this->validated($fields);
        $brand = Brand::query()->find($suggestion->brand_id);
        $scopeId = match ($fields['scope']) {
            'url' => $suggestion->page_id !== null ? (int) $suggestion->page_id : throw ValidationException::withMessages(['scope' => 'Bu önerinin URL’si yok; marka ya da sektör seçin.']),
            'brand' => (int) $suggestion->brand_id,
            'sector' => $brand?->sector_id !== null ? (int) $brand->sector_id : throw ValidationException::withMessages(['scope' => 'Markanın sektörü yok.']),
            default => null,
        };
        $id = WebsiteStandardCatalog::DECISION_PREFIX.Str::uuid();
        DB::table('website_standard_settings')->insert([
            'standard_id' => $id, 'enabled' => true, 'custom_definition' => json_encode($this->definition($id, $fields, 1), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'scope_type' => $fields['scope'], 'scope_id' => $scopeId, 'version' => 1, 'created_from_suggestion_id' => $suggestion->id,
            'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $action = (array) $suggestion->action;
        unset($action['standard_draft']);
        $suggestion->forceFill(['action' => $action + ['standard_id' => $id]])->save();

        return $id;
    }

    /**
     * New version of a decision standard (the scope stays); the previous versions are kept in its history.
     *
     * @param  array{title: string, rule: string, condition?: string, exceptions?: string, scope?: string}  $fields
     */
    public function update(string $id, array $fields, User $user): int
    {
        $this->authorize($user);
        $row = DB::table('website_standard_settings')->where('standard_id', $id)->first();
        if ($row === null || ! str_starts_with($id, WebsiteStandardCatalog::DECISION_PREFIX)) {
            throw ValidationException::withMessages(['standard' => 'Standart bulunamadı.']);
        }
        $fields = $this->validated(['scope' => (string) $row->scope_type] + $fields);
        $fields['scope'] = (string) $row->scope_type;
        $previous = (array) json_decode((string) $row->custom_definition, true);
        $history = array_values((array) ($previous['history'] ?? []));
        array_unshift($history, [
            'version' => (int) $row->version, 'title' => (string) ($previous['title'] ?? ''), 'rule' => (string) ($previous['rule'] ?? ''),
            'condition' => (string) ($previous['condition'] ?? ''), 'exceptions' => (string) ($previous['exceptions'] ?? ''),
            'at' => (string) ($row->updated_at ?? ''),
        ]);
        $version = (int) $row->version + 1;
        DB::table('website_standard_settings')->where('standard_id', $id)->update([
            'custom_definition' => json_encode($this->definition($id, $fields, $version) + ['history' => array_slice($history, 0, self::HISTORY_LIMIT)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'version' => $version, 'updated_by' => $user->id, 'updated_at' => now(),
        ]);

        return $version;
    }

    /**
     * Decision standards that apply to this brand / these pages (the scope matches), for AI packs.
     *
     * @param  list<int>  $pageIds
     * @return list<array{id: string, title: string, rule: string, condition: string, exceptions: string, scope: string, version: int}>
     */
    public function forContext(Brand $brand, array $pageIds): array
    {
        $decisions = array_filter($this->catalog->all(true), fn (array $d): bool => str_starts_with((string) $d['id'], WebsiteStandardCatalog::DECISION_PREFIX));
        $applicable = WebsiteStandardCatalog::applicable($decisions, (int) $brand->id, $brand->sector_id !== null ? (int) $brand->sector_id : null, $pageIds);

        return array_values(array_map(fn (array $d): array => [
            'id' => (string) $d['id'], 'title' => (string) $d['title'], 'rule' => (string) ($d['rule'] ?? $d['action'] ?? ''),
            'condition' => (string) ($d['condition'] ?? ''), 'exceptions' => (string) ($d['exceptions'] ?? ''),
            'scope' => (string) ($d['scope_type'] ?? 'general'), 'version' => (int) ($d['version'] ?? 1),
        ], $applicable));
    }

    /**
     * Failing / to-review URL standards of one page from its stored fields (title, H1, word count, category, language):
     * the library's URL evaluator over the whole site's page records. Stored HTML signals are not used (unknown there).
     *
     * @return list<array{title: string, state: string, finding: string}>
     */
    public function pageChecks(DigitalAsset $site, Page $page, Brand $brand): array
    {
        try {
            $standards = array_filter($this->catalog->forAssetType('website'), fn (array $d): bool => str_starts_with((string) $d['method'], 'url_'));
            $records = [];
            Page::query()->where('website_asset_id', $site->id)->orderBy('id')->limit(2000)
                ->get(['id', 'url', 'path', 'title', 'h1', 'category', 'language', 'word_count', 'is_indexable', 'wp_post_type', 'changed_at', 'canonical'])
                ->each(function (Page $p) use (&$records): void {
                    $key = SeoText::urlKey((string) $p->url);
                    $kind = match (true) {
                        $p->path === '/' || $p->path === '' => 'home',
                        $p->category === 'blog' || $p->wp_post_type === 'post' => 'post',
                        in_array($p->category, ['hizmet', 'lokasyon'], true) => 'service',
                        $p->category === 'kurumsal' => 'about',
                        default => 'page',
                    };
                    $records[$key] = [
                        'key' => $key, 'url' => (string) $p->url, 'path' => (string) $p->path, 'kind' => $kind, 'title' => $p->title, 'h1' => $p->h1,
                        'indexable' => (bool) $p->is_indexable, 'noindex' => ! $p->is_indexable, 'word_count' => (int) $p->word_count, 'signals' => null,
                        'canonical_key' => $p->canonical !== null ? SeoText::urlKey((string) $p->canonical) : $key,
                        'wp_language' => $p->language, 'wp_translations' => [], 'status_code' => null, 'clicks' => null, 'clicks_prev' => null, 'impressions' => null,
                        'impr_90' => null, 'modified_at' => $p->changed_at?->toIso8601String(), 'in_sitemap' => null, 'inlinks' => 0, 'robots' => null,
                        'is_service' => $kind === 'service', 'is_priority_service' => false, 'inspection' => null, 'cannibal' => null,
                    ];
                });
            $key = SeoText::urlKey((string) $page->url);
            if (! isset($records[$key])) {
                return [];
            }
            $sector = (string) ($brand->sectorCategory?->code ?? '');
            $result = (new UrlStandardEvaluator)->evaluate($standards, $records, [
                'health' => in_array($sector, ['dental', 'health', 'saglik', 'medical'], true), 'ymyl' => in_array($sector, ['dental', 'health', 'saglik', 'medical', 'legal', 'finance'], true),
                'sitemap_known' => false, 'link_graph' => false, 'cannibalization_known' => false, 'now' => time(), 'thin_words' => 300,
                'locations' => SiteScope::areaWords($brand), 'modifiers' => [], 'home_key' => SeoText::urlKey(SiteScope::origin($site)),
            ]);
            $out = [];
            foreach ((array) ($result['pages'][$key] ?? []) as $id => $check) {
                if (in_array($check['state'] ?? null, ['fail', 'review'], true)) {
                    $out[] = ['title' => (string) ($standards[$id]['title'] ?? $id), 'state' => (string) $check['state'], 'finding' => (string) $check['finding']];
                }
            }

            return array_slice($out, 0, 15);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{title: string, rule: string, condition: string, exceptions: string, scope: string}
     */
    private function validated(array $fields): array
    {
        $out = [
            'title' => trim((string) ($fields['title'] ?? '')), 'rule' => trim((string) ($fields['rule'] ?? '')),
            'condition' => trim((string) ($fields['condition'] ?? '')), 'exceptions' => trim((string) ($fields['exceptions'] ?? '')),
            'scope' => (string) ($fields['scope'] ?? ''),
        ];
        if (mb_strlen($out['title']) < 3 || mb_strlen($out['title']) > 160 || mb_strlen($out['rule']) < 10 || mb_strlen($out['rule']) > 1000 || ! in_array($out['scope'], self::SCOPES, true)) {
            throw ValidationException::withMessages(['standard' => 'Başlık (3–160), kural (10–1000 karakter) ve kapsam gerekli.']);
        }

        return $out;
    }

    /**
     * @param  array{title: string, rule: string, condition: string, exceptions: string, scope: string}  $fields
     * @return array<string, mixed>
     */
    private function definition(string $id, array $fields, int $version): array
    {
        return [
            'id' => $id, 'version' => $version, 'enabled' => true, 'title' => $fields['title'], 'group' => 'content', 'method' => 'decision_rule',
            'classification' => 'agency_practice', 'applicability' => 'content_page', 'required_evidence' => ['decision'], 'asset_type' => 'website', 'platform' => 'general',
            'criterion' => 'Kapsam: '.self::SCOPE_LABELS[$fields['scope']].($fields['condition'] !== '' ? ' · Koşul: '.$fields['condition'] : ''),
            'rule' => $fields['rule'], 'condition' => $fields['condition'], 'exceptions' => $fields['exceptions'], 'action' => $fields['rule'],
            'verification' => $fields['exceptions'] !== '' ? 'İstisnalar: '.$fields['exceptions'] : 'İstisna yok.',
            'source_url' => null, 'source_reviewed_at' => now()->toDateString(), 'severity' => 'medium',
        ];
    }

    private function authorize(User $user): void
    {
        if (! $user->is_active || ! $user->hasRole(Roles::ADMIN)) {
            abort(403, 'Standartları yalnız Admin kaydedebilir.');
        }
    }
}

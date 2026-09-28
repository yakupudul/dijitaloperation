<?php

namespace App\Services\ContentStudio;

use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\ContentIdea;
use App\Models\DigitalAsset;
use App\Models\TopicCluster;
use App\Models\User;
use App\Services\SeoTasks\SeoText;
use App\Services\SeoTasks\SiteUrlPattern;
use App\Support\ServiceScope;
use Illuminate\Validation\ValidationException;

/**
 * Faz 4 — concrete article ideas: title, focus keyword, target queries, page type, target URL (the site's own URL
 * pattern), H2 / H3 outline, FAQ questions and internal links to real existing URLs (the service page of the offering
 * and 1–2 related posts from the inventory). Every proposed phrase passes the sector rules. A topic the site already
 * wrote (title / H1 / slug similarity ≥ threshold over the whole inventory) is never proposed; a close one is shown as
 * "benzer mevcut yazı".
 *
 * Sources: uncovered (and weak informational) topic clusters, brand service areas × services with no page (location,
 * one per service × area — no doorway pages), AI gap ideation (ContentIdeaAi) and the operator.
 */
final class ContentIdeaPlanner
{
    private ?SiteContentInventory $inventory = null;

    private ?BriefCompliance $compliance = null;

    /** @var array<int, array{name: string, names: list<string>}> */
    private array $services = [];

    /**
     * Ideas from the topic map (and service areas). Existing ideas are kept (operator edits win); new ones are added.
     *
     * @param  list<int>|null  $offeringIds  null = every service
     * @return array{created: int, skipped_existing: int, ideas: list<ContentIdea>}
     */
    public function fromClusters(DigitalAsset $site, ?array $offeringIds = null, int $limit = 60, bool $withLocations = true): array
    {
        $this->boot($site);
        $clusters = TopicCluster::query()->where('digital_asset_id', $site->id)->where('status', 'active')
            ->when($offeringIds !== null, fn ($q) => $q->whereIn('brand_offering_id', $offeringIds ?: [0]))
            ->where(fn ($q) => $q->where('verdict', 'new')->orWhere(fn ($q) => $q->where('verdict', 'strengthen')->where('intent', 'informational')->where('query_count', '>=', 3)))
            ->orderByDesc('demand_score')->limit(max(1, $limit) * 3)->get();
        $created = 0;
        $skipped = 0;
        $ideas = [];
        foreach ($clusters as $cluster) {
            if (count($ideas) >= $limit) {
                break;
            }
            if ($cluster->verdict === 'strengthen' && ($this->inventory->byUrl((string) $cluster->owner_url)['kind'] ?? null) === 'post') {
                continue; // a weak blog post is strengthened, not written again
            }
            $result = $this->ideaForCluster($cluster);
            if ($result['idea'] === null) {
                $skipped += $result['existing'] ? 1 : 0;

                continue;
            }
            $created += $result['created'] ? 1 : 0;
            $ideas[] = $result['idea'];
        }
        if ($withLocations && count($ideas) < $limit) {
            foreach ($this->locationIdeas($site, $offeringIds, min($limit - count($ideas), (int) config('moxdop-content.ideas.location_ideas_per_run', 6))) as $idea) {
                $created += $idea->wasRecentlyCreated ? 1 : 0;
                $ideas[] = $idea;
            }
        }

        return ['created' => $created, 'skipped_existing' => $skipped, 'ideas' => $ideas];
    }

    /**
     * The idea of one cluster (found or created). `existing` = the topic is already written on the site.
     *
     * @return array{idea: ?ContentIdea, created: bool, existing: bool}
     */
    public function ideaForCluster(TopicCluster $cluster): array
    {
        $site = $cluster->digitalAsset()->firstOrFail();
        $this->boot($site);
        $key = hash('sha256', 'cluster|'.$cluster->id);
        $found = ContentIdea::query()->where('digital_asset_id', $site->id)->where('idea_key', $key)->first();
        if ($found !== null) {
            return ['idea' => $found->status === 'removed' ? null : $found, 'created' => false, 'existing' => false];
        }
        $service = $this->services[(int) $cluster->brand_offering_id] ?? null;
        $serviceName = $service['name'] ?? $cluster->label;
        $members = $cluster->queries()->limit(30)->get();
        $queries = $members->pluck('query')->map(fn ($q): string => (string) $q)->all();
        $type = in_array($cluster->page_type, ['service', 'guide', 'faq', 'comparison', 'location'], true) ? $cluster->page_type : 'guide';
        $focus = $this->compliance->topic((string) ($cluster->head_query ?: $cluster->label), $serviceName);
        $draft = $this->compose($site, $type, $focus, $serviceName, $service['names'] ?? [$serviceName], $cluster->brand_offering_id, $queries, null);
        if ($draft === null) {
            return ['idea' => null, 'created' => false, 'existing' => true];
        }

        $idea = ContentIdea::query()->create($draft + [
            'brand_id' => $site->brand_id, 'digital_asset_id' => $site->id, 'topic_cluster_id' => $cluster->id, 'brand_offering_id' => $cluster->brand_offering_id,
            'idea_key' => $key, 'source' => 'cluster', 'status' => 'open', 'demand_score' => (float) $cluster->demand_score, 'sort' => $this->nextSort($site),
        ]);

        return ['idea' => $idea, 'created' => true, 'existing' => false];
    }

    /**
     * Location ideas from the BRAND's service areas × services — never from query records. At most one per service ×
     * area, only for areas the brand serves and only when no existing page already names both (no doorway pages).
     *
     * @param  list<int>|null  $offeringIds
     * @return list<ContentIdea>
     */
    public function locationIdeas(DigitalAsset $site, ?array $offeringIds, int $limit): array
    {
        $this->boot($site);
        if ($limit < 1) {
            return [];
        }
        $areas = $this->areas($site->brand);
        if ($areas === []) {
            return [];
        }
        $out = [];
        foreach ($this->orderedServices($site->brand, $offeringIds) as $offeringId => $service) {
            foreach ($areas as $area) {
                if (count($out) >= $limit) {
                    return $out;
                }
                $key = hash('sha256', 'location|'.$offeringId.'|'.SeoText::fold($area));
                $found = ContentIdea::query()->where('digital_asset_id', $site->id)->where('idea_key', $key)->first();
                if ($found !== null) {
                    if ($found->status !== 'removed') {
                        $out[] = $found;
                    }

                    continue;
                }
                if ($this->areaCovered($area, $service['names'])) {
                    continue;
                }
                $draft = $this->compose($site, 'location', TopicText::lower($area.' '.$service['name']), $service['name'], $service['names'], $offeringId, [], $area);
                if ($draft === null) {
                    continue;
                }
                $out[] = ContentIdea::query()->create($draft + [
                    'brand_id' => $site->brand_id, 'digital_asset_id' => $site->id, 'brand_offering_id' => $offeringId, 'idea_key' => $key,
                    'source' => 'location', 'status' => 'open', 'location' => mb_substr($area, 0, 160), 'sort' => $this->nextSort($site),
                ]);
            }
        }

        return $out;
    }

    /**
     * Store AI gap ideas: kept only when the service is one of the brand's, the title is compliant and the topic is
     * not written yet (site inventory) nor already an idea. Internal links and target URL are computed here.
     *
     * @param  list<array<string, mixed>>  $raw
     * @return list<ContentIdea>
     */
    public function storeAiIdeas(DigitalAsset $site, array $raw, ?User $user = null, int $limit = PHP_INT_MAX): array
    {
        $this->boot($site);
        $out = [];
        foreach ($raw as $row) {
            if (count($out) >= $limit) {
                break;
            }
            $title = mb_substr(trim(strip_tags((string) ($row['title'] ?? ''))), 0, 200);
            if ($title === '' || ! $this->compliance->isCompliant($title)) {
                continue;
            }
            $offeringId = $this->offeringByName((string) ($row['service'] ?? ''));
            $service = $offeringId !== null ? $this->services[$offeringId] : null;
            $type = in_array($row['page_type'] ?? null, ['guide', 'faq', 'comparison'], true) ? (string) $row['page_type'] : 'guide';
            $focus = $this->compliance->topic(trim((string) ($row['focus_keyword'] ?? '')) ?: $title, $service['name'] ?? $title);
            $key = hash('sha256', 'ai|'.SeoText::fold($title));
            if (ContentIdea::query()->where('digital_asset_id', $site->id)->where('idea_key', $key)->exists()) {
                continue;
            }
            $draft = $this->compose($site, $type, $focus, $service['name'] ?? $focus, $service['names'] ?? [], $offeringId,
                array_values(array_filter(array_map('strval', (array) ($row['target_queries'] ?? [])))), null, $title,
                $this->cleanOutline((array) ($row['outline'] ?? [])), $this->compliance->filter(array_map(fn ($q): string => $this->question((string) $q), array_slice((array) ($row['faq'] ?? []), 0, 5))));
            if ($draft === null) {
                continue;
            }
            $out[] = ContentIdea::query()->create($draft + [
                'brand_id' => $site->brand_id, 'digital_asset_id' => $site->id, 'brand_offering_id' => $offeringId, 'idea_key' => $key,
                'source' => 'ai', 'status' => 'open', 'sort' => $this->nextSort($site), 'created_by' => $user?->id,
            ]);
        }

        return $out;
    }

    /** Operator: "Fikir ekle" — a title and (optionally) a service; the rest is filled like any idea. */
    public function addManual(DigitalAsset $site, string $title, ?int $offeringId, ?User $user): ContentIdea
    {
        app(ServiceScope::class)->ensureAssetServed($site, 'idea');
        $this->boot($site);
        $title = mb_substr(trim(strip_tags($title)), 0, 200);
        if ($title === '') {
            throw ValidationException::withMessages(['idea' => 'Başlık boş olamaz.']);
        }
        if ($offeringId !== null && ! isset($this->services[$offeringId])) {
            throw ValidationException::withMessages(['idea' => 'Hizmet bu markaya ait değil.']);
        }
        $service = $offeringId !== null ? $this->services[$offeringId] : null;
        $draft = $this->compose($site, SeoText::looksLikeQuestion($title) ? 'guide' : 'guide', $this->compliance->topic($title, $service['name'] ?? $title), $service['name'] ?? $title,
            $service['names'] ?? [], $offeringId, [], null, $title, [], [], true);

        return ContentIdea::query()->updateOrCreate(['digital_asset_id' => $site->id, 'idea_key' => hash('sha256', 'op|'.SeoText::fold($title))], $draft + [
            'brand_id' => $site->brand_id, 'brand_offering_id' => $offeringId, 'source' => 'operator', 'status' => 'open', 'sort' => $this->nextSort($site), 'created_by' => $user?->id,
        ]);
    }

    /** Operator edit of an idea's title / focus keyword; internal links and target URL follow. */
    public function update(ContentIdea $idea, string $title, string $focus): ContentIdea
    {
        $site = DigitalAsset::query()->findOrFail($idea->digital_asset_id);
        $this->boot($site);
        $title = mb_substr(trim(strip_tags($title)), 0, 200);
        if ($title === '') {
            throw ValidationException::withMessages(['idea' => 'Başlık boş olamaz.']);
        }
        $service = $idea->brand_offering_id !== null ? ($this->services[(int) $idea->brand_offering_id] ?? null) : null;
        $similar = $this->inventory->similar($title, (float) config('moxdop-content.ideas.similar_title_threshold', 0.5));
        $idea->forceFill([
            'title' => $title,
            'focus_keyword' => mb_substr(trim(strip_tags($focus)) ?: $idea->focus_keyword, 0, 200),
            'similar_existing' => $similar,
            'internal_links' => $this->links($idea->brand_offering_id, $service['names'] ?? [], $title, (string) $idea->target_url),
        ])->save();

        return $idea;
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $queries
     * @param  list<array{h2: string, h3: list<string>}>  $outline
     * @param  list<string>  $faq
     * @return array<string, mixed>|null null when the topic is already written on the site
     */
    private function compose(DigitalAsset $site, string $type, string $focus, string $serviceName, array $names, ?int $offeringId, array $queries, ?string $area,
        ?string $title = null, array $outline = [], array $faq = [], bool $force = false): ?array
    {
        $serviceName = trim($serviceName) !== '' ? $serviceName : $focus;
        $focus = trim($focus) !== '' ? $focus : TopicText::lower($serviceName);
        $title ??= $this->title($type, $focus, $serviceName, $area);
        $existingAt = (float) config('moxdop-content.ideas.existing_title_threshold', 0.8);
        $similarAt = (float) config('moxdop-content.ideas.similar_title_threshold', 0.5);
        $similar = null;
        foreach ([$title, $focus] as $text) {
            $match = $this->inventory->similar($text, $similarAt);
            if ($match !== null && ($similar === null || $match['score'] > $similar['score'])) {
                $similar = $match;
            }
        }
        // Location pages are judged by area × service coverage (areaCovered), not by title similarity to the service page.
        if (! $force && $type !== 'location' && $similar !== null && $similar['score'] >= $existingAt) {
            return null;
        }
        if (! $force && $this->duplicateIdea($site, $title)) {
            return null;
        }
        $targetQueries = array_slice($this->compliance->filter(array_values(array_unique(array_merge([$focus], $queries)))), 0, 10);
        $slug = SeoText::slugify($type === 'location' && $area !== null ? $area.' '.$serviceName : ($type === 'faq' ? $serviceName.' sss' : $focus));
        $urlType = match ($type) {
            'comparison' => 'guide', 'service' => 'service', default => $type,
        };
        $origin = SeoText::origin((string) ($site->primary_url ?: 'https://'.$site->domain));
        $target = (new SiteUrlPattern($this->inventory->pages()))->targetUrl($origin, $urlType, $slug, $serviceName);

        return [
            'title' => mb_substr($title, 0, 250),
            'focus_keyword' => mb_substr($focus, 0, 200),
            'queries' => $targetQueries,
            'page_type' => $type,
            'target_url' => mb_substr($target, 0, 1000),
            'outline' => $outline !== [] ? $outline : $this->outline($type, $focus, $serviceName, $queries, $area),
            'faq' => $faq !== [] ? array_slice($faq, 0, 5) : $this->faq($type, $serviceName, $queries),
            'internal_links' => $this->links($offeringId, $names !== [] ? $names : [$serviceName], $title, $target),
            'similar_existing' => $similar,
        ];
    }

    private function title(string $type, string $focus, string $service, ?string $area): string
    {
        $Focus = TopicText::ucfirst($focus);
        $Service = TopicText::ucfirst($service);
        $options = match ($type) {
            'faq' => [$Service.' Hakkında Sık Sorulan Sorular'],
            'comparison' => [$Focus.': Farklar ve Hangi Durumda Hangisi?', $Focus.': Farklar'],
            'service' => [$Service.': Süreç ve Sık Sorulanlar', $Service],
            'location' => [trim(($area ?? '').' '.$Service).': Süreç ve Sık Sorulanlar', trim(($area ?? '').' '.$Service)],
            default => SeoText::looksLikeQuestion($focus) ? [rtrim($Focus, '?').'?'] : [$Focus.': Bilmeniz Gerekenler', $Focus],
        };

        return (string) $this->compliance->first($options, $Focus);
    }

    /**
     * @param  list<string>  $queries
     * @return list<array{h2: string, h3: list<string>}>
     */
    private function outline(string $type, string $focus, string $service, array $queries, ?string $area): array
    {
        $Focus = TopicText::ucfirst($focus);
        $Service = TopicText::ucfirst($service);
        $sections = array_values(array_filter($this->compliance->filter($queries), fn (string $q): bool => ! SeoText::looksLikeQuestion($q) && SeoText::fold($q) !== SeoText::fold($focus)));
        $extra = array_map(fn (string $q): string => TopicText::ucfirst($q), array_slice($sections, 0, 3));
        $lines = match ($type) {
            'faq' => [[$Service.' nedir?'], ['Süreç nasıl işler?'], ['Sık sorulan sorular']],
            'comparison' => [['Seçenekler nelerdir?'], ['Temel farklar'], ['Hangi durumda hangisi tercih edilir?'], ['Karar vermeden önce sorulması gerekenler'], ['Sık sorulan sorular']],
            'service' => [[$Service.' nedir, kimler için uygundur?'], ['Süreç nasıl işler?'], ['Sonrasında nelere dikkat edilmeli?'], ['Sık sorulan sorular']],
            'location' => [[trim(($area ?? '').' bölgesinde '.$service)], ['Süreç nasıl işler?'], ['Ulaşım ve randevu'], ['Sık sorulan sorular']],
            default => [
                [SeoText::looksLikeQuestion($focus) ? $Service.' nedir?' : $Focus.' nedir?'],
                ['Süreç adım adım'], ['Kimler için uygun?'], ['Dikkat edilmesi gerekenler'], ['Sık sorulan sorular'],
            ],
        };
        $out = [];
        foreach ($lines as $i => $options) {
            $h2 = $this->compliance->first($options);
            if ($h2 === null) {
                continue;
            }
            $out[] = ['h2' => $h2, 'h3' => $i === 1 ? $extra : []];
        }

        return $out;
    }

    /**
     * @param  list<string>  $queries
     * @return list<string>
     */
    private function faq(string $type, string $service, array $queries): array
    {
        $count = (int) config('moxdop-content.article.faq_questions', 4);
        $lower = TopicText::lower($service);
        $questions = array_map(fn (string $q): string => $this->question($q), array_values(array_filter($queries, fn (string $q): bool => SeoText::looksLikeQuestion($q))));
        $generic = [$lower.' ne kadar sürer?', $lower.' kimlere uygulanır?', $lower.' sonrası nelere dikkat edilmeli?', $lower.' öncesinde neler yapılmalı?', $lower.' için muayene gerekli mi?'];
        $all = $this->compliance->filter(array_values(array_unique(array_merge($questions, $generic))));

        return array_slice(array_map(fn (string $q): string => TopicText::ucfirst($q), $all), 0, $count);
    }

    private function question(string $text): string
    {
        $text = trim(strip_tags($text));

        return $text === '' ? '' : rtrim($text, '?').'?';
    }

    /**
     * Internal links: the offering's service page and 1–2 related existing posts / pages (real inventory URLs only).
     *
     * @param  list<string>  $names
     * @return list<array{url: string, title: string}>
     */
    private function links(?int $offeringId, array $names, string $title, string $targetUrl): array
    {
        $max = (int) config('moxdop-content.article.internal_links_max', 3);
        $links = [];
        $exclude = [SeoText::urlKey($targetUrl)];
        $service = $names !== [] ? $this->inventory->servicePage($offeringId, $names) : null;
        if ($service !== null && ! in_array(SeoText::urlKey($service['url']), $exclude, true)) {
            $links[] = ['url' => $service['url'], 'title' => $service['title']];
            $exclude[] = SeoText::urlKey($service['url']);
        }
        $related = $this->inventory->matches(trim($title.' '.implode(' ', array_slice($names, 0, 1))), 8, 0.2, $exclude);
        usort($related, fn (array $a, array $b): int => [($b['kind'] === 'post') ? 1 : 0, $b['score']] <=> [($a['kind'] === 'post') ? 1 : 0, $a['score']]);
        foreach ($related as $match) {
            if (count($links) >= $max || SeoText::urlPath($match['url']) === '/') {
                continue;
            }
            $links[] = ['url' => $match['url'], 'title' => $match['title']];
        }

        return array_slice($links, 0, $max);
    }

    /**
     * @param  list<mixed>  $outline
     * @return list<array{h2: string, h3: list<string>}>
     */
    private function cleanOutline(array $outline): array
    {
        $out = [];
        foreach (array_slice($outline, 0, 8) as $section) {
            $h2 = trim(strip_tags(is_array($section) ? (string) ($section['h2'] ?? '') : (string) $section));
            if ($h2 === '' || ! $this->compliance->isCompliant($h2)) {
                continue;
            }
            $h3 = is_array($section) ? $this->compliance->filter(array_map(fn ($h): string => mb_substr(trim(strip_tags((string) $h)), 0, 150), array_slice((array) ($section['h3'] ?? []), 0, 5))) : [];
            $out[] = ['h2' => mb_substr($h2, 0, 150), 'h3' => $h3];
        }

        return $out;
    }

    private function duplicateIdea(DigitalAsset $site, string $title): bool
    {
        $stems = TopicText::stems($title);
        foreach (ContentIdea::query()->where('digital_asset_id', $site->id)->where('status', '!=', 'removed')->pluck('title') as $existing) {
            if (TopicText::stemSimilarity($stems, TopicText::stems((string) $existing)) >= (float) config('moxdop-content.ideas.existing_title_threshold', 0.8)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $names */
    private function areaCovered(string $area, array $names): bool
    {
        $area = SeoText::fold($area);
        foreach ($this->inventory->items() as $item) {
            $text = SeoText::fold($item['title'].' '.$item['h1'].' '.$item['slug']);
            if (! str_contains(' '.$text.' ', ' '.$area.' ')) {
                continue;
            }
            foreach ($names as $name) {
                if (TopicText::containment($name, $text) >= 0.8) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> the brand's active service areas (district, else city), priority order */
    private function areas(?Brand $brand): array
    {
        if ($brand === null) {
            return [];
        }
        $out = [];
        foreach ($brand->serviceAreas()->where('status', 'active')->orderByRaw('CASE WHEN priority_rank IS NULL THEN 1 ELSE 0 END')->orderBy('priority_rank')->orderBy('id')->get() as $area) {
            $name = trim((string) ($area->district_name ?: $area->city_name));
            if ($name !== '') {
                $out[SeoText::fold($name)] = $name;
            }
        }

        return array_values($out);
    }

    /**
     * @param  list<int>|null  $offeringIds
     * @return array<int, array{name: string, names: list<string>}>
     */
    private function orderedServices(?Brand $brand, ?array $offeringIds): array
    {
        if ($brand === null) {
            return [];
        }
        $ids = BrandOffering::query()->where('brand_id', $brand->id)->where('status', 'active')
            ->when($offeringIds !== null, fn ($q) => $q->whereIn('id', $offeringIds ?: [0]))
            ->orderByDesc('is_priority')->orderByRaw('CASE WHEN priority_rank IS NULL THEN 1 ELSE 0 END')->orderBy('priority_rank')->orderBy('id')->pluck('id');

        $out = [];
        foreach ($ids as $id) {
            if (isset($this->services[(int) $id])) {
                $out[(int) $id] = $this->services[(int) $id];
            }
        }

        return $out;
    }

    private function offeringByName(string $name): ?int
    {
        [$best, $bestScore] = [null, 0.0];
        foreach ($this->services as $id => $service) {
            foreach ($service['names'] as $candidate) {
                $score = SeoText::fold($candidate) === SeoText::fold($name) ? 1.0 : TopicText::similarity($candidate, $name);
                if ($score > $bestScore) {
                    [$best, $bestScore] = [$id, $score];
                }
            }
        }

        return $bestScore >= 0.6 ? $best : null;
    }

    private function nextSort(DigitalAsset $site): int
    {
        return (int) ContentIdea::query()->where('digital_asset_id', $site->id)->max('sort') + 1;
    }

    private function boot(DigitalAsset $site): void
    {
        if ($this->inventory !== null && (int) $this->inventory->site->id === (int) $site->id) {
            return;
        }
        $this->inventory = SiteContentInventory::for($site);
        $this->compliance = BriefCompliance::forBrand($site->brand);
        $this->services = [];
        foreach (BrandOffering::query()->with(['names', 'primaryName', 'catalogItem.primaryName', 'catalogItem.names'])->where('brand_id', $site->brand_id)->where('status', 'active')->get() as $offering) {
            $names = [$offering->displayName()];
            foreach ($offering->names as $name) {
                if ($name->is_active && filled($name->raw_label)) {
                    $names[] = (string) $name->raw_label;
                }
            }
            foreach ($offering->catalogItem?->names ?? [] as $name) {
                if ($name->is_active && filled($name->raw_label)) {
                    $names[] = (string) $name->raw_label;
                }
            }
            $this->services[(int) $offering->id] = ['name' => $offering->displayName(), 'names' => array_values(array_unique($names))];
        }
    }

    /** @return array<int, array{name: string, names: list<string>}> */
    public function services(DigitalAsset $site): array
    {
        $this->boot($site);

        return $this->services;
    }

    public function inventory(DigitalAsset $site): SiteContentInventory
    {
        $this->boot($site);

        return $this->inventory;
    }

    public function compliance(DigitalAsset $site): BriefCompliance
    {
        $this->boot($site);

        return $this->compliance;
    }
}

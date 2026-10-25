<?php

namespace App\Services\AiTasks;

use App\Models\AiTask;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\DigitalAsset;
use App\Models\GbpQueuedPost;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\ExternalWrites\ArticleDraft;
use App\Services\ExternalWrites\ContentComplianceGate;
use App\Services\Gbp\GbpAssistant;
use App\Services\Gbp\GbpPostQueue;
use App\Services\Repair\RepairAutopilot;
use App\Services\Repair\RepairDesk;
use App\Services\Site\ContentPosts;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Düzeltme (2026-10-14): Claude fixes a result of the AI queue it already submitted (a typo, a half-translated FAQ, a
 * template meta description) where the result landed: the desk / content row (`suggestions.action`) or the planned
 * Business Profile post. The publishing rules do not change.
 *
 *  - Not sent yet: the text is corrected in place, the same sector checks run again, and the row's own AI check is
 *    asked again (content and GBP posts lose their verdict; a desk row's changed proposal is a new check by itself).
 *    It still goes out only with the operator's daily click or desk approval.
 *  - Already sent: nothing is written to the site; the correction becomes a new row for the operator to approve — a
 *    title / description row of the same page, or a content "Güncelleme" row for the sent WordPress post.
 *
 * Every correction is logged on the row (`action.corrections`).
 */
final class ResultCorrections
{
    public const array ARTICLE_FIELDS = ['title', 'meta_title', 'meta_description', 'excerpt', 'html'];

    public const array PROPOSAL_FIELDS = ['seo_title', 'meta_description'];

    public const string TARGET_SUGGESTION = 'suggestion';

    public const string TARGET_GBP_POST = 'gbp_post';

    /** A cluster row's match reason or missing items (site.cluster_match / site.cluster_gaps; pool #46). */
    public const string TARGET_CLUSTER_ROW = 'cluster_row';

    public const array CLUSTER_OPERATIONS = ['site.cluster_match', 'site.cluster_gaps'];

    /** Kept on a row at most (newest last). */
    private const int LOG_MAX = 20;

    /**
     * The rows a closed task's answer landed on: suggestion ids named in the asking job or in the answer, and planned
     * GBP posts whose text the answer wrote. Only rows of the task's brand.
     *
     * @return list<array<string, mixed>>
     */
    public function targetsOf(AiTask $task): array
    {
        $ids = [];
        if (preg_match_all('/"suggestion_?[iI]d";i:(\d+);/', (string) $task->resume, $m) > 0) {
            array_push($ids, ...array_map('intval', $m[1]));
        }
        if (preg_match_all('/"suggestion_?[iI]ds";a:\d+:\{([^}]*)\}/', (string) $task->resume, $m) > 0) {
            foreach ($m[1] as $list) {
                preg_match_all('/i:\d+;i:(\d+);/', $list, $inner);
                array_push($ids, ...array_map('intval', $inner[1]));
            }
        }
        $answered = [];
        foreach (['pages', 'items', 'rows'] as $key) {
            foreach ((array) data_get($task->output, $key, []) as $row) {
                if (is_array($row) && is_int($row['id'] ?? null)) {
                    $answered[] = $row['id'];
                }
            }
        }
        $suggestions = Suggestion::query()->whereIn('id', array_values(array_unique([...$ids, ...$answered])))
            ->when($task->brand_id !== null, fn ($q) => $q->where('brand_id', $task->brand_id))->orderBy('id')->get();
        $posts = GbpQueuedPost::query()->whereIn('id', $answered)
            ->when($task->brand_id !== null, fn ($q) => $q->where('brand_id', $task->brand_id))->get();
        foreach ((array) data_get($task->output, 'posts', []) as $row) {
            $text = trim((string) (is_array($row) ? ($row['text'] ?? '') : ''));
            if (mb_strlen($text) >= 40) {
                $posts = $posts->merge(GbpQueuedPost::query()->when($task->brand_id !== null, fn ($q) => $q->where('brand_id', $task->brand_id))
                    ->where('summary', 'like', str_replace(['%', '_'], ['\%', '\_'], mb_substr($text, 0, 60)).'%')
                    ->where('created_at', '>=', ($task->completed_at ?? $task->created_at)->copy()->subDay())->get());
            }
        }

        $clusterRows = collect();
        if (in_array((string) $task->operation, self::CLUSTER_OPERATIONS, true) && $task->brand_id !== null) {
            $clusterIds = collect((array) data_get($task->output, 'clusters', []))->map(fn ($r): mixed => is_array($r) ? ($r['cluster_id'] ?? null) : null)
                ->filter(fn ($id): bool => is_int($id))->unique()->values()->all();
            if ($clusterIds !== []) {
                $clusterRows = BrandClusterPage::query()->where('brand_id', $task->brand_id)->whereIn('cluster_id', $clusterIds)
                    ->when(is_int(data_get($task->input, 'site_id')), fn ($q) => $q->where('website_asset_id', data_get($task->input, 'site_id')))
                    ->orderBy('id')->limit(200)->get();
            }
        }

        return [
            ...$suggestions->map(fn (Suggestion $s): array => $this->describe($s))->filter(fn (array $t): bool => $t['fields'] !== [])->values()->all(),
            ...$posts->unique('id')->map(fn (GbpQueuedPost $p): array => $this->describe($p))->values()->all(),
            ...$clusterRows->map(fn (BrandClusterPage $row): array => $this->describeClusterRow($row))->filter(fn (array $t): bool => $t['fields'] !== [])->values()->all(),
        ];
    }

    /**
     * A cluster row's operator-visible texts: the match reason and each missing item (never sent anywhere).
     *
     * @return array{type: string, id: int, title: string, status: string, fields: array<string, array{text: mixed, sent: bool}>}
     */
    public function describeClusterRow(BrandClusterPage $row): array
    {
        $fields = [];
        if (is_string($row->reason) && trim($row->reason) !== '') {
            $fields['reason'] = ['text' => $row->reason, 'sent' => false];
        }
        foreach ((array) $row->gaps as $i => $gap) {
            if (is_array($gap) && is_string($gap['text'] ?? null)) {
                $fields['gaps.'.$i.'.text'] = ['text' => $gap['text'], 'sent' => false];
            }
        }

        return ['type' => self::TARGET_CLUSTER_ROW, 'id' => (int) $row->id, 'title' => 'Küme satırı #'.$row->id.' ('.(string) $row->language.')',
            'status' => (string) $row->state, 'fields' => $fields];
    }

    /** @param  list<array{find: string, replace: string}>  $replacements */
    private function correctClusterRow(BrandClusterPage $row, string $path, array $replacements, mixed $value, string $note, ?int $taskId): array
    {
        $current = $this->describeClusterRow($row)['fields'][$path] ?? null;
        if ($current === null) {
            throw ValidationException::withMessages(['path' => 'Bu satırda "'.$path.'" yok. Düzeltilebilecek alanlar: '.implode(', ', array_keys($this->describeClusterRow($row)['fields'])).'.']);
        }
        $new = trim(self::edited((string) $current['text'], $replacements, $value));
        if ($new === $current['text']) {
            throw ValidationException::withMessages(['value' => 'Düzeltme metni değiştirmiyor.']);
        }
        // The same rule as the AI answer (ClusterAudit): a URL or a number of two or more digits is not from the page.
        if (preg_match('/https?:|www\.|\d{2,}/u', $new) === 1) {
            throw ValidationException::withMessages(['value' => 'Gerekçe ve eksik metninde adres ya da iki haneli sayı olamaz.']);
        }
        if ($path === 'reason') {
            $row->forceFill(['reason' => mb_substr($new, 0, 300)])->save();
        } else {
            $gaps = (array) $row->gaps;
            $index = (int) explode('.', $path)[1];
            if (mb_strlen($new) < 5) {
                throw ValidationException::withMessages(['value' => 'Eksik metni en az 5 karakter olmalı.']);
            }
            $gaps[$index]['text'] = mb_substr($new, 0, 200);
            $row->forceFill(['gaps' => array_values($gaps)])->save();
        }
        logger()->info('Cluster row corrected by Claude.', ['row' => $row->id, 'path' => $path, 'note' => mb_substr(trim($note), 0, 300), 'task_id' => $taskId]);

        return ['outcome' => 'corrected', 'target' => $this->describeClusterRow($row->fresh()), 'message' => 'Düzeltildi; küme satırı yalnız panelde görünür, siteye bir şey gitmez.'];
    }

    /**
     * What can be corrected on a row: path => {text, sent}.
     *
     * @return array{type: string, id: int, title: string, status: string, fields: array<string, array{text: mixed, sent: bool}>, corrections?: list<array<string, mixed>>}
     */
    public function describe(Suggestion|GbpQueuedPost $target): array
    {
        if ($target instanceof GbpQueuedPost) {
            return ['type' => self::TARGET_GBP_POST, 'id' => (int) $target->id, 'title' => 'GBP gönderisi '.$target->publish_on, 'status' => (string) $target->status,
                'fields' => ['summary' => ['text' => (string) $target->summary, 'sent' => self::postSent($target)]]];
        }
        $action = (array) $target->action;
        $fields = [];
        foreach (self::ARTICLE_FIELDS as $field) {
            if (is_string(data_get($action, 'article.'.$field))) {
                $fields['article.'.$field] = ['text' => $action['article'][$field], 'sent' => self::sent($target, 'article.'.$field)];
            }
        }
        foreach ((array) ($action['translations'] ?? []) as $language => $article) {
            foreach (self::ARTICLE_FIELDS as $field) {
                if (is_array($article) && is_string($article[$field] ?? null)) {
                    $fields['translations.'.$language.'.'.$field] = ['text' => $article[$field], 'sent' => self::sent($target, 'translations.'.$language.'.'.$field)];
                }
            }
        }
        foreach (self::PROPOSAL_FIELDS as $field) {
            if (is_string(data_get($action, 'proposal.new.'.$field))) {
                $fields['proposal.'.$field] = ['text' => data_get($action, 'proposal.new.'.$field), 'sent' => self::sent($target, 'proposal.'.$field)];
            }
        }
        if (is_array(data_get($action, 'proposal.new.internal_links')) && data_get($action, 'proposal.new.internal_links') !== []) {
            $fields['proposal.internal_links'] = ['text' => data_get($action, 'proposal.new.internal_links'), 'sent' => self::sent($target, 'proposal.internal_links')];
        }
        if ($target->action_type === 'gbp_description' && is_string($action['proposed'] ?? null)) {
            $fields['proposed'] = ['text' => $action['proposed'], 'sent' => self::sent($target, 'proposed')];
        }
        if (is_string(data_get($action, RepairAutopilot::KEY.'.reason'))) {
            $fields[RepairAutopilot::KEY.'.reason'] = ['text' => data_get($action, RepairAutopilot::KEY.'.reason'), 'sent' => false];
        }

        return array_filter(['type' => self::TARGET_SUGGESTION, 'id' => (int) $target->id, 'title' => (string) $target->title, 'status' => (string) $target->status,
            'fields' => $fields, 'corrections' => $action['corrections'] ?? null], fn (mixed $v): bool => $v !== null);
    }

    /**
     * Corrects one field: `replacements` (each `find` must be in the current text; every occurrence is replaced) or a
     * whole new `value`.
     *
     * @param  list<array{find: string, replace: string}>  $replacements
     * @return array{outcome: string, message: string, target: array<string, mixed>, follow_up_id?: int}
     */
    public function correct(string $type, int $id, string $path, array $replacements, mixed $value, string $note, ?int $taskId = null): array
    {
        if ($type === self::TARGET_GBP_POST) {
            return $this->correctPost(GbpQueuedPost::query()->findOrFail($id), $path, $replacements, $value);
        }
        if ($type === self::TARGET_CLUSTER_ROW) {
            return $this->correctClusterRow(BrandClusterPage::query()->findOrFail($id), $path, $replacements, $value, $note, $taskId);
        }
        $suggestion = Suggestion::query()->with('page')->findOrFail($id);
        $current = $this->describe($suggestion)['fields'][$path] ?? null;
        if ($current === null) {
            throw ValidationException::withMessages(['path' => 'Bu satırda "'.$path.'" yok. Düzeltilebilecek alanlar: '.implode(', ', array_keys($this->describe($suggestion)['fields'])).'.']);
        }
        $new = $path === 'proposal.internal_links' ? $this->links((array) $current['text'], $value) : self::edited((string) $current['text'], $replacements, $value);
        if ($new === $current['text']) {
            throw ValidationException::withMessages(['value' => 'Düzeltme metni değiştirmiyor.']);
        }
        $brand = Brand::query()->with('sectorCategory')->find($suggestion->brand_id);
        if ($path !== RepairAutopilot::KEY.'.reason' && is_string($new)) {
            $this->assertCleanText($brand, $new);
        }
        $log = ['path' => $path, 'note' => mb_substr(trim($note), 0, 300), 'task_id' => $taskId, 'at' => now()->toIso8601String()];

        if ($current['sent']) {
            if (RepairDesk::isQueued($suggestion)) {
                throw ValidationException::withMessages(['path' => 'Bu satırın onayı şu an gönderiliyor; bitince tekrar dene.']);
            }
            $followUp = str_starts_with($path, 'proposal.') ? $this->fieldsFollowUp($suggestion, $path, (string) $new, $log)
                : $this->articleFollowUp($suggestion, $path, (string) $new, $brand, $log);
            $this->log($suggestion, $log + ['follow_up_id' => (int) $followUp->id]);

            return ['outcome' => 'follow_up', 'follow_up_id' => (int) $followUp->id, 'target' => $this->describe($followUp),
                'message' => 'Bu değer siteye gitmişti; düzeltme #'.$followUp->id.' numaralı yeni satır olarak onaya hazırlandı.'];
        }

        $action = (array) $suggestion->action;
        match (true) {
            str_starts_with($path, 'article.'), str_starts_with($path, 'translations.') => $this->setArticleField($action, $path, (string) $new, $brand),
            $path === 'proposal.internal_links' => data_set($action, 'proposal.new.internal_links', $new),
            str_starts_with($path, 'proposal.') => data_set($action, 'proposal.new.'.substr($path, 9), $new),
            $path === 'proposed' => $action['proposed'] = mb_substr((string) $new, 0, 750),
            default => data_set($action, $path, mb_substr((string) $new, 0, 180)),
        };
        $action['corrections'] = array_slice([...(array) ($action['corrections'] ?? []), $log], -self::LOG_MAX);
        $suggestion->forceFill(['action' => $action])->save();

        return ['outcome' => 'corrected', 'target' => $this->describe($suggestion->fresh()),
            'message' => 'Düzeltildi; satır yeniden AI kontrolünden geçecek ve yine operatör onayıyla gönderilecek.'];
    }

    /** Whether the value under this path already went to the site / Google. */
    public static function sent(Suggestion $suggestion, string $path): bool
    {
        $action = (array) $suggestion->action;
        if (str_starts_with($path, 'article.')) {
            return isset($action['article_write_id']);
        }
        if (str_starts_with($path, 'translations.')) {
            $language = explode('.', $path)[1] ?? '';

            return isset($action['article_write_id']) && in_array($language, (array) ($action['sent_languages'] ?? []), true);
        }
        if (str_starts_with($path, 'proposal.') || $path === 'proposed') {
            return isset($action['writes']) || in_array($suggestion->status, [Suggestion::APPROVED, Suggestion::APPLIED], true) || RepairDesk::isQueued($suggestion);
        }

        return false;
    }

    private static function postSent(GbpQueuedPost $post): bool
    {
        return $post->external_write_action_id !== null || in_array($post->status, [GbpQueuedPost::PUBLISHED, GbpQueuedPost::FAILED], true);
    }

    /** @param  list<array{find: string, replace: string}>  $replacements */
    private static function edited(string $text, array $replacements, mixed $value): string
    {
        if ($replacements === []) {
            if (! is_string($value) || trim($value) === '') {
                throw ValidationException::withMessages(['value' => 'replacements ya da value gerekli.']);
            }

            return trim($value);
        }
        foreach ($replacements as $i => $pair) {
            $find = (string) ($pair['find'] ?? '');
            if ($find === '' || ! str_contains($text, $find)) {
                throw ValidationException::withMessages(['replacements' => ($i + 1).'. düzeltmenin aranan metni bulunamadı: «'.mb_substr($find, 0, 80).'».']);
            }
            $text = str_replace($find, (string) ($pair['replace'] ?? ''), $text);
        }

        return $text;
    }

    /**
     * Links can be dropped or their anchor text fixed; no new link target is invented.
     *
     * @param  list<mixed>  $current
     * @return list<array<string, mixed>>
     */
    private function links(array $current, mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            throw ValidationException::withMessages(['value' => 'İç linklerin yeni listesi (en az bir link) value olarak verilmeli.']);
        }
        $known = collect($current)->filter(fn (mixed $l): bool => is_array($l))->keyBy(fn (array $l): string => (string) ($l['url'] ?? ''));
        $out = [];
        foreach ($value as $link) {
            $url = is_array($link) ? (string) ($link['url'] ?? '') : '';
            if (! $known->has($url)) {
                throw ValidationException::withMessages(['value' => 'Yeni link eklenemez, yalnız mevcut linkler kalabilir: '.$url]);
            }
            $out[] = array_merge($known->get($url), array_intersect_key($link, array_flip(['anchor', 'text'])));
        }

        return $out;
    }

    private function assertCleanText(?Brand $brand, string $text): void
    {
        $hits = ForbiddenTerms::forBrand($brand)->blocking(strip_tags($text));
        if ($hits !== []) {
            throw ValidationException::withMessages(['value' => 'Düzeltme yasaklı ifade içeriyor: «'.implode('», «', array_map(fn ($h): string => is_array($h) ? (string) ($h['phrase'] ?? json_encode($h)) : (string) $h, $hits)).'».']);
        }
    }

    /** @param  array<string, mixed>  $action */
    private function setArticleField(array &$action, string $path, string $value, ?Brand $brand): void
    {
        $base = str_starts_with($path, 'article.') ? 'article' : implode('.', array_slice(explode('.', $path), 0, 2));
        $article = (array) data_get($action, $base);
        $article[last(explode('.', $path))] = $value;
        $this->assertArticle($article, $brand);
        data_set($action, $base, $article);
        // The nightly content check reads the corrected article again before the daily click can send it.
        if (isset($action['autopilot']['review'])) {
            unset($action['autopilot']['review']);
        }
    }

    /** @param  array<string, mixed>  $article */
    private function assertArticle(array $article, ?Brand $brand): void
    {
        if (mb_strlen(trim((string) ($article['title'] ?? ''))) < 5 || trim(strip_tags((string) ($article['html'] ?? ''))) === '') {
            throw ValidationException::withMessages(['value' => 'Başlık ya da metin boş kalamaz.']);
        }
        $gate = app(ContentComplianceGate::class);
        $blocking = ContentComplianceGate::blocking($gate->violations($brand, ArticleDraft::fromArray($article + ['reference' => 'correction'])));
        if ($blocking !== []) {
            throw ValidationException::withMessages(['value' => 'Sektör kuralına takılıyor: '.ContentComplianceGate::summary($blocking)]);
        }
    }

    /** @param  array<string, mixed>  $log */
    private function log(Suggestion $suggestion, array $log): void
    {
        $fresh = $suggestion->fresh();
        $action = (array) $fresh->action;
        $action['corrections'] = array_slice([...(array) ($action['corrections'] ?? []), $log], -self::LOG_MAX);
        $fresh->forceFill(['action' => $action])->save();
    }

    /**
     * A title / description already on the site: a new desk row of the same page with only the corrected field.
     *
     * @param  array<string, mixed>  $log
     */
    private function fieldsFollowUp(Suggestion $original, string $path, string $value, array $log): Suggestion
    {
        $field = substr($path, 9);
        $page = $original->page;
        if (! in_array($field, self::PROPOSAL_FIELDS, true) || $page === null) {
            throw ValidationException::withMessages(['path' => 'Gönderilmiş bu değer için düzeltme satırı hazırlanamıyor (yalnız sayfası bilinen başlık ve açıklama).']);
        }
        $current = [$field => $field === 'seo_title' ? $page->title : $page->meta_description];

        return $this->followUp($original, 'title_description', $page->id, 'Düzeltme: '.($field === 'seo_title' ? 'başlık' : 'açıklama'), [
            'site_id' => (int) $page->website_asset_id, 'problems' => [$field.':improve'],
            'proposal' => ['kind' => 'fields', 'current' => array_filter($current, fn ($v): bool => $v !== null), 'new' => [$field => $value],
                'note' => (string) $log['note'], 'prepared_at' => now()->toIso8601String(), 'correction' => true],
        ], $value);
    }

    /**
     * An article already sent to WordPress: a content "Güncelleme" row for the sent post with the corrected version
     * (sent as a WordPress draft copy of that post, then "Sayfaya uygula").
     *
     * @param  array<string, mixed>  $log
     */
    private function articleFollowUp(Suggestion $original, string $path, string $value, ?Brand $brand, array $log): Suggestion
    {
        $action = (array) $original->action;
        $base = str_starts_with($path, 'article.') ? 'article' : implode('.', array_slice(explode('.', $path), 0, 2));
        $article = (array) data_get($action, $base);
        $article[last(explode('.', $path))] = $value;
        $this->assertArticle($article, $brand);
        $language = (string) ($article['language'] ?? '');
        $posts = app(ContentPosts::class)->postIds(collect([$original]))[(int) $original->id] ?? [];
        $postId = $posts[$language] ?? ($base === 'article' ? (array_values($posts)[0] ?? null) : null);
        if ($postId === null) {
            throw ValidationException::withMessages(['path' => 'Yazı gönderildi ama WordPress yazı numarası henüz gelmedi; gönderim bitince tekrar dene.']);
        }
        $site = DigitalAsset::query()->find($action['site_id'] ?? null);
        $page = $site !== null ? Page::query()->where('website_asset_id', $site->id)->where('wp_post_id', $postId)->first() : null;

        return $this->followUp($original, SiteSuggestionTypes::CONTENT, $page?->id, 'Düzeltme: '.mb_substr((string) ($article['title'] ?? $original->title), 0, 140), [
            'site_id' => (int) ($action['site_id'] ?? 0), 'kind' => 'update', 'page_type' => $action['page_type'] ?? 'blog', 'language' => $language ?: null,
            'target_url' => $page?->url ?? ($action['target_url'] ?? null), 'wp_post_id' => (int) $postId, 'article' => $article,
            'article_note' => 'Gönderilmiş yazının düzeltilmiş hali: '.$log['note'],
        ], md5((string) json_encode($article)));
    }

    /** @param  array<string, mixed>  $action */
    private function followUp(Suggestion $original, string $type, ?int $pageId, string $title, array $action, string $material): Suggestion
    {
        $fingerprint = hash('sha256', 'correction|'.$original->id.'|'.$type.'|'.md5($material));
        $existing = Suggestion::query()->where('brand_id', $original->brand_id)->where('fingerprint', $fingerprint)->first();
        if ($existing !== null) {
            return $existing;
        }

        return Suggestion::query()->create([
            'brand_id' => $original->brand_id, 'channel' => 'search', 'decision_key' => 'site.correction', 'fingerprint' => $fingerprint,
            'material_hash' => hash('sha256', $material), 'title' => mb_substr($title, 0, 160),
            'reason' => 'Claude, #'.$original->id.' numaralı satırla gönderilen değerde hata buldu; düzeltilmiş hali onayınızı bekliyor.',
            'priority' => 2, 'evidence' => [['kind' => 'correction', 'value' => '#'.$original->id, 'source' => 'Claude düzeltmesi']],
            'action_type' => $type, 'target_type' => 'site', 'target_id' => $action['site_id'] ?? null, 'page_id' => $pageId, 'cluster_id' => $original->cluster_id,
            'status' => Suggestion::OPEN, 'first_seen_at' => now(), 'last_seen_at' => now(),
            'action' => $action + ['correction_of' => (int) $original->id],
        ]);
    }

    /** @param  list<array{find: string, replace: string}>  $replacements */
    private function correctPost(GbpQueuedPost $post, string $path, array $replacements, mixed $value): array
    {
        if ($path !== 'summary') {
            throw ValidationException::withMessages(['path' => 'GBP gönderisinde yalnız "summary" düzeltilir.']);
        }
        if (self::postSent($post) || ! in_array($post->status, [GbpQueuedPost::DRAFT, GbpQueuedPost::APPROVED], true)) {
            throw ValidationException::withMessages(['path' => 'Bu gönderi Google\'a gitmiş ya da kapanmış; yayınlanmış GBP gönderisini düzeltecek bir yol yok.']);
        }
        $text = self::edited((string) $post->summary, $replacements, $value);
        if (mb_strlen($text) < 40 || mb_strlen($text) > GbpPostQueue::TEXT_MAX) {
            throw ValidationException::withMessages(['value' => 'Metin 40–'.GbpPostQueue::TEXT_MAX.' karakter olmalı.']);
        }
        $blocking = GbpAssistant::blockingHits($post->digitalAsset?->brand, $text);
        if ($blocking !== []) {
            throw ValidationException::withMessages(['value' => 'Sektör uyum kuralına takılıyor: '.implode(', ', $blocking).'.']);
        }
        // Read again by the post check before the daily click can send it.
        $post->forceFill(['summary' => $text, 'review' => null])->save();

        return ['outcome' => 'corrected', 'target' => $this->describe($post->fresh()),
            'message' => 'Düzeltildi; gönderi yeniden AI kontrolünden geçecek ve yine günlük tıkla gönderilecek.'];
    }

    /** @return Collection<int, Suggestion> rows that are corrections of this one */
    public static function followUpsOf(Suggestion $suggestion): Collection
    {
        return Suggestion::query()->where('brand_id', $suggestion->brand_id)->where('action->correction_of', (int) $suggestion->id)->get();
    }
}

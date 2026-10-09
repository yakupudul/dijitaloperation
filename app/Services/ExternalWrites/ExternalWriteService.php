<?php

namespace App\Services\ExternalWrites;

use App\Jobs\ExecuteExternalWriteJob;
use App\Models\AiProduction;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpReview;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Gbp\Desk\BranchPages;
use App\Services\Gbp\Desk\PhotoPlan;
use App\Services\Gbp\GbpAssistant;
use App\Services\Gbp\GbpProfilePlanner;
use App\Services\Gbp\GbpSuggestions;
use App\Services\GoogleAds\GoogleAdsChanges;
use App\Services\GoogleAds\GoogleAdsSuggestions;
use App\Services\Integrations\WordPress\WordPressManagementService;
use App\Services\Integrations\WordPress\WordPressSiteBuilder;
use App\Services\Site\ClusterOverlaps;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Entry point for every approved external write (ADR-064 / 068 / 070 / 071 / 073 / 076 / 077). Checks: kill switch, Admin role, eligible source item. Every
 * request becomes an ExternalWriteAction row and runs on the queue; undo is the same path in reverse.
 */
final class ExternalWriteService
{
    /** ADR-070 actions handled by WordPressFixWriter. */
    private const array FIX_ACTIONS = [ExternalWriteAction::ACTION_SITE_FIX, ExternalWriteAction::ACTION_CONTENT_DRAFT, ExternalWriteAction::ACTION_CONTENT_APPLY];

    public function __construct(
        private readonly GoogleAdsNegativeListWriter $negatives,
        private readonly WordPressDraftWriter $drafts,
        private readonly WordPressFixWriter $fixes,
        private readonly GbpWriter $gbp,
    ) {}

    public static function allowed(?User $user, string $channel): bool
    {
        return (bool) config('moxdop-external-writes.enabled', true)
            && (bool) config('moxdop-external-writes.'.$channel.'.enabled', true)
            && $user !== null && $user->is_active && $user->hasRole(Roles::ADMIN);
    }

    /**
     * ADR-064: Admin-approved (possibly edited) negative keyword list for one bound Google Ads account, sent to the
     * shared negative list. Optionally linked to the suggestion(s) that proposed it; they are applied when it succeeds.
     *
     * @param  list<int>  $suggestionIds
     */
    public function requestNegativeList(User $user, DigitalAsset $asset, string $lines, ?Suggestion $suggestion = null, array $suggestionIds = []): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_GOOGLE_ADS);
        if ($asset->type !== 'google_ads' || ($suggestion !== null && (int) $asset->brand_id !== (int) $suggestion->brand_id)) {
            throw ValidationException::withMessages(['write' => 'Bu liste Google Ads\'e gönderilemez.']);
        }
        $parsed = GoogleAdsNegativeListWriter::parse($lines);
        if ($parsed['keywords'] === []) {
            throw ValidationException::withMessages(['write' => 'Gönderilecek geçerli terim yok.']);
        }
        $running = ExternalWriteAction::query()->where('digital_asset_id', $asset->id)->where('action', ExternalWriteAction::ACTION_NEGATIVE_LIST_ADD)
            ->whereIn('status', ['queued', 'running', 'undoing'])->exists();
        if ($running) {
            throw ValidationException::withMessages(['write' => 'Bu hesap için bir negatif gönderimi zaten sürüyor.']);
        }

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_GOOGLE_ADS,
            'action' => ExternalWriteAction::ACTION_NEGATIVE_LIST_ADD,
            'digital_asset_id' => $asset->id,
            'brand_id' => $asset->brand_id,
            'suggestion_id' => $suggestion?->id,
            'status' => 'queued',
            'request_payload' => ['keywords' => $parsed['keywords'], 'rejected' => $parsed['rejected'], 'shared_set_name' => config('moxdop-external-writes.google_ads.shared_set_name')]
                + ($suggestionIds !== [] ? ['suggestion_ids' => array_values($suggestionIds)] : []),
            'requested_by' => $user->id,
        ]));
    }

    /**
     * ADR-081: one Google Ads setting change prepared on the Onarım masası (field, resource, value before and after).
     * Budgets move by at most 30% in one change.
     *
     * @param  array{field: string, resource: string, before: mixed, after: mixed, label: string}  $change
     */
    public function requestAdsChange(User $user, DigitalAsset $asset, array $change, ?Suggestion $suggestion = null): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_GOOGLE_ADS);
        $field = (string) ($change['field'] ?? '');
        if ($asset->type !== 'google_ads' || ! isset(GoogleAdsChangeWriter::FIELDS[$field]) || ! is_string($change['resource'] ?? null)) {
            throw ValidationException::withMessages(['write' => 'Bu değişiklik Google Ads\'e gönderilemez.']);
        }
        $valid = match ($field) {
            'search_partners', 'display_network', 'auto_tagging' => is_bool($change['after'] ?? null),
            'location_option' => in_array($change['after'] ?? null, ['PRESENCE', 'PRESENCE_OR_INTEREST'], true),
            'keyword_status' => in_array($change['after'] ?? null, ['PAUSED', 'ENABLED'], true),
            'budget' => is_numeric($change['after'] ?? null) && is_numeric($change['before'] ?? null) && (int) $change['before'] > 0
                && abs((int) $change['after'] - (int) $change['before']) <= 0.3 * (int) $change['before'] + 1,
        };
        if (! $valid) {
            throw ValidationException::withMessages(['write' => 'Değişiklik değeri geçersiz'.($field === 'budget' ? ' (bütçe bir seferde en çok %30 değişir).' : '.')]);
        }
        $busy = ExternalWriteAction::query()->where('digital_asset_id', $asset->id)->where('action', ExternalWriteAction::ACTION_ADS_CHANGE)
            ->whereIn('status', ['queued', 'running', 'undoing'])->whereRaw('cast(request_payload as text) like ?', ['%'.$change['resource'].'%'])->exists();
        if ($busy) {
            throw ValidationException::withMessages(['write' => 'Bu öğe için bir değişiklik zaten gönderiliyor.']);
        }

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_GOOGLE_ADS, 'action' => ExternalWriteAction::ACTION_ADS_CHANGE,
            'digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id, 'suggestion_id' => $suggestion?->id, 'status' => 'queued',
            'request_payload' => ['field' => $field, 'resource' => $change['resource'], 'before' => $change['before'] ?? null, 'after' => $change['after'],
                'label' => mb_substr((string) ($change['label'] ?? 'Google Ads ayarı'), 0, 160)],
            'requested_by' => $user->id,
        ]));
    }

    /**
     * ADR-064 (2): Admin-approved WordPress draft skeleton.
     *
     * @param  array{title: string, content_html: string, post_type?: string, excerpt?: string, reference: string}  $draft
     */
    public function requestDraft(User $user, DigitalAsset $site, array $draft, ?Suggestion $suggestion = null): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_WORDPRESS);
        if (blank($draft['title'] ?? null) || blank($draft['reference'] ?? null)) {
            throw ValidationException::withMessages(['write' => 'Taslağın başlığı ve referansı olmalı.']);
        }
        try {
            $this->drafts->connection((int) $site->id);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['write' => $exception->getMessage()]);
        }
        $busy = ExternalWriteAction::query()->where('digital_asset_id', $site->id)->where('action', ExternalWriteAction::ACTION_DRAFT_CREATE)
            ->whereIn('status', ['queued', 'running', 'undoing'])->where('request_payload->draft->reference', $draft['reference'])->exists();
        if ($busy) {
            throw ValidationException::withMessages(['write' => 'Bu taslak için bir gönderim zaten sürüyor.']);
        }

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS,
            'action' => ExternalWriteAction::ACTION_DRAFT_CREATE,
            'digital_asset_id' => $site->id,
            'brand_id' => $site->brand_id,
            'suggestion_id' => $suggestion?->id,
            'status' => 'queued',
            'request_payload' => ['draft' => [
                'title' => mb_substr((string) $draft['title'], 0, 200), 'content_html' => (string) ($draft['content_html'] ?? ''),
                'post_type' => in_array($draft['post_type'] ?? 'post', ['post', 'page'], true) ? $draft['post_type'] : 'post',
                'excerpt' => mb_substr((string) ($draft['excerpt'] ?? ''), 0, 300), 'reference' => (string) $draft['reference'],
            ]],
            'requested_by' => $user->id,
        ]));
    }

    /**
     * ADR-068: Admin-approved install of one update WordPress offers (plugin file, theme stylesheet or core).
     * Not undoable from MoxDOP; the plugin must have updates switched on by the site admin.
     */
    public function requestUpdate(User $user, DigitalAsset $site, string $type, string $item, ?string $label = null): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_WORDPRESS);
        if (! in_array($type, ['plugin', 'theme', 'core'], true) || ($type !== 'core' && (trim($item) === '' || strlen($item) > 200))) {
            throw ValidationException::withMessages(['write' => 'Geçersiz güncelleme.']);
        }
        try {
            app(WordPressManagementService::class)->connection((int) $site->id);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['write' => $exception->getMessage()]);
        }
        $busy = ExternalWriteAction::query()->where('digital_asset_id', $site->id)->where('action', ExternalWriteAction::ACTION_UPDATE_APPLY)
            ->whereIn('status', ['queued', 'running'])->exists();
        if ($busy) {
            throw ValidationException::withMessages(['write' => 'Bu sitede bir güncelleme zaten sürüyor; bitmesini bekle.']);
        }

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS,
            'action' => ExternalWriteAction::ACTION_UPDATE_APPLY,
            'digital_asset_id' => $site->id,
            'brand_id' => $site->brand_id,
            'status' => 'queued',
            'request_payload' => ['type' => $type, 'item' => $type === 'core' ? '' : $item, 'label' => $label],
            'requested_by' => $user->id,
        ]));
    }

    /**
     * 1.4.1: Admin-approved update of the MoxDOP Connector itself to the version this MoxDOP ships. The site must run
     * ≥ self_update_min_plugin_version (older sites are updated by hand once). Not undoable.
     */
    public function requestConnectorUpdate(User $user, DigitalAsset $site): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_WORDPRESS);
        try {
            $state = app(WordPressManagementService::class)->connectorUpdateState((int) $site->id);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['write' => $exception->getMessage()]);
        }
        if (! $state['available']) {
            throw ValidationException::withMessages(['write' => $state['reason']]);
        }
        $busy = ExternalWriteAction::query()->where('digital_asset_id', $site->id)
            ->whereIn('action', [ExternalWriteAction::ACTION_CONNECTOR_UPDATE, ExternalWriteAction::ACTION_UPDATE_APPLY])
            ->whereIn('status', ['queued', 'running'])->exists();
        if ($busy) {
            throw ValidationException::withMessages(['write' => 'Bu sitede bir güncelleme zaten sürüyor; bitmesini bekle.']);
        }

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS,
            'action' => ExternalWriteAction::ACTION_CONNECTOR_UPDATE,
            'digital_asset_id' => $site->id,
            'brand_id' => $site->brand_id,
            'status' => 'queued',
            'request_payload' => ['from' => $state['current'], 'to' => $state['latest'], 'label' => 'MoxDOP Connector'],
            'requested_by' => $user->id,
        ]));
    }

    /**
     * ADR-070: Admin-approved batch of site fixes (SEO title / description, alt text, schema, redirect, noindex,
     * canonical, internal link) for one site in one request.
     *
     * @param  list<array{type: string, object_id?: int, reference: string, value?: mixed, from?: string}>  $changes
     */
    public function requestSiteFixes(User $user, DigitalAsset $site, array $changes, ?Suggestion $suggestion = null): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_WORDPRESS);
        $changes = array_values(array_filter($changes, fn (mixed $change): bool => is_array($change) && filled($change['type'] ?? null) && filled($change['reference'] ?? null)
            && (in_array($change['type'], ['noindex', 'canonical', 'schema'], true) || (($change['value'] ?? null) !== null && ($change['value'] ?? null) !== ''))));
        if ($changes === []) {
            throw ValidationException::withMessages(['write' => 'Önerilen değeri olan düzeltme yok.']);
        }
        if (count($changes) > 100) {
            throw ValidationException::withMessages(['write' => 'Tek seferde en fazla 100 düzeltme gönderilebilir.']);
        }
        $this->fixConnection($site, collect($changes)->contains(fn (array $c): bool => in_array($c['type'], ['redirect', 'merge_redirect'], true)));

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => ExternalWriteAction::ACTION_SITE_FIX,
            'digital_asset_id' => $site->id, 'brand_id' => $site->brand_id, 'suggestion_id' => $suggestion?->id, 'status' => 'queued',
            'request_payload' => ['changes' => array_map(fn (array $change): array => WordPressFixWriter::change($change), $changes)],
            'requested_by' => $user->id,
        ]));
    }

    /**
     * ADR-070: the approved new version of a page goes to WordPress as a draft copy (object_id + title + html), or a
     * whole new page as an ArticleDraft (ADR-076 fields). Every text passes the sector compliance gate first.
     *
     * @param  array{object_id?: int, title?: string, html?: string, reference: string, article?: array<string, mixed>}  $payload
     */
    public function requestContentDraft(User $user, DigitalAsset $site, array $payload, ?Suggestion $suggestion = null): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_WORDPRESS);
        $article = is_array($payload['article'] ?? null) ? ArticleDraft::fromArray($payload['article']) : null;
        if ($article === null && (blank($payload['html'] ?? null) || (int) ($payload['object_id'] ?? 0) < 1)) {
            throw ValidationException::withMessages(['write' => 'Bu öneride gönderilecek metin yok.']);
        }
        $this->assertArticleCompliant($site->brand_id, $article ?? ArticleDraft::fromArray(['title' => (string) ($payload['title'] ?? 'Sayfa'), 'html' => (string) $payload['html'], 'reference' => (string) ($payload['reference'] ?? 'content')]));
        $this->fixConnection($site);

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => ExternalWriteAction::ACTION_CONTENT_DRAFT,
            'digital_asset_id' => $site->id, 'brand_id' => $site->brand_id, 'suggestion_id' => $suggestion?->id, 'status' => 'queued',
            'request_payload' => array_filter([
                'object_id' => (int) ($payload['object_id'] ?? 0) ?: null, 'title' => $payload['title'] ?? null, 'html' => $article === null ? (string) $payload['html'] : null,
                'reference' => (string) ($payload['reference'] ?? ($article?->reference ?? 'content')), 'article' => $article?->toArray(),
            ], fn (mixed $v): bool => $v !== null),
            'requested_by' => $user->id,
        ]));
    }

    /**
     * ADR-076: Admin-approved article drafts, the source language first and each translation linked to it (Polylang).
     * Every version passes the compliance gate; the site must run the connector ≥ rich_drafts_min_plugin_version.
     * Use ContentDraftPublisher::publish() rather than calling this directly.
     *
     * @param  list<ArticleDraft>  $translations  each with its own language, different from the source's
     */
    public function requestArticleDrafts(User $user, DigitalAsset $site, ArticleDraft $source, array $translations = []): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_WORDPRESS);
        foreach ([$source, ...$translations] as $article) {
            $this->assertArticleCompliant($site->brand_id, $article, $article->language !== null ? 'Metin ('.$article->language.')' : 'Metin');
        }
        try {
            $this->drafts->richConnection((int) $site->id);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['write' => $exception->getMessage()]);
        }
        $busy = ExternalWriteAction::query()->where('digital_asset_id', $site->id)->where('action', ExternalWriteAction::ACTION_ARTICLE_DRAFTS)
            ->whereIn('status', ['queued', 'running', 'undoing'])->where('request_payload->reference', $source->reference)->exists();
        if ($busy) {
            throw ValidationException::withMessages(['write' => 'Bu makale için bir gönderim zaten sürüyor.']);
        }
        $drafts = [['language' => $source->language, 'draft' => WordPressDraftWriter::payload($source, brandId: $site->brand_id)]];
        foreach ($translations as $translation) {
            $drafts[] = ['language' => $translation->language, 'draft' => WordPressDraftWriter::payload($translation, brandId: $site->brand_id)];
        }

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => ExternalWriteAction::ACTION_ARTICLE_DRAFTS,
            'digital_asset_id' => $site->id, 'brand_id' => $site->brand_id, 'status' => 'queued',
            'request_payload' => ['reference' => $source->reference, 'label' => $source->title, 'languages' => array_column($drafts, 'language'), 'drafts' => $drafts],
            'requested_by' => $user->id,
        ]));
    }

    private function assertArticleCompliant(?int $brandId, ArticleDraft $article, string $what = 'Metin'): void
    {
        try {
            app(ContentComplianceGate::class)->assertCompliant($brandId !== null ? Brand::query()->find($brandId) : null, $article, $what);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['write' => (string) collect($exception->errors())->flatten()->first()]);
        }
    }

    /** ADR-070: second approval, the draft copy (possibly edited in WordPress) replaces the live page. */
    public function requestContentApply(User $user, DigitalAsset $site, int $draftId, ?Suggestion $suggestion = null): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_WORDPRESS);
        if ($draftId < 1) {
            throw ValidationException::withMessages(['write' => 'Önce yeni sürümü WordPress’e taslak olarak gönder.']);
        }
        $this->fixConnection($site);

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => ExternalWriteAction::ACTION_CONTENT_APPLY,
            'digital_asset_id' => $site->id, 'brand_id' => $site->brand_id, 'suggestion_id' => $suggestion?->id, 'status' => 'queued',
            'request_payload' => ['draft_id' => $draftId],
            'requested_by' => $user->id,
        ]));
    }

    /** ADR-073: Admin-approved reply to one Google review of a bound Business Profile location. */
    public function requestReviewReply(User $user, GbpReview $review, string $comment): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_GBP);
        $comment = trim($comment);
        if ($comment === '' || mb_strlen($comment) > (int) config('moxdop-external-writes.gbp.max_reply_length', 4000)) {
            throw ValidationException::withMessages(['write' => 'Yanıt boş olamaz ve 4000 karakteri geçemez.']);
        }
        $assetId = CoreAssetBinding::query()->where('external_resource_id', $review->external_resource_id)->where('capability', 'google_business_profile')
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)->value('digital_asset_id');
        $asset = $assetId !== null ? DigitalAsset::query()->find($assetId) : null;
        if ($asset === null) {
            throw ValidationException::withMessages(['write' => 'Bu yorumun konumu bir markaya bağlı değil.']);
        }
        $this->gbpLocation($asset);
        $busy = ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_REVIEW_REPLY)->whereIn('status', ['queued', 'running', 'undoing'])
            ->where('request_payload->review_id', $review->id)->exists();
        if ($busy) {
            throw ValidationException::withMessages(['write' => 'Bu yoruma bir yanıt zaten gönderiliyor.']);
        }

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_GBP, 'action' => ExternalWriteAction::ACTION_REVIEW_REPLY,
            'digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id, 'status' => 'queued',
            'request_payload' => ['review_id' => $review->id, 'comment' => $comment, 'label' => 'Yorum yanıtı'],
            'requested_by' => $user->id,
        ]));
    }

    /**
     * ADR-073: Admin-approved Business Profile local post, now or at `publish_at` (Europe/Istanbul; the approved post
     * waits as `scheduled` and `releaseScheduled()` sends it when due — Google has no scheduling of its own).
     *
     * ADR-078: a post of the automatic queue also carries its image (https JPG / PNG) and `queue_id`.
     *
     * @param  array{summary: string, url?: ?string, action_type?: ?string, publish_at?: ?string, image_url?: ?string, queue_id?: int}  $post
     */
    public function requestLocalPost(User $user, DigitalAsset $asset, array $post): ExternalWriteAction
    {
        $publishAt = null;
        if (filled($post['publish_at'] ?? null)) {
            try {
                $publishAt = CarbonImmutable::parse((string) $post['publish_at'], 'Europe/Istanbul');
            } catch (Throwable) {
                throw ValidationException::withMessages(['write' => 'Yayın zamanı geçerli değil.']);
            }
            if ($publishAt->lessThan(now()->subMinute())) {
                throw ValidationException::withMessages(['write' => 'Yayın zamanı geçmişte olamaz.']);
            }
            if ($publishAt->greaterThan(now()->addDays(90))) {
                throw ValidationException::withMessages(['write' => 'Yayın zamanı en çok 90 gün sonrası olabilir.']);
            }
            if ($publishAt->lessThanOrEqualTo(now()->addMinutes(2))) {
                $publishAt = null;
            }
        }
        $this->guard($user, ExternalWriteAction::CHANNEL_GBP);
        $summary = trim((string) ($post['summary'] ?? ''));
        if ($asset->type !== 'google_business_profile' || $summary === '' || mb_strlen($summary) > (int) config('moxdop-external-writes.gbp.max_post_length', 1500)) {
            throw ValidationException::withMessages(['write' => 'Gönderi metni boş olamaz ve 1500 karakteri geçemez; hedef bir İşletme Profili olmalı.']);
        }
        $url = trim((string) ($post['url'] ?? ''));
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages(['write' => 'Bağlantı geçerli bir adres değil.']);
        }
        $image = trim((string) ($post['image_url'] ?? ''));
        if ($image !== '' && preg_match('~^https://\S+$~i', $image) !== 1) {
            throw ValidationException::withMessages(['write' => 'Görsel adresi https olmalı.']);
        }
        $this->gbpLocation($asset);
        $action = ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_GBP, 'action' => ExternalWriteAction::ACTION_LOCAL_POST,
            'digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id, 'status' => $publishAt !== null ? 'scheduled' : 'queued',
            'request_payload' => ['summary' => $summary, 'url' => $url !== '' ? $url : null, 'action_type' => in_array($post['action_type'] ?? null, ['LEARN_MORE', 'BOOK', 'CALL', 'ORDER', 'SIGN_UP'], true) ? $post['action_type'] : 'LEARN_MORE',
                'label' => isset($post['queue_id']) ? 'Otomatik gönderi' : 'İşletme Profili gönderisi', 'publish_at' => $publishAt?->utc()->toIso8601String()]
                + array_filter(['image_url' => $image !== '' ? $image : null, 'queue_id' => isset($post['queue_id']) ? (int) $post['queue_id'] : null]),
            'requested_by' => $user->id,
        ]);

        return $publishAt !== null ? $action : $this->queue($action);
    }

    /**
     * ADR-077: the Admin sends the chosen rows of a "Kategori ve hizmetler" plan to the profile (additions only). Only
     * rows the plan marked new; a service under a new category needs that category chosen too; edited descriptions
     * are cut to 300 characters and checked against the sector rules again.
     *
     * @param  list<string>  $categoryIds
     * @param  list<int>  $serviceIndexes  indexes into the plan's services
     * @param  array<int|string, string>  $descriptions  edited descriptions by service index
     */
    public function requestProfileUpdate(User $user, DigitalAsset $asset, AiProduction $plan, array $categoryIds, array $serviceIndexes, array $descriptions = []): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_GBP);
        if ($plan->kind !== GbpProfilePlanner::KIND || $plan->subject_type !== 'DigitalAsset' || (int) $plan->subject_id !== (int) $asset->id || $plan->status !== AiProduction::STATUS_NEW) {
            throw ValidationException::withMessages(['write' => 'Bu hazırlık artık geçerli değil; yeniden hazırlayın.']);
        }
        $content = (array) $plan->content;
        $categories = array_values(array_filter((array) ($content['categories'] ?? []), fn (array $c): bool => $c['status'] === 'new' && in_array($c['id'], $categoryIds, true)));
        $chosen = array_column($categories, 'id');
        $newIds = array_column(array_filter((array) ($content['categories'] ?? []), fn (array $c): bool => $c['status'] === 'new'), 'name', 'id');
        $services = [];
        $problems = [];
        foreach ((array) ($content['services'] ?? []) as $index => $service) {
            if ($service['status'] !== 'new' || ! in_array((int) $index, array_map('intval', $serviceIndexes), true)) {
                continue;
            }
            if (isset($newIds[$service['category_id']]) && ! in_array($service['category_id'], $chosen, true)) {
                $problems[] = '«'.$service['name'].'» için «'.$newIds[$service['category_id']].'» kategorisini de seçin.';

                continue;
            }
            $description = GbpProfilePlanner::description((string) ($descriptions[$index] ?? $service['description']));
            $blocking = GbpAssistant::blockingHits($asset->loadMissing('brand')->brand, $service['name'].' '.$description);
            if ($blocking !== []) {
                $problems[] = '«'.$service['name'].'» sektör uyum kuralına takılıyor: '.implode(', ', $blocking).'.';

                continue;
            }
            $services[] = ['category_id' => $service['category_id'], 'service_type_id' => $service['service_type_id'], 'name' => $service['name'], 'description' => $description];
        }
        if ($problems !== []) {
            throw ValidationException::withMessages(['write' => implode(' ', $problems)]);
        }
        if ($categories === [] && $services === []) {
            throw ValidationException::withMessages(['write' => 'Gönderilecek kategori ya da hizmet seçin.']);
        }
        $busy = ExternalWriteAction::query()->where('digital_asset_id', $asset->id)->where('action', ExternalWriteAction::ACTION_PROFILE_UPDATE)
            ->whereIn('status', ['queued', 'running', 'undoing'])->exists();
        if ($busy) {
            throw ValidationException::withMessages(['write' => 'Bu profile bir ekleme zaten gönderiliyor.']);
        }
        $this->gbpLocation($asset);
        $action = ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_GBP, 'action' => ExternalWriteAction::ACTION_PROFILE_UPDATE,
            'digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id, 'status' => 'queued',
            'request_payload' => ['plan_id' => $plan->id, 'categories' => array_map(fn (array $c): array => ['id' => $c['id'], 'name' => $c['name']], $categories),
                'services' => $services, 'label' => 'Kategori ve hizmet ekleme'],
            'requested_by' => $user->id,
        ]);
        $plan->forceFill(['status' => AiProduction::STATUS_USED, 'status_changed_by' => $user->id, 'status_changed_at' => now()])->save();

        return $this->queue($action);
    }

    /**
     * ADR-079: the Admin sends the description, special hours (dates in the next 400 days) and / or website link to the
     * profile. The description passes the same checks as AI texts (≤ 750 characters, no contact data, sector rules); the
     * website link must be https on one of the brand's own sites.
     *
     * @param  array{description?: string, special_hours?: list<array{date: string, closed?: bool, open?: string, close?: string}>, website_uri?: string}  $fields
     */
    public function requestProfileFields(User $user, DigitalAsset $asset, array $fields, string $label, ?Suggestion $suggestion = null): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_GBP);
        if ($asset->type !== 'google_business_profile') {
            throw ValidationException::withMessages(['write' => 'Hedef bir İşletme Profili olmalı.']);
        }
        $clean = [];
        if (array_key_exists('description', $fields)) {
            $text = trim((string) $fields['description']);
            if (mb_strlen($text) < 100 || mb_strlen($text) > GbpAssistant::DESCRIPTION_MAX) {
                throw ValidationException::withMessages(['write' => 'Açıklama 100–'.GbpAssistant::DESCRIPTION_MAX.' karakter olmalı.']);
            }
            if (preg_match('~https?://|www\.|[\w.+-]+@[\w-]+\.[\w.]+|\+?\d[\d\s().-]{8,}\d~iu', $text) === 1) {
                throw ValidationException::withMessages(['write' => 'Açıklamada telefon, e-posta ya da bağlantı olamaz (Google kuralı).']);
            }
            $blocking = GbpAssistant::blockingHits($asset->loadMissing('brand')->brand, $text);
            if ($blocking !== []) {
                throw ValidationException::withMessages(['write' => 'Açıklama sektör uyum kuralına takılıyor: '.implode(', ', $blocking).'.']);
            }
            $clean['description'] = $text;
        }
        if (array_key_exists('special_hours', $fields)) {
            $rows = [];
            foreach ((array) $fields['special_hours'] as $row) {
                $period = is_array($row) ? GbpWriter::specialPeriod($row) : null;
                $date = (string) data_get($row, 'date', '');
                if ($period === null || $date < now('Europe/Istanbul')->toDateString() || $date > now('Europe/Istanbul')->addDays(400)->toDateString()) {
                    throw ValidationException::withMessages(['write' => 'Özel gün satırı geçersiz: '.$date.' (gelecek bir tarih; kapalı ya da açılış < kapanış saati).']);
                }
                $rows[$date] = array_filter(['date' => $date, 'closed' => (bool) ($row['closed'] ?? false) ?: null,
                    'open' => ($row['closed'] ?? false) ? null : (string) $row['open'], 'close' => ($row['closed'] ?? false) ? null : (string) $row['close']], fn (mixed $v): bool => $v !== null);
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['write' => 'Gönderilecek özel gün yok.']);
            }
            ksort($rows);
            $clean['special_hours'] = array_values($rows);
        }
        if (array_key_exists('website_uri', $fields)) {
            $uri = trim((string) $fields['website_uri']);
            $host = strtolower((string) parse_url($uri, PHP_URL_HOST));
            $own = DigitalAsset::query()->where('brand_id', $asset->brand_id)->where('type', 'website')->get(['domain', 'primary_url'])
                ->map(fn (DigitalAsset $site): string => preg_replace('/^www\./', '', strtolower((string) ($site->domain ?: parse_url((string) $site->primary_url, PHP_URL_HOST)))))->filter()->all();
            if (preg_match('~^https://\S+$~i', $uri) !== 1 || ! in_array(preg_replace('/^www\./', '', $host), $own, true)) {
                throw ValidationException::withMessages(['write' => 'Web sitesi bağlantısı markanın kendi sitesinde bir https adresi olmalı.']);
            }
            $clean['website_uri'] = $uri;
        }
        $clean += $this->profileInfoFields($asset, $fields);
        if ($clean === []) {
            throw ValidationException::withMessages(['write' => 'Gönderilecek alan yok.']);
        }
        $busy = ExternalWriteAction::query()->where('digital_asset_id', $asset->id)->where('action', ExternalWriteAction::ACTION_PROFILE_FIELDS)
            ->whereIn('status', ['queued', 'running', 'undoing'])->exists();
        if ($busy) {
            throw ValidationException::withMessages(['write' => 'Bu profile bir güncelleme zaten gönderiliyor; bitince tekrar deneyin.']);
        }
        $this->gbpLocation($asset);

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_GBP, 'action' => ExternalWriteAction::ACTION_PROFILE_FIELDS,
            'digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id, 'suggestion_id' => $suggestion?->id, 'status' => 'queued',
            'request_payload' => ['fields' => $clean, 'label' => mb_substr($label, 0, 120)],
            'requested_by' => $user->id,
        ]));
    }

    /**
     * ADR-080 fields: regular hours (day rows, at least one open day), primary phone, primary category (a Google
     * category id), yes / no attributes Google offers for the profile, appointment link (https).
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function profileInfoFields(DigitalAsset $asset, array $fields): array
    {
        $clean = [];
        if (array_key_exists('regular_hours', $fields)) {
            $rows = [];
            foreach ((array) $fields['regular_hours'] as $row) {
                if (! is_array($row) || GbpWriter::regularPeriods([$row]) === []) {
                    throw ValidationException::withMessages(['write' => 'Çalışma saati satırı geçersiz: '.(is_array($row) ? implode(' ', array_map('strval', $row)) : '').' (gün ve SS:DD açılış / kapanış).']);
                }
                $rows[] = ['day' => strtoupper((string) $row['day']), 'open' => (string) $row['open'], 'close' => (string) $row['close']];
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['write' => 'En az bir gün açık olmalı.']);
            }
            $clean['regular_hours'] = $rows;
        }
        if (array_key_exists('phone', $fields)) {
            $phone = trim(preg_replace('/\s+/', ' ', (string) $fields['phone']) ?? '');
            $digits = (string) preg_replace('/\D+/', '', $phone);
            if (preg_match('/^\+?[\d\s().-]+$/', $phone) !== 1 || strlen($digits) < 10 || strlen($digits) > 15) {
                throw ValidationException::withMessages(['write' => 'Telefon numarası geçersiz (10–15 rakam).']);
            }
            $clean['phone'] = $phone;
        }
        if (array_key_exists('primary_category', $fields)) {
            $id = trim((string) data_get($fields, 'primary_category.id', ''));
            if (preg_match('#^categories/gcid:[a-z0-9_]+$#', $id) !== 1) {
                throw ValidationException::withMessages(['write' => 'Birincil kategori Google listesinden seçilmeli.']);
            }
            $clean['primary_category'] = ['id' => $id, 'name' => mb_substr(trim((string) data_get($fields, 'primary_category.name', '')), 0, 120)];
        }
        if (array_key_exists('attributes', $fields)) {
            $offered = collect(GoogleAdsAdvisorInputCollector::decode(DB::table('gbp_attribute_snapshots')->where('digital_asset_id', $asset->id)->latest('id')->value('available_attributes')))
                ->filter(fn (mixed $a): bool => is_array($a) && ($a['valueType'] ?? '') === 'BOOL' && ! (bool) ($a['deprecated'] ?? false))
                ->pluck('parent')->map(fn (mixed $n): string => (string) $n)->all();
            $rows = [];
            foreach ((array) $fields['attributes'] as $attribute) {
                $name = (string) data_get($attribute, 'name', '');
                if (preg_match('#^attributes/[a-z0-9_]+$#', $name) !== 1 || ! in_array($name, $offered, true)) {
                    throw ValidationException::withMessages(['write' => 'Özellik bu profil için Google listesinde yok: '.$name.'.']);
                }
                $rows[$name] = ['name' => $name, 'value' => (bool) data_get($attribute, 'value')];
            }
            if ($rows === [] || count($rows) > 40) {
                throw ValidationException::withMessages(['write' => 'Gönderilecek özellik sayısı 1–40 olmalı.']);
            }
            $clean['attributes'] = array_values($rows);
        }
        if (array_key_exists('appointment_url', $fields)) {
            $uri = trim((string) $fields['appointment_url']);
            if (preg_match('~^https://[^\s/]+\.[^\s/]+\S*$~i', $uri) !== 1 || mb_strlen($uri) > 500) {
                throw ValidationException::withMessages(['write' => 'Randevu bağlantısı https ile başlayan bir adres olmalı.']);
            }
            $clean['appointment_url'] = $uri;
        }

        return $clean;
    }

    /**
     * ADR-080: the Admin adds one video to the profile from an https MP4 / MOV address (Google fetches it itself).
     */
    public function requestVideo(User $user, DigitalAsset $asset, string $sourceUrl): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_GBP);
        $sourceUrl = trim($sourceUrl);
        if ($asset->type !== 'google_business_profile' || preg_match('~^https://\S+\.(mp4|mov)(\?\S*)?$~i', $sourceUrl) !== 1) {
            throw ValidationException::withMessages(['write' => 'Video https ile başlayan bir MP4 / MOV adresi olmalı; hedef bir İşletme Profili olmalı.']);
        }
        $this->gbpLocation($asset);

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_GBP, 'action' => ExternalWriteAction::ACTION_MEDIA_UPLOAD,
            'digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id, 'status' => 'queued',
            'request_payload' => ['source_url' => $sourceUrl, 'category' => 'ADDITIONAL', 'media_format' => 'VIDEO', 'label' => 'Video ekleme'],
            'requested_by' => $user->id,
        ]));
    }

    /**
     * ADR-079: the Admin adds one photo to the profile from an https JPG / PNG address (the brand's site or MoxDOP's
     * own public storage). Google fetches the file itself.
     */
    public function requestPhoto(User $user, DigitalAsset $asset, string $sourceUrl, string $category = 'ADDITIONAL', ?int $photoId = null): ExternalWriteAction
    {
        $this->guard($user, ExternalWriteAction::CHANNEL_GBP);
        $sourceUrl = trim($sourceUrl);
        if ($asset->type !== 'google_business_profile' || preg_match('~^https://\S+\.(jpe?g|png)(\?\S*)?$~i', $sourceUrl) !== 1) {
            throw ValidationException::withMessages(['write' => 'Fotoğraf https ile başlayan bir JPG / PNG adresi olmalı; hedef bir İşletme Profili olmalı.']);
        }
        if (! in_array($category, ['ADDITIONAL', 'EXTERIOR', 'INTERIOR', 'PRODUCT', 'AT_WORK', 'TEAMS', 'LOGO', 'COVER'], true)) {
            throw ValidationException::withMessages(['write' => 'Fotoğraf türü geçersiz.']);
        }
        $this->gbpLocation($asset);

        return $this->queue(ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_GBP, 'action' => ExternalWriteAction::ACTION_MEDIA_UPLOAD,
            'digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id, 'status' => 'queued',
            'request_payload' => array_filter(['source_url' => $sourceUrl, 'category' => $category, 'photo_id' => $photoId, 'label' => 'Fotoğraf ekleme'], fn (mixed $v): bool => $v !== null),
            'requested_by' => $user->id,
        ]));
    }

    /** Sends the scheduled (already Admin-approved) Business Profile posts whose time has come. */
    public function releaseScheduled(): int
    {
        $released = 0;
        ExternalWriteAction::query()->where('status', 'scheduled')->where('action', ExternalWriteAction::ACTION_LOCAL_POST)->orderBy('id')->limit(200)->get()
            ->each(function (ExternalWriteAction $action) use (&$released): void {
                $at = data_get($action->request_payload, 'publish_at');
                if (is_string($at) && CarbonImmutable::parse($at)->greaterThan(now())) {
                    return;
                }
                if (ExternalWriteAction::query()->whereKey($action->id)->where('status', 'scheduled')->update(['status' => 'queued', 'updated_at' => now()]) === 1) {
                    $this->queue($action->refresh());
                    $released++;
                }
            });

        return $released;
    }

    /** Admin cancels a scheduled post before it is sent. */
    public function cancelScheduled(User $user, ExternalWriteAction $action): void
    {
        $this->guard($user, $action->channel);
        if (ExternalWriteAction::query()->whereKey($action->id)->where('status', 'scheduled')->update(['status' => 'cancelled', 'finished_at' => now(), 'updated_at' => now()]) !== 1) {
            throw ValidationException::withMessages(['write' => 'Bu gönderi artık zamanlanmış değil.']);
        }
    }

    private function gbpLocation(DigitalAsset $asset): void
    {
        try {
            $this->gbp->location((int) $asset->id);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['write' => $exception->getMessage()]);
        }
    }

    public function requestUndo(User $user, ExternalWriteAction $action): ExternalWriteAction
    {
        $this->guard($user, $action->channel);
        if (! $action->isUndoable()) {
            throw ValidationException::withMessages(['write' => 'Bu işlem geri alınamaz.']);
        }
        $action->forceFill(['status' => 'undoing', 'undone_by' => $user->id])->save();
        $this->dispatch($action, undo: true);

        return $action;
    }

    /** Executed by the job. */
    public function execute(ExternalWriteAction $action): void
    {
        $action->forceFill(['status' => 'running', 'started_at' => now()])->save();
        try {
            $result = match (true) {
                $action->action === ExternalWriteAction::ACTION_ADS_CHANGE => app(GoogleAdsChangeWriter::class)->apply($action),
                $action->channel === ExternalWriteAction::CHANNEL_GOOGLE_ADS => $this->negatives->apply($action),
                $action->channel === ExternalWriteAction::CHANNEL_GBP => $this->gbp->apply($action),
                $action->action === ExternalWriteAction::ACTION_UPDATE_APPLY => app(WordPressManagementService::class)->apply($action),
                $action->action === ExternalWriteAction::ACTION_CONNECTOR_UPDATE => app(WordPressManagementService::class)->selfUpdate($action),
                $action->action === ExternalWriteAction::ACTION_SITE_BUILD => app(WordPressSiteBuilder::class)->apply($action),
                in_array($action->action, self::FIX_ACTIONS, true) => $this->fixes->apply($action),
                default => $this->drafts->apply($action),
            };
            $action->forceFill(['status' => $result['status'] ?? 'succeeded', 'result' => $result, 'finished_at' => now(), 'error' => self::changeErrors($result)])->save();
        } catch (Throwable $exception) {
            $action->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($exception->getMessage(), 0, 500)])->save();
        }
        if ($action->action === ExternalWriteAction::ACTION_NEGATIVE_LIST_ADD) {
            // Shared-list negatives become "Uygulandı" only once Google accepted them.
            app(GoogleAdsSuggestions::class)->writeFinished($action);
        }
        if ($action->action === ExternalWriteAction::ACTION_ADS_CHANGE) {
            app(GoogleAdsChanges::class)->writeFinished($action);
        }
        if ($action->action === ExternalWriteAction::ACTION_SITE_FIX) {
            // "301 ile birleştir" becomes applied only once the site confirmed it (else open again with the error).
            app(ClusterOverlaps::class)->writeFinished($action);
        }
        if ($action->action === ExternalWriteAction::ACTION_ARTICLE_DRAFTS && str_starts_with((string) data_get($action->request_payload, 'reference'), 'gbp-branch-')) {
            // ADR-079: the branch page draft gets its local-business markup (same approval).
            app(BranchPages::class)->draftFinished($action);
        }
        if ($action->action === ExternalWriteAction::ACTION_MEDIA_UPLOAD) {
            app(PhotoPlan::class)->writeFinished($action);
        }
        if ($action->action === ExternalWriteAction::ACTION_PROFILE_FIELDS && $action->suggestion_id !== null && in_array($action->status, ['succeeded', 'partial'], true)) {
            // The description suggestion that was sent is done (outcome baseline from today).
            $suggestion = Suggestion::query()->find($action->suggestion_id);
            if ($suggestion !== null && $suggestion->status !== Suggestion::APPLIED) {
                app(GbpSuggestions::class)->markApplied($suggestion, User::query()->find($action->requested_by));
            }
        }
    }

    /**
     * A site write the site answered but did not (fully) carry out: the reasons the site gave per change, so the desk
     * and the health check show why instead of an empty "Başarısız".
     *
     * @param  array<string, mixed>  $result
     */
    public static function changeErrors(array $result): ?string
    {
        if (($result['status'] ?? 'succeeded') === 'succeeded') {
            return null;
        }
        $errors = collect((array) ($result['changes'] ?? []))->map(fn (mixed $c): string => is_array($c) ? trim((string) ($c['error'] ?? '')) : '')
            ->filter()->countBy()->map(fn (int $n, string $e): string => $n > 1 ? $e.' ('.$n.' değişiklik)' : $e)->values();

        return $errors->isEmpty() ? null : mb_substr($errors->implode(' · '), 0, 500);
    }

    public function executeUndo(ExternalWriteAction $action): void
    {
        try {
            $undo = match (true) {
                $action->action === ExternalWriteAction::ACTION_ADS_CHANGE => app(GoogleAdsChangeWriter::class)->undo($action),
                $action->channel === ExternalWriteAction::CHANNEL_GOOGLE_ADS => $this->negatives->undo($action),
                $action->channel === ExternalWriteAction::CHANNEL_GBP => $this->gbp->undo($action),
                $action->action === ExternalWriteAction::ACTION_SITE_BUILD => app(WordPressSiteBuilder::class)->undo($action),
                in_array($action->action, self::FIX_ACTIONS, true) => $this->fixes->undo($action),
                default => $this->drafts->undo($action),
            };
            $action->forceFill(['status' => 'undone', 'undone_at' => now(), 'result' => array_merge($action->result ?? [], ['undo' => $undo]), 'error' => null])->save();
            app(ClusterOverlaps::class)->writeUndone($action);
            if ($action->action === ExternalWriteAction::ACTION_MEDIA_UPLOAD) {
                app(PhotoPlan::class)->writeFinished($action);
            }
        } catch (Throwable $exception) {
            $action->forceFill(['status' => 'undo_failed', 'error' => mb_substr($exception->getMessage(), 0, 500)])->save();
        }
    }

    /** @param  bool  $redirects  the batch writes a 301: it is kept by the connector itself, which needs 1.12.0 */
    private function fixConnection(?DigitalAsset $site, bool $redirects = false): void
    {
        try {
            $connection = app(WordPressDraftWriter::class)->connection((int) $site?->id);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['write' => $exception->getMessage()]);
        }
        $version = (string) data_get($connection->config, 'plugin_version', '0.0.0');
        if (version_compare($version, (string) config('moxdop-wordpress.fixes_min_plugin_version', '1.4.0'), '<')) {
            throw ValidationException::withMessages(['write' => 'WordPress Connector '.$version.'; site düzeltmeleri için en az '.config('moxdop-wordpress.fixes_min_plugin_version', '1.4.0').' gerekli. Eklentiyi güncelle ve eklenti ayarlarında "SEO fixes" / "Content updates" seçeneğini aç.']);
        }
        $minimum = (string) config('moxdop-wordpress.merge_redirect_min_plugin_version', '1.12.0');
        if ($redirects && version_compare($version, $minimum, '<')) {
            throw ValidationException::withMessages(['write' => 'WordPress Connector '.$version.'; 301 yalnızca MoxDOP eklentisine yazılır, bunun için en az '.$minimum.' gerekli. Web siteleri › eklentiyi güncelle.']);
        }
    }

    private function guard(User $user, string $channel): void
    {
        if (! (bool) config('moxdop-external-writes.enabled', true) || ! (bool) config('moxdop-external-writes.'.$channel.'.enabled', true)) {
            throw ValidationException::withMessages(['write' => 'Harici yazma kapalı (EXTERNAL_WRITES_ENABLED).']);
        }
        if (! self::allowed($user, $channel)) {
            abort(403, 'Harici yazmayı yalnız Admin onaylayabilir.');
        }
    }

    private function queue(ExternalWriteAction $action): ExternalWriteAction
    {
        $this->dispatch($action, undo: false);

        return $action;
    }

    private function dispatch(ExternalWriteAction $action, bool $undo): void
    {
        dispatch(new ExecuteExternalWriteJob($action->id, $undo))
            ->onConnection((string) config('moxdop-external-writes.queue_connection', config('queue.default')))
            ->onQueue((string) config('moxdop-external-writes.queue', 'default'));
    }
}

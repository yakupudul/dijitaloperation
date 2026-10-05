<?php

namespace App\Services\ExternalWrites;

use App\Jobs\ExecuteExternalWriteJob;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpReview;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\GoogleAds\GoogleAdsSuggestions;
use App\Services\Integrations\WordPress\WordPressManagementService;
use App\Services\Integrations\WordPress\WordPressSiteBuilder;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Entry point for every approved external write (ADR-064 / 068 / 070 / 071 / 073 / 076). Checks: kill switch, Admin role, eligible source item. Every
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
        $this->fixConnection($site);

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
        $drafts = [['language' => $source->language, 'draft' => WordPressDraftWriter::payload($source)]];
        foreach ($translations as $translation) {
            $drafts[] = ['language' => $translation->language, 'draft' => WordPressDraftWriter::payload($translation)];
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
     * @param  array{summary: string, url?: ?string, action_type?: ?string, publish_at?: ?string}  $post
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
        $this->gbpLocation($asset);
        $action = ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_GBP, 'action' => ExternalWriteAction::ACTION_LOCAL_POST,
            'digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id, 'status' => $publishAt !== null ? 'scheduled' : 'queued',
            'request_payload' => ['summary' => $summary, 'url' => $url !== '' ? $url : null, 'action_type' => in_array($post['action_type'] ?? null, ['LEARN_MORE', 'BOOK', 'CALL', 'ORDER', 'SIGN_UP'], true) ? $post['action_type'] : 'LEARN_MORE',
                'label' => 'İşletme Profili gönderisi', 'publish_at' => $publishAt?->utc()->toIso8601String()],
            'requested_by' => $user->id,
        ]);

        return $publishAt !== null ? $action : $this->queue($action);
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
                $action->channel === ExternalWriteAction::CHANNEL_GOOGLE_ADS => $this->negatives->apply($action),
                $action->channel === ExternalWriteAction::CHANNEL_GBP => $this->gbp->apply($action),
                $action->action === ExternalWriteAction::ACTION_UPDATE_APPLY => app(WordPressManagementService::class)->apply($action),
                $action->action === ExternalWriteAction::ACTION_CONNECTOR_UPDATE => app(WordPressManagementService::class)->selfUpdate($action),
                $action->action === ExternalWriteAction::ACTION_SITE_BUILD => app(WordPressSiteBuilder::class)->apply($action),
                in_array($action->action, self::FIX_ACTIONS, true) => $this->fixes->apply($action),
                default => $this->drafts->apply($action),
            };
            $action->forceFill(['status' => $result['status'] ?? 'succeeded', 'result' => $result, 'finished_at' => now(), 'error' => null])->save();
        } catch (Throwable $exception) {
            $action->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($exception->getMessage(), 0, 500)])->save();
        }
        if ($action->action === ExternalWriteAction::ACTION_NEGATIVE_LIST_ADD) {
            // Shared-list negatives become "Uygulandı" only once Google accepted them.
            app(GoogleAdsSuggestions::class)->writeFinished($action);
        }
    }

    public function executeUndo(ExternalWriteAction $action): void
    {
        try {
            $undo = match (true) {
                $action->channel === ExternalWriteAction::CHANNEL_GOOGLE_ADS => $this->negatives->undo($action),
                $action->channel === ExternalWriteAction::CHANNEL_GBP => $this->gbp->undo($action),
                $action->action === ExternalWriteAction::ACTION_SITE_BUILD => app(WordPressSiteBuilder::class)->undo($action),
                in_array($action->action, self::FIX_ACTIONS, true) => $this->fixes->undo($action),
                default => $this->drafts->undo($action),
            };
            $action->forceFill(['status' => 'undone', 'undone_at' => now(), 'result' => array_merge($action->result ?? [], ['undo' => $undo]), 'error' => null])->save();
        } catch (Throwable $exception) {
            $action->forceFill(['status' => 'undo_failed', 'error' => mb_substr($exception->getMessage(), 0, 500)])->save();
        }
    }

    private function fixConnection(?DigitalAsset $site): void
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

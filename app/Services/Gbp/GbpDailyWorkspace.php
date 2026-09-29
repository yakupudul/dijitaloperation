<?php

namespace App\Services\Gbp;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Run;
use Carbon\CarbonImmutable;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The daily Business Profile workspace (asset page tabs Yorumlar, Gönderiler, Profil sağlığı, Yorum toplama) and the
 * Komuta merkezi Business Profile items. Everything is read from the collected provider tables and the content
 * calendar; nothing here writes to Google (replies and posts go through ExternalWriteService, ADR-073).
 */
final class GbpDailyWorkspace
{
    /** A review older than this without an owner reply is late. */
    public const int REPLY_SLA_HOURS = 48;

    /** Recommended rhythm: one post a week; the command center reminds after two weeks without one. */
    public const int POST_CADENCE_DAYS = 7;

    public const int POST_REMINDER_DAYS = 14;

    public const string MANAGER_URL = 'https://business.google.com/locations';

    private const array STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    /** Rating filter → Google star values. */
    public const array RATING_FILTERS = ['low' => ['ONE', 'TWO'], 'mid' => ['THREE'], 'high' => ['FOUR', 'FIVE']];

    public function resource(DigitalAsset|int $asset): ?CoreExternalResource
    {
        $resourceId = CoreAssetBinding::query()->where('digital_asset_id', $asset instanceof DigitalAsset ? $asset->id : $asset)
            ->where('capability', 'google_business_profile')->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderByDesc('id')->value('external_resource_id');

        return $resourceId !== null ? CoreExternalResource::query()->find($resourceId) : null;
    }

    /**
     * Reviews for the Yorumlar tab: unanswered first (oldest waiting on top), then newest.
     *
     * @return list<array<string, mixed>>
     */
    public function reviews(int $resourceId, string $rating = '', bool $unansweredOnly = false, int $limit = 50): array
    {
        $rows = DB::table('gbp_reviews')->where('external_resource_id', $resourceId)
            ->when(isset(self::RATING_FILTERS[$rating]), fn ($q) => $q->whereIn('star_rating', self::RATING_FILTERS[$rating]))
            ->when($unansweredOnly, fn ($q) => $q->whereNull('review_reply'))
            ->orderByRaw('CASE WHEN review_reply IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('create_time')->limit(max(1, min(200, $limit)))
            ->get(['id', 'reviewer', 'star_rating', 'comment', 'create_time', 'review_reply']);
        $actions = $this->replyActions($rows->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return $rows->map(function (object $row) use ($actions): array {
            $reply = $this->decode($row->review_reply);
            $replied = $reply !== [];
            $created = $row->create_time !== null ? CarbonImmutable::parse((string) $row->create_time) : null;
            $ageHours = $created !== null ? (int) $created->diffInHours(now(), true) : null;

            return [
                'id' => (int) $row->id,
                'reviewer' => (string) ($this->decode($row->reviewer)['displayName'] ?? '—'),
                'rating' => self::STARS[strtoupper((string) $row->star_rating)] ?? null,
                'comment' => trim((string) $row->comment),
                'date' => $created?->toDateString() ?? '',
                'age' => $created !== null ? $this->age($ageHours) : '—',
                'late' => ! $replied && $ageHours !== null && $ageHours > self::REPLY_SLA_HOURS,
                'replied' => $replied,
                'reply' => $replied ? trim((string) ($reply['comment'] ?? '')) : null,
                'action' => $actions[(int) $row->id] ?? null,
            ];
        })->values()->all();
    }

    /**
     * Latest ADR-073 reply action per review (to show "gönderiliyor", the error, or "Geri al").
     *
     * @param  list<int>  $reviewIds
     * @return array<int, array{id: int, status: string, label: string, undoable: bool, error: ?string}>
     */
    public function replyActions(array $reviewIds): array
    {
        if ($reviewIds === []) {
            return [];
        }
        $out = [];
        ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_REVIEW_REPLY)->latest('id')->limit(500)->get()
            ->each(function (ExternalWriteAction $action) use (&$out, $reviewIds): void {
                $reviewId = (int) data_get($action->request_payload, 'review_id');
                if (isset($out[$reviewId]) || ! in_array($reviewId, $reviewIds, true)) {
                    return;
                }
                $out[$reviewId] = ['id' => (int) $action->id, 'status' => (string) $action->status, 'label' => $action->statusLabel(),
                    'undoable' => $action->isUndoable(), 'error' => $action->error];
            });

        return $out;
    }

    /**
     * Whether Google gave the reviews on the last collection, and if not why (in Turkish).
     *
     * @return array{state: string, reason: ?string, raw: ?string, checked_at: ?string, rows: ?int}
     */
    public function reviewAccess(DigitalAsset $asset): array
    {
        $run = Run::query()->where('digital_asset_id', $asset->id)->where('module_id', 'google-business-profile')
            ->latest('id')->limit(10)->get()->first(fn (Run $run): bool => is_array(data_get($run->metadata, 'datasets.gbp_reviews')));
        if ($run === null) {
            return ['state' => 'never', 'reason' => null, 'raw' => null, 'checked_at' => null, 'rows' => null];
        }
        $dataset = (array) data_get($run->metadata, 'datasets.gbp_reviews');
        $status = (string) ($dataset['status'] ?? 'unavailable');
        $ok = in_array($status, ['available', 'partial'], true);
        $raw = $ok ? null : (string) ($dataset['reason'] ?? '');

        return [
            'state' => $ok ? 'ok' : ($status === 'retrying' ? 'retrying' : 'unavailable'),
            'reason' => $ok ? null : self::reviewReason($raw),
            'raw' => $raw !== '' ? $raw : null,
            'checked_at' => ($run->finished_at ?? $run->started_at)?->diffForHumans(),
            'rows' => isset($dataset['rows']) ? (int) $dataset['rows'] : null,
        ];
    }

    /** Plain Turkish reason for a failed review collection (the raw Google message is shown next to it). */
    public static function reviewReason(string $raw): string
    {
        $disabled = str_contains($raw, 'SERVICE_DISABLED') || str_contains($raw, 'has not been used');

        return match (true) {
            str_contains($raw, 'account context') && $disabled => 'Konumun Google hesabı bulunamadı: Google Cloud projesinde “My Business Account Management API” kapalı. API Kitaplığı’ndan etkinleştirin.',
            str_contains($raw, 'account context') => 'Konumun Google hesabı bulunamadı; bağlı Google kullanıcısının bu konumu yöneten hesaba (sahip/yönetici) erişimi olmalı.',
            str_contains($raw, 'HTTP 401') => 'Google bağlantısının süresi dolmuş; Entegrasyonlar’dan Google’a yeniden bağlanın.',
            $disabled || str_contains($raw, 'HTTP 403') => 'Google bu hesap için yorum erişimi vermedi (API onayı gerekli). Yorumlar Google My Business API (v4) ile okunur; bu API yalnız Google’ın Business Profile API erişim başvurusunu onayladığı Cloud projelerinde açılır. Onaydan sonra projede “Google My Business API”yi etkinleştirin ve bağlı kullanıcının konumda sahip/yönetici olduğunu kontrol edin.',
            str_contains($raw, 'HTTP 404') => 'Google bu konumu yorum servisinde bulamadı; konum doğrulanmamış, silinmiş veya başka hesaba taşınmış olabilir.',
            str_contains($raw, 'HTTP 429') || str_contains($raw, 'pacing') => 'Google istek sınırına takıldı; bir sonraki toplamada kendiliğinden tekrar denenir.',
            str_contains($raw, 'time budget') => 'Süre sınırı doldu; kalan yorumlar bir sonraki toplamada alınır.',
            default => 'Yorumlar Google’dan alınamadı.',
        };
    }

    /**
     * Gönderiler tab: posts MoxDOP sent to this profile and posts collected from Google, plus the weekly rhythm.
     *
     * @return array{items: list<array<string, mixed>>, last_post: ?string, days_since: ?int, next: ?string, hint: string, late: bool}
     */
    public function posts(DigitalAsset $asset, ?int $resourceId): array
    {
        $actions = ExternalWriteAction::query()->where('digital_asset_id', $asset->id)->where('action', ExternalWriteAction::ACTION_LOCAL_POST)
            ->where('created_at', '>=', now()->subDays(120))->orderByDesc('id')->limit(60)->get();
        $items = $actions->map(function (ExternalWriteAction $action): array {
            $summary = (string) data_get($action->request_payload, 'summary', '');
            $publishAt = data_get($action->request_payload, 'publish_at');
            $scheduledFor = is_string($publishAt) ? CarbonImmutable::parse($publishAt)->timezone('Europe/Istanbul') : null;

            return [
                'kind' => 'moxdop', 'id' => (int) $action->id, 'title' => mb_strimwidth(trim(strtok($summary, "\n") ?: $summary), 0, 80, '…'), 'body' => $summary,
                'url' => data_get($action->request_payload, 'url'), 'action_type' => data_get($action->request_payload, 'action_type'), 'status' => (string) $action->status,
                'status_label' => match ((string) $action->status) {
                    'succeeded' => 'Yayınlandı', 'queued', 'running' => 'Gönderiliyor', 'failed' => 'Yayınlanamadı', 'undone' => 'Geri alındı', 'undoing' => 'Geri alınıyor',
                    'scheduled' => 'Zamanlandı', 'cancelled' => 'İptal edildi', default => (string) $action->status,
                },
                'when' => $action->status === 'scheduled' && $scheduledFor !== null ? $scheduledFor->format('d.m.Y H:i') : ($action->finished_at ?? $action->created_at)?->timezone('Europe/Istanbul')->format('d.m.Y H:i'),
                'sort' => $action->status === 'scheduled' && $scheduledFor !== null ? $scheduledFor->timestamp : (($action->finished_at ?? $action->created_at)?->timestamp ?? 0),
                'scheduled' => $action->status === 'scheduled',
                'error' => $action->status === 'failed' ? $action->error : null,
                'action_id' => $action->id, 'action_status' => $action->status,
                'undoable' => $action->isUndoable(), 'editable' => false,
            ];
        });
        $known = $actions->map(fn (ExternalWriteAction $a): ?string => data_get($a->result, 'post_name'))->filter()->all();
        $collected = $resourceId !== null
            ? DB::table('gbp_posts')->where('external_resource_id', $resourceId)->orderByDesc('create_time')->limit(40)->get(['post_name', 'summary', 'state', 'create_time', 'topic_type', 'raw_payload'])
            : collect();
        foreach ($collected as $post) {
            if (in_array((string) $post->post_name, $known, true)) {
                continue;
            }
            $created = $post->create_time !== null ? CarbonImmutable::parse((string) $post->create_time) : null;
            $items->push([
                'kind' => 'google', 'id' => null, 'title' => mb_strimwidth(trim((string) $post->summary), 0, 80, '…'), 'body' => (string) $post->summary,
                'url' => $this->decode($post->raw_payload)['searchUrl'] ?? null, 'action_type' => null, 'status' => strtolower((string) ($post->state ?: 'live')),
                'status_label' => match (strtoupper((string) $post->state)) {
                    'LIVE', '' => 'Google’da yayında', 'PROCESSING' => 'Google işliyor', 'REJECTED' => 'Google reddetti', default => (string) $post->state,
                },
                'when' => $created?->timezone('Europe/Istanbul')->format('d.m.Y H:i'), 'sort' => $created?->timestamp ?? 0,
                'error' => null, 'action_id' => null, 'action_status' => null, 'undoable' => false, 'editable' => false, 'scheduled' => false,
            ]);
        }

        $cadence = $this->cadence($asset, $resourceId);

        return ['items' => $items->sortByDesc('sort')->values()->all()] + $cadence;
    }

    /**
     * Last post (collected from Google or published from MoxDOP), days since, and the hint.
     *
     * @return array{last_post: ?string, days_since: ?int, next: ?string, hint: string, late: bool}
     */
    public function cadence(DigitalAsset $asset, ?int $resourceId): array
    {
        $collected = $resourceId !== null ? DB::table('gbp_posts')->where('external_resource_id', $resourceId)->max('create_time') : null;
        $published = ExternalWriteAction::query()->where('digital_asset_id', $asset->id)->where('action', ExternalWriteAction::ACTION_LOCAL_POST)->where('status', 'succeeded')->max('finished_at');
        $last = collect([$collected, $published])->filter()->map(fn ($value): CarbonImmutable => CarbonImmutable::parse((string) $value))->sortDesc()->first();
        $next = null;
        $days = $last !== null ? (int) $last->startOfDay()->diffInDays(now()->startOfDay(), true) : null;
        $late = $days === null || $days > self::POST_CADENCE_DAYS;
        $hint = match (true) {
            $days === null => 'Bu profilde henüz gönderi yok; haftada 1 gönderi önerilir.',
            $days === 0 => 'Son gönderi bugün; haftada 1 gönderi önerilir.',
            default => sprintf('Son gönderi %d gün önce; haftada 1 önerilir.', $days),
        };

        return ['last_post' => $last?->toDateString(), 'days_since' => $days, 'next' => $next, 'hint' => $hint, 'late' => $late];
    }

    /**
     * Profil sağlığı: the Business Profile standards (standards.json, gbp_*) plus the plain completeness items they do
     * not cover, read-only from the last collected data, each with one finding and one thing to do on Google. Missing
     * data is "Veri yok", never a problem; the score counts only evaluated items.
     *
     * @return array{available: bool, score: ?int, items: list<array{key: string, label: string, done: bool, state: string, value: string, todo: string}>}
     */
    public function health(int $resourceId, ?DigitalAsset $asset = null): array
    {
        $snapshot = DB::table('gbp_location_snapshots')->where('external_resource_id', $resourceId)->orderByDesc('captured_at')->orderByDesc('id')->first();
        if ($snapshot === null) {
            return ['available' => false, 'score' => null, 'items' => []];
        }
        $asset ??= DigitalAsset::query()->find(CoreAssetBinding::query()->where('external_resource_id', $resourceId)->where('capability', 'google_business_profile')
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)->orderByDesc('id')->value('digital_asset_id'));
        $items = [];
        if ($asset !== null) {
            foreach (app(GbpStandardInput::class)->results($asset, $resourceId) as $id => $result) {
                $items[] = ['key' => $id, 'label' => $result['title'], 'done' => $result['state'] === 'pass', 'state' => $result['state'],
                    'value' => $result['finding'], 'todo' => (string) ($result['solution'] ?? '')];
            }
        }

        $additional = count((array) ($this->decode($snapshot->additional_categories)['additionalCategories'] ?? []));
        $phone = trim((string) ($this->decode($snapshot->phone_numbers)['primaryPhone'] ?? ''));
        $website = trim((string) $snapshot->website_uri);
        $media = DB::table('gbp_media')->where('external_resource_id', $resourceId)->get(['media_format', 'category']);
        $photos = $media->filter(fn (object $row): bool => strtoupper((string) $row->media_format) !== 'VIDEO')->count();
        $mediaCategories = $media->pluck('category')->map(fn ($c): string => strtoupper((string) $c))->all();
        $plain = [
            ['key' => 'additional_categories', 'label' => __('operator_gbp.completeness_items.additional_categories'), 'done' => $additional >= 1, 'value' => (string) $additional,
                'todo' => 'Sunduğunuz diğer hizmetlere uyan ek kategoriler ekleyin.'],
            ['key' => 'primary_phone', 'label' => __('operator_gbp.completeness_items.primary_phone'), 'done' => $phone !== '', 'value' => $phone !== '' ? $phone : '—',
                'todo' => 'Birincil telefon numarasını ekleyin.'],
            ['key' => 'website', 'label' => __('operator_gbp.completeness_items.website'), 'done' => $website !== '', 'value' => $website !== '' ? $website : '—',
                'todo' => 'Web sitesi adresini (UTM etiketli) ekleyin.'],
            ['key' => 'photos', 'label' => __('operator_gbp.completeness_items.photos'), 'done' => $photos >= 10 && in_array('COVER', $mediaCategories, true) && in_array('LOGO', $mediaCategories, true),
                'value' => $photos.' fotoğraf'.(in_array('COVER', $mediaCategories, true) ? '' : ' · kapak yok').(in_array('LOGO', $mediaCategories, true) ? '' : ' · logo yok'),
                'todo' => 'Kapak fotoğrafı, logo ve iç/dış mekân, ekip fotoğrafları yükleyin.'],
        ];
        foreach ($plain as $item) {
            $items[] = $item + ['state' => $item['done'] ? 'pass' : 'fail'];
        }
        $rank = ['fail' => 0, 'review' => 1, 'pass' => 2, 'unknown' => 3, 'not_applicable' => 4];
        usort($items, fn (array $a, array $b): int => ($rank[$a['state']] ?? 5) <=> ($rank[$b['state']] ?? 5));
        $evaluated = array_filter($items, fn (array $item): bool => in_array($item['state'], ['pass', 'fail', 'review'], true));
        $done = count(array_filter($evaluated, fn (array $item): bool => $item['state'] === 'pass'));

        return ['available' => true, 'score' => $evaluated === [] ? null : (int) round($done / count($evaluated) * 100), 'items' => $items];
    }

    /** Google's "write a review" link for a place (null without a place id). */
    public function reviewLink(?string $placeId): ?string
    {
        $placeId = trim((string) $placeId);

        return $placeId !== '' ? 'https://search.google.com/local/writereview?placeid='.rawurlencode($placeId) : null;
    }

    public function placeId(CoreExternalResource $resource): ?string
    {
        $fromSnapshot = DB::table('gbp_location_snapshots')->where('external_resource_id', $resource->id)->whereNotNull('place_id')
            ->orderByDesc('captured_at')->orderByDesc('id')->value('place_id');
        $placeId = $fromSnapshot ?? data_get($resource->metadata, 'place_id');

        return is_string($placeId) && trim($placeId) !== '' ? trim($placeId) : null;
    }

    /** Inline SVG QR code for a link; null when the QR library is not installed. */
    public function qrSvg(string $url): ?string
    {
        if (! class_exists(QRCode::class)) {
            return null;
        }
        try {
            $svg = (new QRCode(new QROptions(['outputInterface' => QRMarkupSVG::class, 'outputBase64' => false, 'addQuietzone' => true])))->render($url);

            // Inline in the page: the XML declaration is dropped.
            $svg = (string) preg_replace('~^<\?xml[^>]*>\s*~', '', trim($svg));

            return str_starts_with($svg, '<svg') ? $svg : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function age(?int $hours): string
    {
        return match (true) {
            $hours === null => '—',
            $hours < 1 => 'az önce',
            $hours < 48 => $hours.' saat',
            $hours < 60 * 24 => intdiv($hours, 24).' gün',
            default => intdiv($hours, 24 * 30).' ay',
        };
    }

    /** @return array<mixed> */
    private function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '' || $raw === 'null') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}

<?php

namespace App\Services\Gbp\Desk;

use App\Models\AiProduction;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpReview;
use App\Models\User;
use App\Services\Archive\ProductionArchive;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\GbpDailyWorkspace;
use App\Services\Gbp\ReviewReplyDrafter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Yorumlar (ADR-079 desk): reviews are the strongest local signal after relevance and distance. One list of every
 * unanswered review of every profile (oldest waiting first) with its AI reply draft (ReviewReplyDrafter, one click for
 * all), sent by the Admin (ADR-073 reply write); per profile the review numbers of the last 90 days and the review
 * request kit (Google's "write a review" link, QR code, printable card, message text to copy).
 */
final class ReviewDesk
{
    public const int WINDOW_DAYS = 90;

    /**
     * At most this many drafts are asked in one click (yakup, 2026-10-06: 30 was too few for a profile with 200+
     * unanswered reviews). The monthly AI budget still stops the queue; drafts run in the background.
     */
    public const int DRAFT_BATCH = 300;

    private const array STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    public function __construct(
        private readonly GbpDailyWorkspace $daily,
        private readonly ReviewReplyDrafter $drafter,
    ) {}

    /**
     * Review numbers per profile: last 90 days, reply rate, unanswered, average rating.
     *
     * @param  array<int, int>  $resources  asset id => resource id
     * @return array<int, array{recent: int, average: ?float, unanswered: int, reply_rate: ?int, late: int}>
     */
    public function stats(array $resources): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);
        $rows = DB::table('gbp_reviews')->whereIn('external_resource_id', array_values($resources))
            ->get(['external_resource_id', 'star_rating', 'create_time', 'review_reply'])->groupBy('external_resource_id');
        $out = [];
        foreach ($resources as $assetId => $resourceId) {
            $all = $rows->get($resourceId, collect());
            $recent = $all->filter(fn ($r): bool => $r->create_time !== null && CarbonImmutable::parse((string) $r->create_time)->greaterThanOrEqualTo($since));
            $stars = $recent->map(fn ($r): ?int => self::STARS[strtoupper((string) $r->star_rating)] ?? null)->filter();
            $unanswered = $all->filter(fn ($r): bool => self::noReply($r->review_reply));
            $out[$assetId] = [
                'recent' => $recent->count(),
                'average' => $stars->isNotEmpty() ? round($stars->avg(), 1) : null,
                'unanswered' => $unanswered->count(),
                'reply_rate' => $all->isNotEmpty() ? (int) round(($all->count() - $unanswered->count()) / $all->count() * 100) : null,
                'late' => $unanswered->filter(fn ($r): bool => $r->create_time !== null && CarbonImmutable::parse((string) $r->create_time)->lessThan(now()->subHours(GbpDailyWorkspace::REPLY_SLA_HOURS)))->count(),
            ];
        }

        return $out;
    }

    /** Review list filters: waiting for a reply (oldest first), answered, all (newest first). */
    public const array STATUSES = ['bekleyen' => 'Yanıt bekleyen', 'yanitli' => 'Yanıtlanan', 'tumu' => 'Tümü', 'bildirim' => 'Kaldırma talepleri'];

    /** Rows loaded per scroll step on the review grid. */
    public const int PAGE = 48;

    /**
     * Unanswered reviews of the given profiles, oldest waiting first, with their draft and reply state.
     *
     * @param  array<int, int>  $resources  asset id => resource id
     * @return list<array{id: int, asset_id: int, rating: ?int, reviewer: string, comment: string, date: string, waiting: string, late: bool, answered: bool, reply: string, draft: ?string, draft_state: ?string, action: ?array<string, mixed>}>
     */
    public function unanswered(array $resources, string $rating = '', int $limit = 100): array
    {
        return $this->reviews($resources, 'bekleyen', $rating, $limit)['rows'];
    }

    /**
     * Reviews of the given profiles for the review grid: waiting ones oldest first, answered / all newest first.
     *
     * @param  array<int, int>  $resources  asset id => resource id
     * @return array{rows: list<array{id: int, asset_id: int, rating: ?int, reviewer: string, comment: string, date: string, waiting: string, late: bool, answered: bool, reply: string, draft: ?string, draft_state: ?string, action: ?array<string, mixed>}>, total: int}
     */
    public function reviews(array $resources, string $status = 'bekleyen', string $rating = '', int $limit = self::PAGE): array
    {
        $assetByResource = array_flip($resources);
        $open = "(review_reply is null or cast(review_reply as text) in ('', 'null', '[]'))";
        $query = DB::table('gbp_reviews')->whereIn('external_resource_id', array_values($resources))
            ->when($status === 'bekleyen', fn ($q) => $q->whereRaw($open))
            ->when($status === 'yanitli', fn ($q) => $q->whereRaw('not '.$open))
            ->when($status === 'bildirim', fn ($q) => $q->whereIn('id', DB::table('gbp_review_flags')->select('gbp_review_id')))
            ->when(isset(GbpDailyWorkspace::RATING_FILTERS[$rating]), fn ($q) => $q->whereIn('star_rating', GbpDailyWorkspace::RATING_FILTERS[$rating]));
        $total = (clone $query)->count();
        $rows = $query->when($status === 'bekleyen', fn ($q) => $q->orderBy('create_time'), fn ($q) => $q->orderByDesc('create_time'))->orderBy('id')
            ->limit($limit)->get(['id', 'external_resource_id', 'reviewer', 'star_rating', 'comment', 'create_time', 'review_reply']);
        $ids = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $drafts = $ids === [] ? collect() : AiProduction::query()->where('kind', ReviewReplyDrafter::KIND)->where('subject_type', 'GbpReview')->whereIn('subject_id', $ids)
            ->where('status', '!=', AiProduction::STATUS_DISCARDED)->orderBy('version')->get()->keyBy('subject_id');
        $actions = $this->daily->replyActions($ids);

        $out = $rows->map(function (object $row) use ($assetByResource, $drafts, $actions): array {
            $created = $row->create_time !== null ? CarbonImmutable::parse((string) $row->create_time) : null;
            $hours = $created !== null ? (int) $created->diffInHours(now(), true) : null;
            $reviewer = json_decode((string) $row->reviewer, true);
            $answered = ! self::noReply($row->review_reply);
            $reply = $answered ? json_decode((string) $row->review_reply, true) : null;

            return [
                'id' => (int) $row->id,
                'asset_id' => (int) ($assetByResource[(int) $row->external_resource_id] ?? 0),
                'rating' => self::STARS[strtoupper((string) $row->star_rating)] ?? null,
                'reviewer' => (string) (is_array($reviewer) ? ($reviewer['displayName'] ?? '—') : '—'),
                'comment' => trim((string) $row->comment),
                'date' => $created?->toDateString() ?? '',
                'waiting' => match (true) {
                    $hours === null => '—',
                    $hours < 48 => $hours.' saat',
                    default => intdiv($hours, 24).' gün',
                },
                'late' => ! $answered && $hours !== null && $hours > GbpDailyWorkspace::REPLY_SLA_HOURS,
                'answered' => $answered,
                'reply' => is_array($reply) ? trim((string) ($reply['comment'] ?? '')) : '',
                'draft' => $drafts->has((int) $row->id) ? (string) data_get($drafts[(int) $row->id]->content, 'reply') : null,
                'draft_state' => $answered ? null : $this->drafter->state((int) $row->id),
                'action' => $actions[(int) $row->id] ?? null,
            ];
        })->values()->all();

        return ['rows' => $out, 'total' => $total];
    }

    /** First name of a reviewer for a shared reply ("{ad}"); empty for anonymous Google users. */
    public static function firstName(string $reviewer): string
    {
        $first = trim((string) strtok(trim($reviewer), ' '));

        return $first === '' || $first === '—' || str_contains(mb_strtolower($reviewer), 'google') ? '' : $first;
    }

    /** A shared reply for one review: "{ad}" becomes the reviewer's first name (dropped with its comma when unknown). */
    public static function personalize(string $text, string $reviewer): string
    {
        $name = self::firstName($reviewer);
        $out = $name !== '' ? str_replace('{ad}', $name, $text) : (string) preg_replace('/\s*\{ad\}/u', '', $text);

        return trim((string) preg_replace('/^,\s*|\s+([,.!?])/u', '$1', $out));
    }

    /** True when a reply is on its way to Google or already there (no new reply can be sent). */
    public static function busy(array $review): bool
    {
        return $review['answered'] || ($review['action'] !== null && in_array($review['action']['status'], ['queued', 'running', 'succeeded'], true));
    }

    /**
     * Asks AI drafts for the listed reviews that have none (at most DRAFT_BATCH per click).
     *
     * @param  list<array<string, mixed>>  $reviews  unanswered() rows
     */
    public function draftAll(array $reviews): int
    {
        $queued = 0;
        foreach ($reviews as $review) {
            if ($queued >= self::DRAFT_BATCH) {
                break;
            }
            if (($review['answered'] ?? false) || $review['draft'] !== null || $review['draft_state'] === 'running' || $review['action'] !== null) {
                continue;
            }
            $model = GbpReview::query()->find($review['id']);
            if ($model === null) {
                continue;
            }
            $this->drafter->queue($model);
            $queued++;
        }

        return $queued;
    }

    /**
     * Keeps a reply the operator wrote or edited as the review's newest draft (so it survives the page, goes into the
     * brand approval PDF and is what "Yayımla" sends). Same text as the newest draft = nothing new.
     */
    public function saveDraft(User $user, int $reviewId, string $text): void
    {
        $text = mb_substr(trim($text), 0, 4000);
        $review = GbpReview::query()->find($reviewId);
        if ($review === null || $text === '') {
            return;
        }
        $latest = AiProduction::query()->where('kind', ReviewReplyDrafter::KIND)->where('subject_type', 'GbpReview')->where('subject_id', $reviewId)
            ->where('status', '!=', AiProduction::STATUS_DISCARDED)->orderByDesc('version')->first();
        if ($latest !== null && trim((string) data_get($latest->content, 'reply')) === $text) {
            return;
        }
        $assetId = $review->digital_asset_id ?? CoreAssetBinding::query()->where('external_resource_id', $review->external_resource_id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)->where('capability', 'google_business_profile')->value('digital_asset_id');
        $asset = $assetId !== null ? DigitalAsset::query()->find($assetId) : null;
        app(ProductionArchive::class)->record(ReviewReplyDrafter::KIND, $review, ['reply' => $text, 'manual' => true, 'edited_by' => $user->id],
            ['brand_id' => $asset?->brand_id, 'digital_asset_id' => $asset?->id, 'title' => 'Yorum yanıtı (elle) · '.mb_substr((string) $review->comment, 0, 60)]);
    }

    /** Admin: one reply to Google (the edited draft). */
    public function send(User $user, int $reviewId, string $text): ExternalWriteAction
    {
        $review = GbpReview::query()->findOrFail($reviewId);

        return app(ExternalWriteService::class)->requestReviewReply($user, $review, $text);
    }

    /**
     * Admin: every listed review that has a draft and no reply on the way gets its draft as the reply.
     *
     * @param  list<array<string, mixed>>  $reviews
     * @return array{sent: int, failed: int}
     */
    public function sendDrafts(User $user, array $reviews): array
    {
        abort_unless(ExternalWriteService::allowed($user, ExternalWriteAction::CHANNEL_GBP), 403, 'Yanıtları yalnız Admin gönderir.');
        $sent = 0;
        $failed = 0;
        foreach ($reviews as $review) {
            if ($review['draft'] === null || trim((string) $review['draft']) === '' || self::busy($review + ['answered' => false])) {
                continue;
            }
            try {
                $this->send($user, (int) $review['id'], (string) $review['draft']);
                $sent++;
            } catch (ValidationException|Throwable) {
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * The review request kit of a profile.
     *
     * @return array{link: ?string, qr: ?string}
     */
    public function kit(DigitalAsset $location): array
    {
        $resource = $this->daily->resource($location);
        $link = $resource !== null ? $this->daily->reviewLink($this->daily->placeId($resource)) : null;

        return ['link' => $link, 'qr' => $link !== null ? $this->daily->qrSvg($link) : null];
    }

    /** Message the business can send to a happy customer (WhatsApp / SMS). */
    public static function requestMessage(string $business, string $link): string
    {
        return 'Merhaba, '.$business.' olarak bizi tercih ettiğiniz için teşekkür ederiz. Deneyiminizi Google’da paylaşırsanız çok seviniriz: '.$link;
    }

    private static function noReply(mixed $raw): bool
    {
        return $raw === null || $raw === '' || $raw === 'null' || $raw === '[]';
    }
}

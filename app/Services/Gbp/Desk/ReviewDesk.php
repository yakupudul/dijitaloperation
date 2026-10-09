<?php

namespace App\Services\Gbp\Desk;

use App\Models\AiProduction;
use App\Models\BrandOffering;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpReview;
use App\Models\GbpReviewFlag;
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

    /** Capitalised words before "hoca / hanım / bey" or after "Dr." that are not names. */
    private const array NOT_NAMES = ['diş', 'doktor', 'doktorum', 'hocam', 'hoca', 'çok', 'tüm', 'bütün', 'her', 'bir', 'ilgili', 'güler', 'değerli', 'sayın', 'sevgili',
        'teşekkür', 'teşekkürler', 'kendisi', 'kliniğin', 'klinik', 'ekip', 'ekibe', 'sekreter', 'asistan', 'güzel', 'harika', 'iyi', 'the', 'and', 'very', 'thank'];

    /** Words that do not tell services apart. */
    private const array GENERIC_SERVICE_WORDS = ['tedavisi', 'tedavi', 'uygulaması', 'uygulama', 'operasyonu', 'operasyonları', 'ameliyatı', 'cerrahi', 'cerrahisi', 'estetik',
        'estetiği', 'kaplama', 'treatment', 'surgery', 'dental', 'tooth', 'teeth', 'hizmeti', 'hizmetleri', 'muayene', 'yenilemesi'];

    public function __construct(
        private readonly GbpDailyWorkspace $daily,
        private readonly ReviewReplyDrafter $drafter,
    ) {}

    /**
     * Review numbers per profile (Şube karnesi): reviews in the last 90 days and their average against the 90 days
     * before, reply rate, how long a reply takes on average (hours, replies Google dates), unanswered, late and the
     * unanswered 1–2 ★ ones.
     *
     * @param  array<int, int>  $resources  asset id => resource id
     * @return array<int, array{total: int, recent: int, average: ?float, previous_average: ?float, unanswered: int, reply_rate: ?int, reply_hours: ?int, late: int, low_open: int}>
     */
    public function stats(array $resources): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);
        $before = now()->subDays(self::WINDOW_DAYS * 2);
        $rows = DB::table('gbp_reviews')->whereIn('external_resource_id', array_values($resources))
            ->get(['external_resource_id', 'star_rating', 'create_time', 'review_reply'])->groupBy('external_resource_id');
        $out = [];
        foreach ($resources as $assetId => $resourceId) {
            $all = $rows->get($resourceId, collect());
            $created = fn ($r): ?CarbonImmutable => $r->create_time !== null ? CarbonImmutable::parse((string) $r->create_time) : null;
            $stars = fn ($r): ?int => self::STARS[strtoupper((string) $r->star_rating)] ?? null;
            $recent = $all->filter(fn ($r): bool => $created($r)?->greaterThanOrEqualTo($since) ?? false);
            $previous = $all->filter(fn ($r): bool => ($created($r)?->greaterThanOrEqualTo($before) ?? false) && $created($r)->lessThan($since));
            $recentStars = $recent->map($stars)->filter();
            $previousStars = $previous->map($stars)->filter();
            $unanswered = $all->filter(fn ($r): bool => self::noReply($r->review_reply));
            $hours = $all->reject(fn ($r): bool => self::noReply($r->review_reply))->map(function ($r) use ($created): ?float {
                $reply = json_decode((string) $r->review_reply, true);
                $at = is_array($reply) && is_string($reply['updateTime'] ?? null) ? CarbonImmutable::parse($reply['updateTime']) : null;
                $made = $created($r);

                return $at !== null && $made !== null && $at->greaterThanOrEqualTo($made) ? $made->diffInMinutes($at, true) / 60 : null;
            })->filter(fn (?float $h): bool => $h !== null);
            $out[$assetId] = [
                'total' => $all->count(),
                'recent' => $recent->count(),
                'average' => $recentStars->isNotEmpty() ? round($recentStars->avg(), 1) : null,
                'previous_average' => $previousStars->isNotEmpty() ? round($previousStars->avg(), 1) : null,
                'unanswered' => $unanswered->count(),
                'reply_rate' => $all->isNotEmpty() ? (int) round(($all->count() - $unanswered->count()) / $all->count() * 100) : null,
                'reply_hours' => $hours->isNotEmpty() ? (int) round($hours->avg()) : null,
                'late' => $unanswered->filter(fn ($r): bool => $created($r)?->lessThan(now()->subHours(GbpDailyWorkspace::REPLY_SLA_HOURS)) ?? false)->count(),
                'low_open' => $unanswered->filter(fn ($r): bool => ($stars($r) ?? 5) <= 2)->count(),
            ];
        }

        return $out;
    }

    /** Review list orders (yakup, 2026-10-07: new reviews first; old ones are worth less on Google). */
    public const array SORTS = ['yeni' => 'En yeni önce', 'eski' => 'En eski önce'];

    /** Review list filters: waiting for a reply, answered, all, removal requests. */
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
        return $this->reviews($resources, 'bekleyen', $rating, $limit, 'eski')['rows'];
    }

    /**
     * Reviews of the given profiles for the review grid: waiting ones oldest first, answered / all newest first.
     *
     * @param  array<int, int>  $resources  asset id => resource id
     * @return array{rows: list<array{id: int, asset_id: int, rating: ?int, reviewer: string, comment: string, date: string, waiting: string, late: bool, answered: bool, reply: string, draft: ?string, draft_state: ?string, action: ?array<string, mixed>}>, total: int}
     */
    public function reviews(array $resources, string $status = 'bekleyen', string $rating = '', int $limit = self::PAGE, string $sort = 'yeni', bool $recentOnly = false, string $search = ''): array
    {
        $search = trim(self::lower($search));
        $assetByResource = array_flip($resources);
        $open = "(review_reply is null or cast(review_reply as text) in ('', 'null', '[]'))";
        $query = DB::table('gbp_reviews')->whereIn('external_resource_id', array_values($resources))
            ->when($status === 'bekleyen', fn ($q) => $q->whereRaw($open))
            ->when($status === 'yanitli', fn ($q) => $q->whereRaw('not '.$open))
            ->when($status === 'bildirim', fn ($q) => $q->whereIn('id', DB::table('gbp_review_flags')->select('gbp_review_id')))
            ->when(isset(GbpDailyWorkspace::RATING_FILTERS[$rating]), fn ($q) => $q->whereIn('star_rating', GbpDailyWorkspace::RATING_FILTERS[$rating]))
            ->when($recentOnly, fn ($q) => $q->where('create_time', '>=', now()->subDays(self::WINDOW_DAYS)))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('lower(cast(comment as text)) like ?', ['%'.$search.'%'])
                ->orWhereRaw('lower(cast(reviewer as text)) like ?', ['%'.$search.'%'])));
        $total = (clone $query)->count();
        $rows = $query->when($sort === 'eski', fn ($q) => $q->orderBy('create_time'), fn ($q) => $q->orderByDesc('create_time'))->orderBy('id')
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
                'comment' => self::original((string) $row->comment),
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

    /**
     * "Taslağı sil": every draft of the listed reviews (AI or written by hand) is put aside in the archive as discarded,
     * so the card is empty again and nothing of it goes to the brand PDF or Yayımla. A reply already on its way to
     * Google is not touched. Returns how many reviews lost their draft.
     *
     * @param  list<int>  $reviewIds
     */
    public function discardDrafts(User $user, array $reviewIds): int
    {
        if ($reviewIds === []) {
            return 0;
        }
        $drafts = AiProduction::query()->where('kind', ReviewReplyDrafter::KIND)->where('subject_type', 'GbpReview')->whereIn('subject_id', $reviewIds)
            ->where('status', '!=', AiProduction::STATUS_DISCARDED)->get();
        $archive = app(ProductionArchive::class);
        foreach ($drafts as $draft) {
            $archive->mark($draft, AiProduction::STATUS_DISCARDED, $user);
        }
        foreach ($reviewIds as $reviewId) {
            $this->drafter->forget($reviewId);
        }

        return $drafts->pluck('subject_id')->unique()->count();
    }

    /** Admin: one reply to Google (the edited draft). */
    public function send(User $user, int $reviewId, string $text): ExternalWriteAction
    {
        $review = GbpReview::query()->findOrFail($reviewId);

        return app(ExternalWriteService::class)->requestReviewReply($user, $review, $text);
    }

    /**
     * Admin (manual click only): every listed review that has a draft and no reply on the way gets its draft as the
     * reply. Reviews the brand said not to answer ("Marka istemedi") and reviews with an open removal flag are skipped.
     *
     * @param  list<array<string, mixed>>  $reviews
     * @return array{sent: int, failed: int}
     */
    public function sendDrafts(User $user, array $reviews): array
    {
        abort_unless(ExternalWriteService::allowed($user, ExternalWriteAction::CHANNEL_GBP), 403, 'Yanıtları yalnız Admin gönderir.');
        $ids = array_values(array_map(fn (array $r): int => (int) $r['id'], $reviews));
        $declined = array_filter(app(ReviewApprovals::class)->forReviews($ids), fn (array $a): bool => $a['state'] === 'skip');
        $flagged = array_filter(app(ReviewFlags::class)->forReviews($ids), fn (array $f): bool => in_array($f['status'], [GbpReviewFlag::DRAFT, GbpReviewFlag::REPORTED], true));
        $sent = 0;
        $failed = 0;
        foreach ($reviews as $review) {
            if ($review['draft'] === null || trim((string) $review['draft']) === '' || self::busy($review + ['answered' => false])
                || isset($declined[(int) $review['id']]) || isset($flagged[(int) $review['id']])) {
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

    /**
     * The reviewer's own words without Google's machine translation: "metin (Translated by Google) translation" keeps
     * the text, "(Translated by Google) translation (Original) metin" keeps what follows "(Original)".
     */
    public static function original(string $comment): string
    {
        $comment = trim($comment);
        if (preg_match('/\(Original\)\s*(.+)$/su', $comment, $m) === 1) {
            return trim($m[1]);
        }
        $cut = mb_strpos($comment, '(Translated by Google)');

        return $cut !== false && $cut > 0 ? trim(mb_substr($comment, 0, $cut)) : $comment;
    }

    /** Lower case that keeps Turkish letters comparable ("İ" → "i", "I" → "ı"). */
    public static function lower(string $text): string
    {
        return mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']));
    }

    /**
     * Words of a reply for comparing two replies: punctuation, honorifics and the given names (the reviewer's) do not
     * make two replies different.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function words(string $text, array $names = []): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', self::lower($text), $m);
        $skip = array_merge(['hanım', 'bey', 'hocam', 'hoca'], array_map(fn (string $n): string => self::lower($n), $names));

        return array_values(array_diff(array_unique($m[0]), $skip));
    }

    /** Share of words two replies have in common (0–1, Jaccard). */
    public static function similarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        return count(array_intersect($a, $b)) / count(array_unique(array_merge($a, $b)));
    }

    /**
     * Replies already on Google for these profiles (newest first, at most $limit) as word lists, to warn before a
     * new reply repeats one of them.
     *
     * @param  list<int>  $resources
     * @return list<array{text: string, words: list<string>}>
     */
    public function publishedReplies(array $resources, int $limit = 400): array
    {
        if ($resources === []) {
            return [];
        }

        return DB::table('gbp_reviews')->whereIn('external_resource_id', $resources)->whereNotNull('review_reply')->orderByDesc('create_time')->limit($limit)
            ->pluck('review_reply')->map(function ($raw): ?array {
                $reply = self::noReply($raw) ? null : json_decode((string) $raw, true);
                $text = is_array($reply) ? trim((string) ($reply['comment'] ?? '')) : '';

                return $text === '' ? null : ['text' => $text, 'words' => self::words($text)];
            })->filter()->values()->all();
    }

    /**
     * Neler konuşuluyor: doctors and services the reviews of these profiles name, with how many reviews and their
     * average rating. Doctors come from "Dr. Ad", "Ad Hoca(m)", "Ad Hanım / Bey" (named in at least 2 reviews);
     * services from the brands' own service names (their distinctive word). Rules only, no AI.
     *
     * @param  list<int>  $resources
     * @param  list<int>  $brandIds
     * @return array{doctors: list<array{name: string, count: int, average: ?float}>, services: list<array{name: string, word: string, count: int, average: ?float}>}
     */
    public function topics(array $resources, array $brandIds): array
    {
        if ($resources === []) {
            return ['doctors' => [], 'services' => []];
        }
        $reviews = DB::table('gbp_reviews')->whereIn('external_resource_id', $resources)->whereNotNull('comment')->get(['comment', 'star_rating'])
            ->map(fn ($r): array => ['text' => self::original((string) $r->comment), 'stars' => self::STARS[strtoupper((string) $r->star_rating)] ?? null])
            ->filter(fn (array $r): bool => $r['text'] !== '')->values();

        $doctors = [];
        $upper = '[A-ZÇĞİÖŞÜ][a-zçğıöşü]{2,}';
        foreach ($reviews as $review) {
            preg_match_all('/(?:\b(?:[Dd]r|[Dd]t|[Dd]oktor|[Hh]ekim)\.?\s+(?:[Dd]t\.?\s+)?('.$upper.')|('.$upper.')\s+(?:[Hh]oca|[Hh]anım|[Bb]ey)(?:[a-zçğıöşü]*)\b)/u', $review['text'], $m, PREG_SET_ORDER);
            $names = [];
            foreach ($m as $match) {
                $name = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
                if ($name !== '' && ! in_array(self::lower($name), self::NOT_NAMES, true)) {
                    $names[$name] = true;
                }
            }
            foreach (array_keys($names) as $name) {
                $doctors[$name][] = $review['stars'];
            }
        }
        $doctorRows = [];
        foreach ($doctors as $name => $stars) {
            if (count($stars) >= 2) {
                $rated = array_filter($stars);
                $doctorRows[] = ['name' => $name, 'count' => count($stars), 'average' => $rated !== [] ? round(array_sum($rated) / count($rated), 1) : null];
            }
        }
        usort($doctorRows, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $serviceRows = [];
        $names = $brandIds === [] ? collect() : BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName'])->whereIn('brand_id', $brandIds)->where('status', 'active')->get()
            ->map(fn (BrandOffering $o): string => $o->displayName())->unique()->values();
        foreach ($names as $name) {
            $word = self::serviceWord($name);
            if ($word === '' || isset($serviceRows[$word])) {
                continue;
            }
            $matched = $reviews->filter(fn (array $r): bool => str_contains(self::lower($r['text']), $word));
            if ($matched->count() >= 2) {
                $rated = $matched->pluck('stars')->filter();
                $serviceRows[$word] = ['name' => $name, 'word' => $word, 'count' => $matched->count(), 'average' => $rated->isNotEmpty() ? round($rated->avg(), 1) : null];
            }
        }
        $serviceRows = array_values($serviceRows);
        usort($serviceRows, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return ['doctors' => array_slice($doctorRows, 0, 12), 'services' => array_slice($serviceRows, 0, 12)];
    }

    /** The distinctive word of a service name ("İmplant Tedavisi" → "implant"); empty when only generic words remain. */
    public static function serviceWord(string $name): string
    {
        $words = array_values(array_filter(self::words($name), fn (string $w): bool => mb_strlen($w) >= 5 && ! in_array($w, self::GENERIC_SERVICE_WORDS, true)));

        return $words[0] ?? '';
    }

    private static function noReply(mixed $raw): bool
    {
        return $raw === null || $raw === '' || $raw === 'null' || $raw === '[]';
    }
}

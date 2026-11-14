<?php

namespace App\Services\Gbp;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\GbpReview;
use App\Models\GbpReviewFlag;
use App\Services\Epicrisis\EpicrisisClaims;
use App\Services\Gbp\Desk\ReviewApprovals;
use App\Services\Gbp\Desk\ReviewDesk;
use App\Services\Gbp\Desk\ReviewFlags;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Yorum yanıtı taslakları (Aşama 9, 2026-10-22). Every unanswered review on a served profile (any age since 2026-11-13)
 * gets a reply draft at night (Claude, the review reply prompt); `checked()` tells without AI whether a draft is sound: long
 * enough, no phone number or link, no claim the brand's record does not hold, not a 1-2 star review, not declined by the
 * brand, no open removal request. Since 2026-10-26 replies go to Google when the client approves them on the brand's
 * approval link (ReviewApprovals), not with the daily click.
 */
final class ReviewReplyCheck
{
    /**
     * Drafts asked per profile per night. Every unanswered review of a served profile gets one, whatever its age (yakup
     * 2026-11-13: "tüm yorumlara yanıt hazırlanmalı", "her işletme için 300 yorum her gün"), newest first.
     */
    public const int NIGHT_DRAFTS = 300;

    public const int MIN_LENGTH = 25;

    public function __construct(private readonly ReviewDesk $desk, private readonly GbpDailyWorkspace $daily) {}

    /**
     * Unanswered reviews with a draft, each with its check: reason null = ready.
     *
     * @return Collection<int, array{review: array<string, mixed>, location: string, brand_id: int, reason: ?string}>
     */
    public function checked(): Collection
    {
        [$rows, $assets] = $this->unanswered();
        $rows = array_values(array_filter($rows, fn (array $r): bool => $r['draft'] !== null && trim((string) $r['draft']) !== '' && ! ReviewDesk::busy($r + ['answered' => false])));
        if ($rows === []) {
            return collect();
        }
        $ids = array_map(fn (array $r): int => (int) $r['id'], $rows);
        $declined = array_filter(app(ReviewApprovals::class)->forReviews($ids), fn (array $a): bool => $a['state'] === 'skip');
        $flagged = array_filter(app(ReviewFlags::class)->forReviews($ids), fn (array $f): bool => in_array($f['status'], [GbpReviewFlag::DRAFT, GbpReviewFlag::REPORTED], true));
        $brands = Brand::query()->whereIn('id', $assets->pluck('brand_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $claims = app(EpicrisisClaims::class);

        return collect($rows)->map(function (array $row) use ($assets, $brands, $declined, $flagged, $claims): array {
            $asset = $assets->get($row['asset_id']);
            $brand = $asset !== null ? $brands->get($asset->brand_id) : null;
            $draft = trim((string) $row['draft']);
            $reason = match (true) {
                $brand === null => 'Profil bir markaya bağlı değil.',
                isset($declined[$row['id']]) => 'Marka bu yoruma yanıt istemedi.',
                isset($flagged[$row['id']]) => 'Yorum için kaldırma talebi açık.',
                ($row['rating'] ?? 5) <= 2 => 'Kötü yorum: yanıtı sen gözden geçir.',
                mb_strlen($draft) < self::MIN_LENGTH => 'Yanıt çok kısa.',
                (bool) preg_match('~https?://|www\.|(?:\d[\s().-]?){7,}~u', $draft) => 'Yanıtta telefon ya da bağlantı var.',
                default => null,
            };
            if ($reason === null && $brand !== null) {
                try {
                    $unsupported = $claims->unsupported($brand, $draft, [(string) $row['comment']]);
                    $reason = $unsupported !== [] ? EpicrisisClaims::reason($unsupported) : null;
                } catch (Throwable $error) {
                    report($error);
                    $reason = 'Yanıt kontrol edilemedi.';
                }
            }

            return ['review' => $row, 'location' => (string) ($asset?->name ?? ''), 'brand_id' => (int) ($asset?->brand_id ?? 0), 'reason' => $reason];
        })->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function ready(): Collection
    {
        return $this->checked()->whereNull('reason')->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function held(): Collection
    {
        return $this->checked()->whereNotNull('reason')->values();
    }

    /**
     * Night: drafts for unanswered reviews that have none (any age, newest first), then new drafts for open ones an older
     * reply prompt wrote; at most NIGHT_DRAFTS per profile.
     */
    public function draftNight(): int
    {
        $assets = DigitalAsset::query()->operational()->where('digital_assets.type', 'google_business_profile')->whereNotNull('digital_assets.brand_id')->pluck('digital_assets.id');
        $queued = 0;
        $drafter = app(ReviewReplyDrafter::class);
        // Profile by profile (yakup 2026-11-13: "her işletme için 300 yorum her gün"), all ages, only reviews still
        // without a draft: the 90-day window and one shared cap used to leave the older reviews without a reply forever.
        foreach ($this->daily->resourceIds($assets->map(fn ($id): int => (int) $id)->all()) as $assetId => $resourceId) {
            $rows = $this->desk->reviews([$assetId => $resourceId], 'bekleyen', '', self::NIGHT_DRAFTS * 2, 'yeni', false, '', false)['rows'];
            $missing = array_values(array_filter($rows, fn (array $r): bool => $r['draft'] === null && $r['draft_state'] !== 'running' && $r['action'] === null));
            try {
                $done = $missing !== [] ? $this->desk->draftAll(array_slice($missing, 0, self::NIGHT_DRAFTS)) : 0;
                // What is left of the profile's share goes to drafts an older reply prompt wrote that nobody approved
                // yet: a better prompt reaches the client links too, not only reviews that come in later.
                foreach ($drafter->staleReviewIds((int) $assetId, self::NIGHT_DRAFTS - $done) as $reviewId) {
                    $review = GbpReview::query()->find($reviewId);
                    if ($review !== null) {
                        $drafter->queue($review);
                        $done++;
                    }
                }
                $queued += $done;
            } catch (ValidationException) {
                return $queued; // no AI route for replies: nothing more to draft tonight
            }
        }

        return $queued;
    }

    /** @return array{0: list<array<string, mixed>>, 1: Collection<int, DigitalAsset>} */
    private function unanswered(): array
    {
        $assets = DigitalAsset::query()->operational()->where('digital_assets.type', 'google_business_profile')->whereNotNull('digital_assets.brand_id')
            ->get(['digital_assets.id', 'digital_assets.brand_id', 'digital_assets.name'])->keyBy('id');
        $resources = $this->daily->resourceIds($assets->keys()->map(fn ($id): int => (int) $id)->all());
        if ($resources === []) {
            return [[], $assets];
        }
        $rows = $this->desk->reviews($resources, 'bekleyen', '', 500, 'eski', true)['rows'];

        return [$rows, $assets];
    }
}

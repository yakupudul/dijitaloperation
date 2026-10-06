<?php

namespace App\Services\Gbp\Desk;

use App\Models\CoreExternalResource;
use App\Models\GbpReviewFlag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Yorumlar › "Kaldırılmasını iste": Google has no API to report a review, so MoxDOP prepares the report (Google's
 * reason, a ready text, the links to Google's review management tool and to the review on Maps), the operator files
 * it there and marks it, and MoxDOP follows it: a reported review that a later full review collection no longer
 * returns becomes "Kaldırıldı".
 */
final class ReviewFlags
{
    /** Google's review management tool (report a review, follow and appeal the decision). */
    public const string TOOL_URL = 'https://support.google.com/business/workflow/16726127?hl=tr';

    public function save(User $user, int $reviewId, int $assetId, string $reason, string $note): GbpReviewFlag
    {
        if (! isset(GbpReviewFlag::REASONS[$reason])) {
            throw ValidationException::withMessages(['flag' => 'Google’ın kaldırma nedenlerinden birini seçin.']);
        }

        return GbpReviewFlag::query()->updateOrCreate(['gbp_review_id' => $reviewId], [
            'digital_asset_id' => $assetId, 'reason' => $reason, 'note' => mb_substr(trim($note), 0, 1000) ?: null, 'created_by' => $user->id,
        ]);
    }

    public function markReported(int $reviewId): void
    {
        GbpReviewFlag::query()->where('gbp_review_id', $reviewId)->whereIn('status', [GbpReviewFlag::DRAFT, GbpReviewFlag::KEPT])
            ->update(['status' => GbpReviewFlag::REPORTED, 'reported_at' => now(), 'resolved_at' => null]);
    }

    public function close(int $reviewId, string $status): void
    {
        if ($status === 'cancel') {
            GbpReviewFlag::query()->where('gbp_review_id', $reviewId)->delete();

            return;
        }
        GbpReviewFlag::query()->where('gbp_review_id', $reviewId)->update(['status' => $status === GbpReviewFlag::REMOVED ? GbpReviewFlag::REMOVED : GbpReviewFlag::KEPT, 'resolved_at' => now()]);
    }

    /**
     * Flags of the given reviews; reported ones that the last full collection no longer returned become "removed".
     *
     * @param  list<int>  $reviewIds
     * @return array<int, array{reason: string, reason_label: string, note: ?string, status: string, status_label: string, reported_at: ?string}>
     */
    public function forReviews(array $reviewIds): array
    {
        if ($reviewIds === []) {
            return [];
        }
        $flags = GbpReviewFlag::query()->whereIn('gbp_review_id', $reviewIds)->get();
        $this->detectRemoved($flags->where('status', GbpReviewFlag::REPORTED)->pluck('gbp_review_id')->map(fn ($id): int => (int) $id)->all());

        return GbpReviewFlag::query()->whereIn('gbp_review_id', $reviewIds)->get()->mapWithKeys(fn (GbpReviewFlag $f): array => [(int) $f->gbp_review_id => [
            'reason' => (string) $f->reason, 'reason_label' => GbpReviewFlag::REASONS[$f->reason] ?? $f->reason, 'note' => $f->note,
            'status' => (string) $f->status, 'status_label' => GbpReviewFlag::STATUS_LABELS[$f->status] ?? $f->status,
            'reported_at' => $f->reported_at?->timezone('Europe/Istanbul')->format('d.m.Y'),
        ]])->all();
    }

    /** @return list<int> review ids with an open (not yet resolved) or any flag */
    public function flaggedIds(array $resourceIds): array
    {
        return DB::table('gbp_review_flags')->join('gbp_reviews', 'gbp_reviews.id', '=', 'gbp_review_flags.gbp_review_id')
            ->whereIn('gbp_reviews.external_resource_id', $resourceIds)->pluck('gbp_reviews.id')->map(fn ($id): int => (int) $id)->all();
    }

    /** Text to paste into Google's form (Turkish; Google reviews reports in any language). */
    public static function reportText(string $reason, ?string $note): string
    {
        $base = match ($reason) {
            'spam' => 'Bu yorum gerçek bir müşteri deneyimine dayanmıyor; kayıtlarımızda bu kişiye ait bir randevu ya da işlem yok. Sahte / spam içerik politikasına aykırıdır.',
            'off_topic' => 'Yorum işletmemizdeki bir deneyimi anlatmıyor; konu dışı içerik politikasına aykırıdır.',
            'conflict' => 'Yorum, işletmeyle çıkar çatışması olan biri (rakip ya da eski çalışan) tarafından yazılmıştır; çıkar çatışması politikasına aykırıdır.',
            'profanity' => 'Yorum küfür ve müstehcen ifadeler içeriyor; uygunsuz içerik politikasına aykırıdır.',
            'harassment' => 'Yorum çalışanlarımızı hedef alan zorbalık ve taciz içeriyor.',
            'hate' => 'Yorum ayrımcı / nefret söylemi içeriyor.',
            'personal' => 'Yorum çalışanlarımıza ya da başka kişilere ait kişisel bilgiler içeriyor.',
            'illegal' => 'Yorum yasa dışı içerik barındırıyor.',
            'impersonation' => 'Yorum başka bir kişinin kimliğine bürünülerek yazılmıştır.',
            default => 'Yorum Google’ın yasaklanmış içerik politikasına aykırıdır.',
        };

        return trim($base.($note !== null && trim($note) !== '' ? ' '.trim($note) : ''));
    }

    /** @param list<int> $reviewIds */
    private function detectRemoved(array $reviewIds): void
    {
        if ($reviewIds === []) {
            return;
        }
        $reviews = DB::table('gbp_reviews')->whereIn('id', $reviewIds)->get(['id', 'external_resource_id', 'collected_at']);
        $resources = CoreExternalResource::query()->whereIn('id', $reviews->pluck('external_resource_id')->unique())->get(['id', 'metadata'])->keyBy('id');
        $flags = GbpReviewFlag::query()->whereIn('gbp_review_id', $reviewIds)->get()->keyBy('gbp_review_id');
        foreach ($reviews as $review) {
            $fullSync = data_get($resources->get($review->external_resource_id)?->metadata, 'gbp_reviews_full_sync_at');
            $flag = $flags->get($review->id);
            if (! is_string($fullSync) || $flag?->reported_at === null || $review->collected_at === null) {
                continue;
            }
            $sync = CarbonImmutable::parse($fullSync);
            if ($sync->greaterThan($flag->reported_at) && CarbonImmutable::parse((string) $review->collected_at)->lessThan($sync->subHours(3))) {
                $flag->forceFill(['status' => GbpReviewFlag::REMOVED, 'resolved_at' => now()])->save();
            }
        }
    }
}

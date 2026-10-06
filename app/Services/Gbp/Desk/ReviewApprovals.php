<?php

namespace App\Services\Gbp\Desk;

use App\Models\Brand;
use App\Models\GbpReviewApproval;
use App\Models\User;
use App\Services\Assistant\PushNotifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Yorumlar › "Markaya onaya gönder" (yakup, 2026-10-06; replaces mailing the PDF): the picked reviews with their
 * prepared replies go to the brand as a link without login (14 days, closable). The brand marks each reply "Uygun",
 * edits it or declines it and adds a note; the approved / edited texts become the reviews' drafts and the cards show
 * the brand's answer. Publishing stays with the Admin in MoxDOP (yakup's decision): nothing goes to Google from the link.
 */
final class ReviewApprovals
{
    public const int DAYS = 14;

    public const int MAX = 300;

    /**
     * @param  list<array<string, mixed>>  $rows  ReviewDesk rows; each needs a reply text in `text`
     * @param  array<int, string>  $names  asset id => business name
     */
    public function create(User $user, Brand $brand, array $rows, array $names): GbpReviewApproval
    {
        $items = [];
        foreach (array_slice($rows, 0, self::MAX) as $row) {
            $text = trim((string) ($row['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $items[] = ['review_id' => (int) $row['id'], 'asset_id' => (int) $row['asset_id'], 'business' => (string) ($names[$row['asset_id']] ?? ''),
                'reviewer' => ReviewDesk::firstName((string) $row['reviewer']), 'rating' => $row['rating'], 'comment' => (string) $row['comment'],
                'date' => (string) $row['date'], 'text' => $text];
        }
        if ($items === []) {
            throw ValidationException::withMessages(['approval' => 'Seçilenlerin hiçbirinde yanıt metni yok; önce taslak yazdırın ya da yanıtı yazın.']);
        }

        return GbpReviewApproval::query()->create(['token' => Str::random(48), 'brand_id' => $brand->id, 'items' => $items,
            'status' => GbpReviewApproval::OPEN, 'expires_at' => now()->addDays(self::DAYS), 'created_by' => $user->id]);
    }

    public static function url(GbpReviewApproval $approval): string
    {
        return route('gbp-review-approval', ['token' => $approval->token]);
    }

    /**
     * The brand's answer from the link: decisions[review id] = ok | edit | skip, texts[review id] = edited text.
     *
     * @param  array<int|string, mixed>  $decisions
     * @param  array<int|string, mixed>  $texts
     */
    public function answer(GbpReviewApproval $approval, array $decisions, array $texts, string $note): void
    {
        if (! $approval->isUsable()) {
            throw ValidationException::withMessages(['approval' => 'Bu bağlantının süresi doldu ya da kapatıldı.']);
        }
        $items = $approval->items;
        $missing = 0;
        foreach ($items as $index => $item) {
            $id = $item['review_id'];
            $decision = (string) ($decisions[$id] ?? '');
            $text = mb_substr(trim((string) ($texts[$id] ?? '')), 0, 4000);
            if (! isset(GbpReviewApproval::DECISIONS[$decision])) {
                $missing++;

                continue;
            }
            // An "Uygun" whose text was changed is an edit; an edit without text is the original.
            if ($decision === 'ok' && $text !== '' && $text !== $item['text']) {
                $decision = 'edit';
            }
            if ($decision === 'edit' && ($text === '' || $text === $item['text'])) {
                $decision = 'ok';
            }
            $items[$index]['decision'] = $decision;
            $items[$index]['brand_text'] = $decision === 'edit' ? $text : null;
        }
        if ($missing === count($items)) {
            throw ValidationException::withMessages(['approval' => 'En az bir yanıt için seçim yapın.']);
        }
        $approval->forceFill(['items' => $items, 'note' => mb_substr(trim($note), 0, 2000) ?: null, 'status' => GbpReviewApproval::ANSWERED, 'answered_at' => now()])->save();

        $author = User::query()->find($approval->created_by);
        $counts = ['ok' => 0, 'edit' => 0, 'skip' => 0];
        foreach ($items as $item) {
            if (! isset($item['decision'])) {
                continue;
            }
            $counts[$item['decision']]++;
            if ($author !== null && $item['decision'] !== 'skip') {
                app(ReviewDesk::class)->saveDraft($author, $item['review_id'], $item['decision'] === 'edit' ? (string) $item['brand_text'] : $item['text']);
            }
        }
        try {
            $brand = (string) $approval->brand?->name;
            app(PushNotifier::class)->send('gbp-review-approval:'.$approval->id.':'.$approval->answered_at?->timestamp, 'Marka yanıtları onayladı: '.$brand,
                $counts['ok'].' uygun, '.$counts['edit'].' düzeltildi, '.$counts['skip'].' istenmedi.'.($approval->note ? ' Not: '.Str::limit($approval->note, 160) : ''),
                'high', route('operator.gbp-reviews', ['marka' => $approval->brand_id, 'durum' => 'bekleyen']));
        } catch (Throwable) {
            // The answer is saved; a failed phone notice must not lose it.
        }
    }

    public function close(GbpReviewApproval $approval): void
    {
        $approval->forceFill(['status' => GbpReviewApproval::CLOSED])->save();
    }

    /**
     * The brand's latest word per review: answered decisions, else "waiting" while a usable link holds it.
     *
     * @param  list<int>  $reviewIds
     * @return array<int, array{state: string, label: string, note: ?string}>
     */
    public function forReviews(array $reviewIds): array
    {
        if ($reviewIds === []) {
            return [];
        }
        $wanted = array_flip($reviewIds);
        $out = [];
        $approvals = GbpReviewApproval::query()->where('created_at', '>=', now()->subDays(90))->orderBy('id')->get();
        foreach ($approvals as $approval) {
            foreach ($approval->items as $item) {
                $id = (int) $item['review_id'];
                if (! isset($wanted[$id])) {
                    continue;
                }
                if (isset($item['decision'])) {
                    $out[$id] = ['state' => $item['decision'], 'label' => GbpReviewApproval::DECISIONS[$item['decision']], 'note' => $approval->note];
                } elseif ($approval->status === GbpReviewApproval::OPEN && $approval->isUsable()) {
                    $out[$id] = ['state' => 'waiting', 'label' => 'Markada onay bekliyor', 'note' => null];
                }
            }
        }

        return $out;
    }

    /** @return Collection<int, GbpReviewApproval> the brand's links of the last 30 days, newest first */
    public function recent(?int $brandId)
    {
        return GbpReviewApproval::query()->with('brand:id,name')->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->where('created_at', '>=', now()->subDays(30))->latest('id')->limit(10)->get();
    }
}

<?php

namespace App\Services\Gbp\Desk;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\GbpReviewApproval;
use App\Models\GbpReviewFlag;
use App\Models\User;
use App\Services\Assistant\PushNotifier;
use App\Services\Gbp\GbpDailyWorkspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Yorumlar › "Markaya onaya gönder" (yakup, 2026-10-06; replaces mailing the PDF): the picked reviews with their
 * prepared replies go to the brand as a link without login (14 days, closable). The brand marks each reply "Uygun",
 * edits it or declines it and adds a note; the approved / edited texts become the reviews' drafts and the cards show
 * the brand's answer.
 *
 * Müşteri onay linki (yakup, 2026-10-26: "müşteri orada yanıtı onaylarsa sistem otomatik göndersin, burada ben
 * göndermeyeyim"): a standing link per brand or per profile (`live`) always lists the current drafts of unanswered
 * reviews, and what the client approves, on any link, goes to Google by itself as the link maker's ADR-073 reply write
 * (logged, undoable on the review screen). Review replies no longer go with the daily click.
 */
final class ReviewApprovals
{
    public const int DAYS = 14;

    public const int MAX = 300;

    /** A standing link stays open until it is closed. */
    public const int LIVE_DAYS = 3650;

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

    /** The standing link of a brand, or of one of its profiles; the open one is reused. */
    public function live(User $user, Brand $brand, ?DigitalAsset $asset = null): GbpReviewApproval
    {
        $existing = GbpReviewApproval::query()->where('live', true)->where('brand_id', $brand->id)->where('digital_asset_id', $asset?->id)
            ->where('status', '!=', GbpReviewApproval::CLOSED)->where('expires_at', '>', now())->latest('id')->first();

        return $existing ?? GbpReviewApproval::query()->create(['token' => Str::random(48), 'brand_id' => $brand->id, 'digital_asset_id' => $asset?->id,
            'live' => true, 'items' => [], 'status' => GbpReviewApproval::OPEN, 'expires_at' => now()->addDays(self::LIVE_DAYS), 'created_by' => $user->id]);
    }

    /**
     * What the client sees: a link's copy, or for a standing link the drafts of the scope's unanswered reviews right now
     * (oldest first; reviews the client declined, with an open removal request or with a reply on its way left out).
     *
     * @return list<array<string, mixed>>
     */
    public function items(GbpReviewApproval $approval): array
    {
        if (! $approval->live) {
            return $approval->items;
        }
        $assets = DigitalAsset::query()->operational()->where('digital_assets.type', 'google_business_profile')->where('digital_assets.brand_id', $approval->brand_id)
            ->when($approval->digital_asset_id !== null, fn ($q) => $q->where('digital_assets.id', $approval->digital_asset_id))
            ->get(['digital_assets.id', 'digital_assets.name'])->keyBy('id');
        $resources = app(GbpDailyWorkspace::class)->resourceIds($assets->keys()->map(fn ($id): int => (int) $id)->all());
        if ($resources === []) {
            return [];
        }
        $rows = array_values(array_filter(app(ReviewDesk::class)->reviews($resources, 'bekleyen', '', self::MAX, 'eski')['rows'],
            fn (array $r): bool => trim((string) $r['draft']) !== '' && ! ReviewDesk::busy($r)));
        $ids = array_column($rows, 'id');
        $declined = array_filter($this->forReviews($ids), fn (array $a): bool => $a['state'] === 'skip');
        $flagged = array_filter(app(ReviewFlags::class)->forReviews($ids), fn (array $f): bool => in_array($f['status'], [GbpReviewFlag::DRAFT, GbpReviewFlag::REPORTED], true));

        return array_values(array_map(fn (array $r): array => ['review_id' => (int) $r['id'], 'asset_id' => (int) $r['asset_id'],
            'business' => GbpDesk::shortName((string) ($assets[$r['asset_id']]->name ?? '')), 'reviewer' => ReviewDesk::firstName((string) $r['reviewer']),
            'rating' => $r['rating'], 'comment' => (string) $r['comment'], 'date' => (string) $r['date'], 'text' => trim((string) $r['draft'])],
            array_filter($rows, fn (array $r): bool => ! isset($declined[$r['id']]) && ! isset($flagged[$r['id']]))));
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
        $shown = $this->items($approval);
        $missing = 0;
        $answered = [];
        foreach ($shown as $item) {
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
            $answered[$id] = array_replace($item, ['decision' => $decision, 'brand_text' => $decision === 'edit' ? $text : null, 'decided_at' => now()->toIso8601String()]);
        }
        if ($shown === [] || $missing === count($shown)) {
            throw ValidationException::withMessages(['approval' => 'En az bir yanıt için seçim yapın.']);
        }
        if ($approval->live) {
            // A standing link keeps every answer it got (newest wins) and stays open for the next drafts.
            $items = collect($approval->items)->keyBy('review_id')->replace($answered)->values()->all();
            $approval->forceFill(['items' => $items, 'note' => mb_substr(trim($note), 0, 2000) ?: $approval->note, 'answered_at' => now()])->save();
        } else {
            $items = array_map(fn (array $item): array => $answered[$item['review_id']] ?? $item, $approval->items);
            $approval->forceFill(['items' => $items, 'note' => mb_substr(trim($note), 0, 2000) ?: null, 'status' => GbpReviewApproval::ANSWERED, 'answered_at' => now()])->save();
        }

        $sent = $this->publish($approval, $answered);
        $counts = ['ok' => 0, 'edit' => 0, 'skip' => 0];
        foreach ($answered as $item) {
            $counts[$item['decision']]++;
        }
        try {
            $brand = (string) $approval->brand?->name;
            $failed = $counts['ok'] + $counts['edit'] - $sent;
            app(PushNotifier::class)->send('gbp-review-approval:'.$approval->id.':'.$approval->answered_at?->timestamp, 'Müşteri yorum yanıtlarını onayladı: '.$brand,
                $sent.' yanıt Google’a gönderildi'.($failed > 0 ? ', '.$failed.' gönderilemedi (Yorumlar ekranında)' : '').', '.$counts['skip'].' yanıt istenmedi.'
                .($approval->note ? ' Not: '.Str::limit($approval->note, 160) : ''),
                'high', route('operator.gbp-reviews', ['marka' => $approval->brand_id, 'durum' => 'bekleyen']));
        } catch (Throwable) {
            // The answer is saved; a failed phone notice must not lose it.
        }
    }

    /**
     * The client's approval is the send: each approved / edited text becomes the review's draft and goes to Google as
     * the link maker's reply write. A review answered meanwhile is left alone; a failed send keeps its draft.
     *
     * @param  array<int, array<string, mixed>>  $answered
     */
    private function publish(GbpReviewApproval $approval, array $answered): int
    {
        $author = User::query()->find($approval->created_by);
        $approved = array_filter($answered, fn (array $item): bool => $item['decision'] !== 'skip');
        if ($author === null || $approved === []) {
            return 0;
        }
        $desk = app(ReviewDesk::class);
        $open = array_flip($desk->openIds(array_keys($approved)));
        $sent = 0;
        foreach ($approved as $id => $item) {
            $text = $item['decision'] === 'edit' ? (string) $item['brand_text'] : (string) $item['text'];
            $desk->saveDraft($author, $id, $text);
            if (! isset($open[$id])) {
                continue;
            }
            try {
                $desk->send($author, $id, $text);
                $sent++;
            } catch (Throwable $error) {
                if (! $error instanceof ValidationException) {
                    report($error);
                }
            }
        }

        return $sent;
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
                } elseif (! $approval->live && $approval->status === GbpReviewApproval::OPEN && $approval->isUsable()) {
                    $out[$id] = ['state' => 'waiting', 'label' => 'Markada onay bekliyor', 'note' => null];
                }
            }
        }

        return $out;
    }

    /** @return Collection<int, GbpReviewApproval> the brand's links of the last 30 days and its open standing links, newest first */
    public function recent(?int $brandId)
    {
        return GbpReviewApproval::query()->with(['brand:id,name', 'digitalAsset:id,name'])->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->where(fn ($q) => $q->where('created_at', '>=', now()->subDays(30))->orWhere(fn ($w) => $w->where('live', true)->where('status', '!=', GbpReviewApproval::CLOSED)))
            ->latest('id')->limit(10)->get();
    }
}

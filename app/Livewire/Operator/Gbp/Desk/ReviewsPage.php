<?php

namespace App\Livewire\Operator\Gbp\Desk;

use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\Desk\ReviewDesk;
use App\Services\Gbp\Desk\ReviewFlags;
use App\Services\Gbp\GbpDailyWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * İşletme profilleri › Yorumlar (`/gbp/yorumlar`, ADR-079):
 *  - Yorumlar: every review of the profiles in scope as a 4-column grid that loads more while scrolling (waiting for a
 *    reply oldest first; answered / all newest first). Reviews are picked one by one or in groups; picked ones get AI
 *    drafts or one shared reply ("{ad}" = first name), are previewed together with warnings (empty, same text on 3+,
 *    short reply to a low rating) and published by the Admin, each as its own undoable ADR-073 reply write.
 *  - Yorum isteme: per profile the review numbers of the last 90 days and the kit to ask happy customers for a
 *    review: Google's review link, its QR code, a message to copy and a printable counter card.
 */
#[Layout('operator.layouts.app')]
#[Title('Yorumlar')]
final class ReviewsPage extends Component
{
    use DeskScope;

    #[Url(as: 'bolum')]
    public string $section = 'yanit';

    #[Url(as: 'durum')]
    public string $status = 'bekleyen';

    #[Url(as: 'puan')]
    public string $rating = '';

    /** Rows shown on the grid (grows by ReviewDesk::PAGE while scrolling). */
    public int $limit = ReviewDesk::PAGE;

    /** @var array<string, string> 'r'.review id => reply text being edited */
    public array $replies = [];

    /** @var list<int> review ids picked for a bulk step */
    public array $selected = [];

    /** One reply for every picked review ("{ad}" = the reviewer's first name). */
    public string $bulkText = '';

    public bool $previewOpen = false;

    /** One profile of the brand (null = all profiles in the brand filter). */
    #[Url(as: 'isletme')]
    public ?int $location = null;

    /** Review whose removal request is being written, its Google reason and the operator's note. */
    public ?int $flagging = null;

    public string $flagReason = '';

    public string $flagNote = '';

    /** Set when the screen is embedded in one profile's asset page (Yorumlar tab): only that profile, no filters. */
    #[Locked]
    public ?int $asset = null;

    public function mount(?int $asset = null): void
    {
        abort_unless(auth()->user()?->is_active, 403);
        if ($asset !== null) {
            $this->asset = $asset;
            $this->location = $asset;
            $this->brand = ($brandId = DigitalAsset::query()->whereKey($asset)->value('brand_id')) !== null ? (int) $brandId : null;
        }
        $this->section = in_array($this->section, ['yanit', 'iste'], true) ? $this->section : 'yanit';
        $this->status = isset(ReviewDesk::STATUSES[$this->status]) ? $this->status : 'bekleyen';
        $this->rating = isset(GbpDailyWorkspace::RATING_FILTERS[$this->rating]) ? $this->rating : '';
    }

    public function setSection(string $section): void
    {
        $this->section = in_array($section, ['yanit', 'iste'], true) ? $section : 'yanit';
    }

    public function setLocation(?int $location = null): void
    {
        if ($this->asset !== null) {
            return;
        }
        $this->location = $location;
        $this->resetList();
    }

    public function updatedBrand(mixed $value): void
    {
        if ($this->asset !== null) {
            $this->brand = ($brandId = DigitalAsset::query()->whereKey($this->asset)->value('brand_id')) !== null ? (int) $brandId : null;

            return;
        }
        $this->brand = filled($value) ? (int) $value : null;
        $this->location = null;
        $this->resetList();
    }

    public function startFlag(int $reviewId): void
    {
        abort_unless($this->canWrite(), 403);
        $this->flagging = $reviewId;
        $flag = app(ReviewFlags::class)->forReviews([$reviewId])[$reviewId] ?? null;
        $this->flagReason = $flag['reason'] ?? '';
        $this->flagNote = (string) ($flag['note'] ?? '');
    }

    public function saveFlag(ReviewDesk $desk, ReviewFlags $flags): void
    {
        abort_unless($this->canWrite(), 403);
        $review = collect($this->reviews($desk))->firstWhere('id', $this->flagging) ?? abort(404);
        try {
            $flags->save(auth()->user(), (int) $review['id'], (int) $review['asset_id'], $this->flagReason, $this->flagNote);
            $this->flagging = null;
            $this->say('Kaldırma talebi hazır: kartta Google’ın aracını açıp bildirin, sonra “Google’a bildirdim” deyin.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function markReported(int $reviewId, ReviewFlags $flags): void
    {
        abort_unless($this->canWrite(), 403);
        $flags->markReported($reviewId);
        $this->say('Bildirim kaydedildi; Google kaldırırsa bir sonraki tam yorum toplamasında “Kaldırıldı” olur.');
    }

    public function closeFlag(int $reviewId, string $status, ReviewFlags $flags): void
    {
        abort_unless($this->canWrite(), 403);
        $flags->close($reviewId, $status);
        $this->flagging = null;
    }

    public function setStatus(string $status): void
    {
        $this->status = isset(ReviewDesk::STATUSES[$status]) ? $status : 'bekleyen';
        $this->resetList();
    }

    public function setRating(string $rating): void
    {
        $this->rating = isset(GbpDailyWorkspace::RATING_FILTERS[$rating]) ? $rating : '';
        $this->resetList();
    }

    /** Next rows of the grid (called when the end of the list scrolls into view). */
    public function more(): void
    {
        $this->limit = min($this->limit + ReviewDesk::PAGE, 2000);
    }

    /** Picks the shown reviews that can get a reply: all, those with a ready text, or rating-only 4–5 stars. */
    public function pick(string $mode, ReviewDesk $desk): void
    {
        $open = array_filter($this->reviews($desk), fn (array $r): bool => ! ReviewDesk::busy($r));
        $this->selected = array_values(array_map(fn (array $r): int => $r['id'], array_filter($open, fn (array $r): bool => match ($mode) {
            'ready' => trim($this->text($r)) !== '',
            'silent' => $r['comment'] === '' && ($r['rating'] ?? 0) >= 4,
            'none' => false,
            default => true,
        })));
    }

    public function draftSelected(ReviewDesk $desk): void
    {
        abort_unless($this->canWrite(), 403);
        $picked = $this->picked($desk);
        $queued = $desk->draftAll($picked !== [] ? $picked : $this->reviews($desk));
        $this->say($queued > 0 ? $queued.' yorum için yanıt taslağı yazılıyor; hazır olanlar kartlarda görünür.' : 'Seçilenlerin hepsinin taslağı ya da yanıtı var.');
    }

    /** AI draft for one card. */
    public function draftOne(int $reviewId, ReviewDesk $desk): void
    {
        abort_unless($this->canWrite(), 403);
        $review = collect($this->reviews($desk))->firstWhere('id', $reviewId) ?? abort(404);
        $desk->draftAll([$review]);
    }

    /** Takes a reply MoxDOP sent back from Google (ADR-073 undo). */
    public function undoReply(int $actionId, ExternalWriteService $writes): void
    {
        abort_unless($this->canWrite(), 403);
        $action = ExternalWriteAction::query()->whereKey($actionId)->where('action', ExternalWriteAction::ACTION_REVIEW_REPLY)->firstOrFail();
        try {
            $writes->requestUndo(auth()->user(), $action);
            $this->say('Yanıt Google’dan geri alınıyor.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    /** Writes the shared reply into every picked review's box (personalized; editable afterwards). */
    public function fillSelected(ReviewDesk $desk): void
    {
        abort_unless($this->canWrite(), 403);
        $text = trim($this->bulkText);
        if ($text === '' || $this->selected === []) {
            $this->say('Önce yorum seçin ve ortak yanıtı yazın.', 'error');

            return;
        }
        foreach ($this->picked($desk) as $review) {
            $this->replies['r'.$review['id']] = ReviewDesk::personalize($text, $review['reviewer']);
            $desk->saveDraft(auth()->user(), $review['id'], $this->replies['r'.$review['id']]);
        }
        $this->say(count($this->selected).' yoruma ortak yanıt yazıldı; kartlarda düzenleyebilir, ön izleyip yayımlayabilirsiniz.');
    }

    /** An edited reply box is kept as the review's draft (for the brand PDF and the next visit). */
    public function updatedReplies(mixed $value, string $key): void
    {
        if (! $this->canWrite() || ! str_starts_with($key, 'r') || ! ctype_digit(substr($key, 1))) {
            return;
        }
        app(ReviewDesk::class)->saveDraft(auth()->user(), (int) substr($key, 1), (string) $value);
    }

    /** Address of the brand approval PDF: the picked reviews, else every waiting review with a reply text in scope. */
    public function pdfUrl(): string
    {
        return route('operator.gbp-review-replies-pdf', array_filter(['marka' => $this->brand, 'isletme' => $this->location, 'yorumlar' => $this->selected !== [] ? implode(',', array_map('intval', $this->selected)) : null]));
    }

    public function openPreview(ReviewDesk $desk): void
    {
        if ($this->picked($desk) === []) {
            $this->say('Ön izlemek için yanıt bekleyen yorum seçin.', 'error');

            return;
        }
        $this->previewOpen = true;
    }

    public function closePreview(): void
    {
        $this->previewOpen = false;
    }

    /** Admin: the picked reviews' texts go to Google (each its own, undoable ADR-073 write). */
    public function publishSelected(ReviewDesk $desk): void
    {
        abort_unless($this->canWrite(), 403);
        $rows = array_map(fn (array $r): array => ['draft' => $this->text($r)] + $r, $this->picked($desk));
        $empty = count(array_filter($rows, fn (array $r): bool => trim((string) $r['draft']) === ''));
        $result = $desk->sendDrafts(auth()->user(), $rows);
        foreach ($rows as $row) {
            unset($this->replies['r'.$row['id']]);
        }
        $this->selected = [];
        $this->previewOpen = false;
        $this->say($result['sent'].' yanıt Google’a gönderiliyor.'.($result['failed'] > 0 ? ' '.$result['failed'].' yanıt gönderilemedi (metni kontrol edin).' : '')
            .($empty > 0 ? ' '.$empty.' yorumun yanıtı boş olduğu için atlandı.' : ''), $result['sent'] === 0 ? 'error' : 'info');
    }

    public function send(int $reviewId, ReviewDesk $desk): void
    {
        abort_unless($this->canWrite(), 403);
        $review = collect($this->reviews($desk))->firstWhere('id', $reviewId) ?? abort(404);
        try {
            $desk->send(auth()->user(), $reviewId, trim($this->text($review)));
            unset($this->replies['r'.$reviewId]);
            $this->selected = array_values(array_diff($this->selected, [$reviewId]));
            $this->say('Yanıt Google’a gönderiliyor.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    private function resetList(): void
    {
        $this->limit = ReviewDesk::PAGE;
        $this->selected = [];
        $this->previewOpen = false;
    }

    /** The reply a review would get now: the edited box, else its AI draft. */
    private function text(array $review): string
    {
        return (string) ($this->replies['r'.$review['id']] ?? $review['draft'] ?? '');
    }

    /** @return list<array<string, mixed>> picked reviews that can still get a reply */
    private function picked(ReviewDesk $desk): array
    {
        $ids = array_flip(array_map('intval', $this->selected));

        return array_values(array_filter($this->reviews($desk), fn (array $r): bool => isset($ids[$r['id']]) && ! ReviewDesk::busy($r)));
    }

    /** @return list<array<string, mixed>> */
    private function reviews(ReviewDesk $desk): array
    {
        return $this->list($desk)['rows'];
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    private function list(ReviewDesk $desk): array
    {
        $resources = app(GbpDailyWorkspace::class)->resourceIds($this->reviewLocations()->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return $desk->reviews($resources, $this->status, $this->rating, $this->limit);
    }

    /** @return Collection<int, DigitalAsset> the brand's profiles, or the one picked */
    /** @return Collection<int, DigitalAsset> on an asset page that profile alone (also when its brand is not operational) */
    protected function scopedLocations(): Collection
    {
        if ($this->asset !== null) {
            return DigitalAsset::query()->with('brand:id,name,sector_id')->whereKey($this->asset)
                ->get(['digital_assets.id', 'digital_assets.name', 'digital_assets.brand_id', 'digital_assets.type']);
        }

        return app(GbpDesk::class)->locations($this->brand);
    }

    private function reviewLocations(): Collection
    {
        $locations = $this->scopedLocations();

        return $this->location !== null && $locations->contains('id', $this->location) ? $locations->where('id', $this->location)->values() : $locations;
    }

    public function render(ReviewDesk $desk, GbpDailyWorkspace $daily, ReviewFlags $flags): View
    {
        $all = $this->scopedLocations();
        if ($this->asset !== null) {
            $this->location = $this->asset;
            $all = $all->where('id', $this->asset)->values();
        } elseif ($this->location !== null && ! $all->contains('id', $this->location)) {
            $this->location = null;
        }
        $allStats = $desk->stats($daily->resourceIds($all->pluck('id')->map(fn ($id): int => (int) $id)->all()));
        $locations = $this->reviewLocations();
        $resources = $daily->resourceIds($locations->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $stats = array_intersect_key($allStats, $resources);
        $list = $this->section === 'yanit' ? $desk->reviews($resources, $this->status, $this->rating, $this->limit) : ['rows' => [], 'total' => 0];
        $reviews = $list['rows'];
        foreach ($reviews as $review) {
            if ($review['draft'] !== null && ! ReviewDesk::busy($review) && ! array_key_exists('r'.$review['id'], $this->replies)) {
                $this->replies['r'.$review['id']] = (string) $review['draft'];
            }
        }
        $kits = [];
        if ($this->section === 'iste') {
            foreach ($locations as $item) {
                $kits[$item->id] = $desk->kit($item);
            }
        }
        $ids = array_flip(array_map('intval', $this->selected));
        $picked = array_values(array_filter($reviews, fn (array $r): bool => isset($ids[$r['id']]) && ! ReviewDesk::busy($r)));
        $preview = [];
        if ($this->previewOpen) {
            $texts = array_count_values(array_map(fn (array $r): string => mb_strtolower(trim($this->text($r))), $picked));
            foreach ($picked as $review) {
                $text = trim($this->text($review));
                $preview[] = $review + ['text' => $text, 'warnings' => array_values(array_filter([
                    $text === '' ? 'Yanıt boş; gönderilmez.' : null,
                    $text !== '' && ($texts[mb_strtolower($text)] ?? 0) >= 3 ? 'Aynı metin '.$texts[mb_strtolower($text)].' yoruma gidiyor; Google tekrar eden yanıtları sevmez, birkaçını kişiselleştirin.' : null,
                    $text !== '' && ($review['rating'] ?? 5) <= 2 && mb_strlen($text) < 80 ? 'Düşük puanlı yorumda yanıt çok kısa.' : null,
                ]))];
            }
        }
        $open = array_filter($reviews, fn (array $r): bool => ! ReviewDesk::busy($r));
        $flagged = $flags->forReviews(array_column($reviews, 'id'));
        $places = [];
        foreach (app(GbpDesk::class)->snapshots(array_values(array_unique(array_map(fn (array $r): int => $r['asset_id'], array_filter($reviews, fn (array $r): bool => isset($flagged[$r['id']]) || $r['id'] === $this->flagging))))) as $assetId => $snapshot) {
            $places[$assetId] = (string) ($snapshot['place_id'] ?? '');
        }
        $chips = $all->map(fn ($l): array => ['id' => (int) $l->id, 'name' => GbpDesk::shortName((string) $l->name), 'unanswered' => (int) ($allStats[$l->id]['unanswered'] ?? 0)])
            ->sortBy([['unanswered', 'desc'], ['name', 'asc']])->values()->all();

        return view('livewire.operator.gbp.desk.reviews', [
            'groups' => $locations->groupBy(fn ($l): string => (string) $l->brand?->name),
            'names' => $all->mapWithKeys(fn ($l): array => [$l->id => GbpDesk::shortName((string) $l->name)])->all(),
            'stats' => $stats,
            'reviews' => $reviews,
            'total' => $list['total'],
            'kits' => $kits,
            'totals' => [
                'unanswered' => array_sum(array_column($stats, 'unanswered')),
                'late' => array_sum(array_column($stats, 'late')),
                'recent' => array_sum(array_column($stats, 'recent')),
            ],
            'pickedCount' => count($picked),
            'readyCount' => count(array_filter($picked, fn (array $r): bool => trim($this->text($r)) !== '')),
            'openCount' => count($open),
            'preview' => $preview,
            'flags' => $flagged,
            'places' => $places,
            'chips' => count($chips) > 1 ? $chips : [],
            'allUnanswered' => array_sum(array_column($allStats, 'unanswered')),
            'drafting' => collect($reviews)->contains(fn (array $r): bool => $r['draft_state'] === 'running'),
            'canWrite' => $this->canWrite(),
            'brandOptions' => $this->brandOptions(),
        ]);
    }
}

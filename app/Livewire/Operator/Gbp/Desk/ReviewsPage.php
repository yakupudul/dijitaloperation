<?php

namespace App\Livewire\Operator\Gbp\Desk;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpReviewApproval;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\Desk\ReviewApprovals;
use App\Services\Gbp\Desk\ReviewDesk;
use App\Services\Gbp\Desk\ReviewFlags;
use App\Services\Gbp\GbpDailyWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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

    /** Two replies sharing at least this share of their words are near copies (preview warning). */
    private const float NEAR_COPY = 0.85;

    #[Url(as: 'bolum')]
    public string $section = 'yanit';

    #[Url(as: 'durum')]
    public string $status = 'bekleyen';

    #[Url(as: 'puan')]
    public string $rating = '';

    /** Order of the grid: newest first (default) or oldest first. */
    #[Url(as: 'sira')]
    public string $sort = 'yeni';

    /** Only reviews of the last 90 days. */
    #[Url(as: 'son')]
    public bool $recentOnly = false;

    /** Words in the review text or the reviewer's name. */
    #[Url(as: 'ara')]
    public string $search = '';

    /** Answered review whose reply is being edited, and the new text. */
    public ?int $editing = null;

    public string $editText = '';

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

    /** Brand approval link just made (shown with a copy button). */
    public ?string $approvalUrl = null;

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
        $this->sort = isset(ReviewDesk::SORTS[$this->sort]) ? $this->sort : 'yeni';
        $this->search = mb_substr(trim($this->search), 0, 80);
    }

    public function setSort(string $sort): void
    {
        $this->sort = isset(ReviewDesk::SORTS[$sort]) ? $sort : 'yeni';
        $this->resetList();
    }

    public function toggleRecent(): void
    {
        $this->recentOnly = ! $this->recentOnly;
        $this->resetList();
    }

    public function updatedSearch(): void
    {
        $this->search = mb_substr(trim($this->search), 0, 80);
        $this->resetList();
    }

    /** A doctor / service chip of "Neler konuşuluyor": the grid shows every review naming it. */
    public function searchFor(string $word): void
    {
        $this->search = mb_substr(trim($word), 0, 80);
        $this->status = 'tumu';
        $this->rating = '';
        $this->resetList();
    }

    /** "Kötü yorumlar": unanswered 1–2 ★ reviews. */
    public function showLowOpen(): void
    {
        $this->status = 'bekleyen';
        $this->rating = 'low';
        $this->search = '';
        $this->resetList();
    }

    /** "Düzenle" on an answered review: its reply in a box (the new text replaces it on Google; undo restores it). */
    public function startEdit(int $reviewId, ReviewDesk $desk): void
    {
        abort_unless($this->canWrite(), 403);
        $review = collect($this->reviews($desk))->firstWhere('id', $reviewId) ?? abort(404);
        $this->editing = $reviewId;
        $this->editText = (string) $review['reply'];
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
        $this->editText = '';
    }

    public function saveEdit(ReviewDesk $desk): void
    {
        abort_unless($this->canWrite(), 403);
        $review = collect($this->reviews($desk))->firstWhere('id', $this->editing) ?? abort(404);
        if (! $review['answered']) {
            return;
        }
        if (trim($this->editText) === trim((string) $review['reply'])) {
            $this->cancelEdit();

            return;
        }
        try {
            $desk->send(auth()->user(), (int) $review['id'], trim($this->editText));
            $this->cancelEdit();
            $this->say('Yanıtın yeni metni Google’a gönderiliyor; “Geri al” eski metni geri koyar.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
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
        $brandAnswers = $mode === 'brand' ? app(ReviewApprovals::class)->forReviews(array_column($open, 'id')) : [];
        $this->selected = array_values(array_map(fn (array $r): int => $r['id'], array_filter($open, fn (array $r): bool => match ($mode) {
            'ready' => trim($this->text($r)) !== '',
            'nodraft' => trim($this->text($r)) === '' && $r['draft_state'] !== 'running',
            'brand' => in_array($brandAnswers[$r['id']]['state'] ?? null, ['ok', 'edit'], true),
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
        $more = $queued >= ReviewDesk::DRAFT_BATCH ? ' Bir seferde en fazla '.ReviewDesk::DRAFT_BATCH.' taslak istenir; kalanlar için bitince yeniden basın.' : '';
        $this->say($queued > 0 ? $queued.' yorum için yanıt taslağı yazılıyor; hazır olanlar kartlarda görünür.'.$more : 'Seçilenlerin hepsinin taslağı ya da yanıtı var.');
    }

    /**
     * "Markaya onaya gönder": the picked reviews with a reply go to the brand as a link (one brand at a time); the
     * brand's approved / edited texts come back as drafts, publishing stays here.
     */
    public function sendToBrand(ReviewDesk $desk, ReviewApprovals $approvals): void
    {
        abort_unless($this->canWrite(), 403);
        $rows = array_values(array_filter($this->picked($desk), fn (array $r): bool => trim($this->text($r)) !== ''));
        $brandIds = $this->scopedLocations()->whereIn('id', array_unique(array_column($rows, 'asset_id')))->pluck('brand_id')->unique()->values();
        if ($rows === []) {
            $this->say('Seçilenlerin hiçbirinde yanıt yok; önce taslak yazdırın ya da yanıtı yazın.', 'error');

            return;
        }
        if ($brandIds->count() !== 1) {
            $this->say('Onay bağlantısı tek marka için hazırlanır; yalnız bir markanın yorumlarını seçin (üstten marka süzgeci).', 'error');

            return;
        }
        $names = $this->scopedLocations()->mapWithKeys(fn ($l): array => [(int) $l->id => GbpDesk::shortName((string) $l->name)])->all();
        $rows = array_map(function (array $r) use ($desk): array {
            $desk->saveDraft(auth()->user(), $r['id'], $this->text($r));

            return $r + ['text' => trim($this->text($r))];
        }, $rows);
        try {
            $approval = $approvals->create(auth()->user(), Brand::query()->findOrFail($brandIds->first()), $rows, $names);
            $this->approvalUrl = ReviewApprovals::url($approval);
            $this->selected = [];
            $this->say(count($approval->items).' yanıt için onay bağlantısı hazır; kopyalayıp markaya gönderin.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function closeApproval(int $approvalId, ReviewApprovals $approvals): void
    {
        abort_unless($this->canWrite(), 403);
        $approvals->close(GbpReviewApproval::query()->findOrFail($approvalId));
        $this->approvalUrl = null;
        $this->say('Onay bağlantısı kapatıldı; marka artık açamaz.');
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

    /** "Taslağı sil" on one card: its drafts are discarded and the box is emptied. */
    public function deleteDraft(int $reviewId, ReviewDesk $desk): void
    {
        abort_unless($this->canWrite(), 403);
        $review = collect($this->reviews($desk))->firstWhere('id', $reviewId) ?? abort(404);
        if (ReviewDesk::busy($review)) {
            return;
        }
        $desk->discardDrafts(auth()->user(), [$reviewId]);
        unset($this->replies['r'.$reviewId]);
        $this->say('Taslak silindi.');
    }

    /** "Taslakları sil" in the bulk bar: the drafts of every picked review. */
    public function deleteSelectedDrafts(ReviewDesk $desk): void
    {
        abort_unless($this->canWrite(), 403);
        $ids = array_column($this->picked($desk), 'id');
        $count = $desk->discardDrafts(auth()->user(), $ids);
        foreach ($ids as $id) {
            unset($this->replies['r'.$id]);
        }
        $this->say($count > 0 ? $count.' yorumun taslağı silindi.' : 'Seçilenlerin taslağı yok.');
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

        return $desk->reviews($resources, $this->status, $this->rating, $this->limit, $this->sort, $this->recentOnly, $this->search);
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
        $list = $this->section === 'yanit' ? $desk->reviews($resources, $this->status, $this->rating, $this->limit, $this->sort, $this->recentOnly, $this->search) : ['rows' => [], 'total' => 0];
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
        $brandAnswers = app(ReviewApprovals::class)->forReviews(array_column($reviews, 'id'));
        $ids = array_flip(array_map('intval', $this->selected));
        $picked = array_values(array_filter($reviews, fn (array $r): bool => isset($ids[$r['id']]) && ! ReviewDesk::busy($r)));
        $preview = [];
        if ($this->previewOpen) {
            $texts = array_count_values(array_map(fn (array $r): string => mb_strtolower(trim($this->text($r))), $picked));
            $words = [];
            foreach ($picked as $review) {
                $words[$review['id']] = ReviewDesk::words($this->text($review), preg_split('/\s+/u', (string) $review['reviewer']) ?: []);
            }
            $published = $desk->publishedReplies(array_values($resources));
            foreach ($picked as $review) {
                $text = trim($this->text($review));
                // Near copies: another picked reply or one already on Google shares ≥ 85% of the words (names aside).
                $twin = null;
                foreach ($picked as $other) {
                    if ($other['id'] !== $review['id'] && mb_strtolower(trim($this->text($other))) !== mb_strtolower($text)
                        && ReviewDesk::similarity($words[$review['id']], $words[$other['id']]) >= self::NEAR_COPY) {
                        $twin = $other['reviewer'];
                        break;
                    }
                }
                $repeat = $text !== '' && collect($published)->contains(fn (array $p): bool => ReviewDesk::similarity($words[$review['id']], $p['words']) >= self::NEAR_COPY);
                $preview[] = $review + ['text' => $text, 'warnings' => array_values(array_filter([
                    $text === '' ? 'Yanıt boş; gönderilmez.' : null,
                    $text !== '' && ($texts[mb_strtolower($text)] ?? 0) >= 2 ? 'Aynı metin '.$texts[mb_strtolower($text)].' yoruma gidiyor; Google tekrar eden yanıtları spam sayabilir, kişiselleştirin.' : null,
                    $text !== '' && $twin !== null ? $twin.' yorumunun yanıtıyla neredeyse aynı; birini değiştirin.' : null,
                    $repeat ? 'Bu şubede Google’da daha önce verilmiş bir yanıtla neredeyse aynı.' : null,
                    $text !== '' && ($review['rating'] ?? 5) <= 2 && mb_strlen($text) < 80 ? 'Düşük puanlı yorumda yanıt çok kısa.' : null,
                    ($brandAnswers[$review['id']]['state'] ?? null) === 'skip' ? 'Marka bu yoruma yanıt verilmesini istemedi.' : null,
                    ($brandAnswers[$review['id']]['state'] ?? null) === 'waiting' ? 'Markanın onayı henüz gelmedi.' : null,
                ]))];
            }
        }
        $open = array_filter($reviews, fn (array $r): bool => ! ReviewDesk::busy($r));
        $flagged = $flags->forReviews(array_column($reviews, 'id'));
        $places = [];
        foreach (app(GbpDesk::class)->snapshots(array_values(array_unique(array_map(fn (array $r): int => $r['asset_id'], array_filter($reviews, fn (array $r): bool => isset($flagged[$r['id']]) || $r['id'] === $this->flagging))))) as $assetId => $snapshot) {
            $places[$assetId] = (string) ($snapshot['place_id'] ?? '');
        }
        $brandIds = $locations->pluck('brand_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $topics = $this->section === 'yanit' ? Cache::remember('gbp-review-topics:'.md5(json_encode([array_values($resources), $brandIds])), now()->addMinutes(30),
            fn (): array => $desk->topics(array_values($resources), $brandIds)) : ['doctors' => [], 'services' => []];
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
            // "AI ile taslak yaz" only asks for these: no reply text yet and no draft being written.
            'toDraftCount' => count(array_filter($picked, fn (array $r): bool => trim($this->text($r)) === '' && $r['draft_state'] !== 'running')),
            'openCount' => count($open),
            'openReady' => count(array_filter($open, fn (array $r): bool => trim($this->text($r)) !== '')),
            'preview' => $preview,
            'flags' => $flagged,
            'brandAnswers' => $brandAnswers,
            'approvalLinks' => $this->canWrite() ? app(ReviewApprovals::class)->recent($this->brand) : collect(),
            'places' => $places,
            'chips' => count($chips) > 1 ? $chips : [],
            'allUnanswered' => array_sum(array_column($allStats, 'unanswered')),
            'drafting' => collect($reviews)->contains(fn (array $r): bool => $r['draft_state'] === 'running'),
            'canWrite' => $this->canWrite(),
            'brandOptions' => $this->brandOptions(),
            'topics' => $topics,
            'lowOpen' => array_sum(array_column($stats, 'low_open')),
            'card' => $locations->count() > 1 ? $locations->map(fn ($l): array => ['id' => (int) $l->id, 'name' => GbpDesk::shortName((string) $l->name)] + ($stats[$l->id] ?? []))
                ->filter(fn (array $row): bool => ($row['total'] ?? 0) > 0)->sortByDesc('unanswered')->values()->all() : [],
        ]);
    }
}

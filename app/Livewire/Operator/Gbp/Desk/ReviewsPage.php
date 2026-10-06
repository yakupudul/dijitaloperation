<?php

namespace App\Livewire\Operator\Gbp\Desk;

use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\Desk\ReviewDesk;
use App\Services\Gbp\GbpDailyWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * İşletme profilleri › Yorumlar (`/gbp/yorumlar`, ADR-079):
 *  - Yanıt bekleyenler: every unanswered review of the profiles in scope in one list, oldest waiting first, with its AI
 *    draft (one click drafts them all); the Admin edits and sends one, or sends every drafted reply (ADR-073 write).
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

    #[Url(as: 'puan')]
    public string $rating = '';

    /** @var array<string, string> 'r'.review id => reply text being edited */
    public array $replies = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active, 403);
        $this->section = in_array($this->section, ['yanit', 'iste'], true) ? $this->section : 'yanit';
        $this->rating = isset(GbpDailyWorkspace::RATING_FILTERS[$this->rating]) ? $this->rating : '';
    }

    public function setSection(string $section): void
    {
        $this->section = in_array($section, ['yanit', 'iste'], true) ? $section : 'yanit';
    }

    public function setRating(string $rating): void
    {
        $this->rating = isset(GbpDailyWorkspace::RATING_FILTERS[$rating]) ? $rating : '';
    }

    public function draftAll(ReviewDesk $desk): void
    {
        abort_unless($this->canWrite(), 403);
        $queued = $desk->draftAll($this->reviews($desk));
        $this->say($queued > 0 ? $queued.' yorum için yanıt taslağı yazılıyor; hazır olanlar listede görünür.' : 'Taslağı olmayan yorum yok.');
    }

    public function send(int $reviewId, ReviewDesk $desk): void
    {
        abort_unless($this->canWrite(), 403);
        $review = collect($this->reviews($desk))->firstWhere('id', $reviewId) ?? abort(404);
        $text = trim((string) ($this->replies['r'.$reviewId] ?? $review['draft'] ?? ''));
        try {
            $desk->send(auth()->user(), $reviewId, $text);
            unset($this->replies['r'.$reviewId]);
            $this->say('Yanıt Google’a gönderiliyor.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function sendDrafts(ReviewDesk $desk): void
    {
        abort_unless($this->canWrite(), 403);
        $reviews = array_map(function (array $review): array {
            $edited = $this->replies['r'.$review['id']] ?? null;

            return $edited !== null && trim($edited) !== '' ? ['draft' => $edited] + $review : $review;
        }, $this->reviews($desk));
        $result = $desk->sendDrafts(auth()->user(), $reviews);
        $this->replies = [];
        $this->say($result['sent'].' yanıt Google’a gönderiliyor.'.($result['failed'] > 0 ? ' '.$result['failed'].' yanıt gönderilemedi (metni kontrol edin).' : ''), $result['sent'] === 0 && $result['failed'] > 0 ? 'error' : 'info');
    }

    /** @return list<array<string, mixed>> */
    private function reviews(ReviewDesk $desk): array
    {
        $resources = app(GbpDailyWorkspace::class)->resourceIds($this->scopedLocations()->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return $desk->unanswered($resources, $this->rating);
    }

    public function render(ReviewDesk $desk, GbpDailyWorkspace $daily): View
    {
        $locations = $this->scopedLocations();
        $resources = $daily->resourceIds($locations->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $stats = $desk->stats($resources);
        $reviews = $this->section === 'yanit' ? $desk->unanswered($resources, $this->rating) : [];
        foreach ($reviews as $review) {
            if ($review['draft'] !== null && ! array_key_exists('r'.$review['id'], $this->replies)) {
                $this->replies['r'.$review['id']] = (string) $review['draft'];
            }
        }
        $kits = [];
        if ($this->section === 'iste') {
            foreach ($locations as $location) {
                $kits[$location->id] = $desk->kit($location);
            }
        }

        return view('livewire.operator.gbp.desk.reviews', [
            'groups' => $locations->groupBy(fn ($l): string => (string) $l->brand?->name),
            'names' => $locations->mapWithKeys(fn ($l): array => [$l->id => GbpDesk::shortName((string) $l->name)])->all(),
            'stats' => $stats,
            'reviews' => $reviews,
            'kits' => $kits,
            'totals' => [
                'unanswered' => array_sum(array_column($stats, 'unanswered')),
                'late' => array_sum(array_column($stats, 'late')),
                'recent' => array_sum(array_column($stats, 'recent')),
            ],
            'drafted' => count(array_filter($reviews, fn (array $r): bool => $r['draft'] !== null && ($r['action'] === null || ! in_array($r['action']['status'], ['queued', 'running', 'succeeded'], true)))),
            'undrafted' => count(array_filter($reviews, fn (array $r): bool => $r['draft'] === null && $r['draft_state'] !== 'running' && $r['action'] === null)),
            'drafting' => collect($reviews)->contains(fn (array $r): bool => $r['draft_state'] === 'running'),
            'canWrite' => $this->canWrite(),
            'brandOptions' => $this->brandOptions(),
        ]);
    }
}

<?php

namespace App\Livewire\Operator\Library;

use App\Models\QueryReview;
use App\Models\QueryReviewItem;
use App\Models\User;
use App\Services\Queries\QueryRescanner;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Filtre taraması onayı: (1) Silinecek sorgular, (2) Hizmeti değişecek sorgular — every line checked by default;
 * "Onayla" applies only the checked lines. Selection = a mode per list (all / none) + the lines flipped against it.
 */
#[Layout('operator.layouts.app')]
#[Title('Filtre taraması')]
final class QueryReviewPage extends Component
{
    use WithPagination;

    #[Locked]
    public int $reviewId;

    /** @var array{delete: bool, service: bool} true = every line checked except the flipped ones */
    public array $all = ['delete' => true, 'service' => true];

    /** @var list<int> item ids flipped against their list's mode */
    public array $flipped = [];

    public string $message = '';

    public function mount(QueryReview $review): void
    {
        $this->reviewId = (int) $review->id;
    }

    public function toggle(int $itemId): void
    {
        $this->flipped = in_array($itemId, $this->flipped, true)
            ? array_values(array_diff($this->flipped, [$itemId]))
            : [...$this->flipped, $itemId];
    }

    public function setAll(string $kind, bool $checked): void
    {
        if (array_key_exists($kind, $this->all)) {
            $this->all[$kind] = $checked;
            $ids = QueryReviewItem::query()->where('query_review_id', $this->reviewId)->where('kind', $kind)->whereIn('id', $this->flipped ?: [0])->pluck('id')->all();
            $this->flipped = array_values(array_diff($this->flipped, $ids));
        }
    }

    public function approve(QueryRescanner $rescanner): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);
        $review = QueryReview::query()->findOrFail($this->reviewId);
        if ($review->status !== QueryReview::READY) {
            return;
        }
        $ids = [];
        foreach ($this->all as $kind => $all) {
            $query = QueryReviewItem::query()->where('query_review_id', $review->id)->where('kind', $kind);
            $ids = [...$ids, ...($all
                ? $query->whereNotIn('id', $this->flipped ?: [0])->pluck('id')->all()
                : $query->whereIn('id', $this->flipped ?: [0])->pluck('id')->all())];
        }
        $done = $rescanner->apply($review, array_map('intval', $ids));
        $this->message = sprintf('%d sorgu silindi · %d sorgunun hizmeti değişti.', $done['deleted'], $done['changed']);
    }

    public function render(): View
    {
        $review = QueryReview::query()->findOrFail($this->reviewId);
        $list = fn (string $kind, string $page) => QueryReviewItem::query()->where('query_review_id', $review->id)->where('kind', $kind)
            ->with(['searchQuery:id,text,impressions', 'fromService.primaryName', 'toService.primaryName'])
            ->orderBy('id')->paginate(100, pageName: $page);

        return view('livewire.operator.library.query-review-page', [
            'review' => $review,
            'deletions' => $list(QueryReviewItem::DELETE, 'silinecek'),
            'changes' => $list(QueryReviewItem::SERVICE, 'degisecek'),
        ]);
    }
}

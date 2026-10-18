<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Brand;
use App\Models\BrandEpicrisis;
use App\Models\BrandEpicrisisItem;
use App\Services\Epicrisis\Epicrisis;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Bugün › Markalara dair sorularım (marka epikrizi): the open questions of every operational brand in one box (a
 * missing required field, two sources disagreeing, an operator line past its date), answered in place, and how full
 * the portfolio's records are. Hidden when nothing is asked.
 */
final class EpicrisisQuestions extends Component
{
    public const int SHOWN = 6;

    /** @var array<int, string> */
    public array $answers = [];

    public bool $all = false;

    public function placeholder(): string
    {
        return '<div></div>';
    }

    public function answer(int $questionId, ?string $value = null): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $question = BrandEpicrisisItem::query()->where('layer', BrandEpicrisisItem::QUESTION)->where('status', BrandEpicrisisItem::OPEN)->findOrFail($questionId);
        $value = trim((string) ($value ?? $this->answers[$questionId] ?? ''));
        if ($value === '') {
            $this->addError('answers.'.$questionId, 'Cevap boş.');

            return;
        }
        app(Epicrisis::class)->answer($question, $value, auth()->user());
        unset($this->answers[$questionId]);
    }

    public function dismiss(int $questionId): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        app(Epicrisis::class)->dismiss(BrandEpicrisisItem::query()->where('layer', BrandEpicrisisItem::QUESTION)->findOrFail($questionId), auth()->user());
    }

    public function render(): View
    {
        $brands = Brand::query()->operational()->pluck('name', 'id');
        $questions = BrandEpicrisisItem::query()->where('layer', BrandEpicrisisItem::QUESTION)->where('status', BrandEpicrisisItem::OPEN)
            ->whereIn('brand_id', $brands->keys()->all() ?: [0])->orderBy('brand_id')->orderBy('id')->get()
            ->sortBy(fn (BrandEpicrisisItem $q): array => [data_get($q->detail, 'kind') === 'conflict' ? 0 : 1, $q->brand_id, $q->id])->values();
        $heads = BrandEpicrisis::query()->whereIn('brand_id', $brands->keys()->all() ?: [0])->get(['brand_id', 'fill_percent']);

        return view('livewire.operator.portfolio.epicrisis-questions', [
            'questions' => $this->all ? $questions : $questions->take(self::SHOWN),
            'total' => $questions->count(),
            'brands' => $brands,
            'fill' => $heads->isNotEmpty() ? (int) round($heads->avg('fill_percent')) : null,
            'thin' => $heads->where('fill_percent', '<', 60)->count(),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }
}

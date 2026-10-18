<?php

namespace App\Livewire\Operator\Portfolio;

use App\Jobs\Brand\RunBrandEpicrisisJob;
use App\Models\Brand;
use App\Models\BrandEpicrisis;
use App\Models\BrandEpicrisisItem;
use App\Services\Epicrisis\Epicrisis;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Marka › Epikriz (yakup, 2026-10-16): the brand's record every AI step reads, by layer — facts with their source,
 * date, confidence and use, the synthesis the AI wrote from them, evidence, lessons — and the open questions. The
 * operator answers a question, adds or corrects a line ("Düzelt"), confirms it ("Doğru": locked) or sets it aside
 * ("Yanlış": never used again). "Şimdi güncelle" queues the pass on the background queue.
 */
final class BrandEpicrisisTab extends Component
{
    #[Locked]
    public int $brandId;

    /** @var array<int, string> question id → typed answer */
    public array $answers = [];

    public ?int $editing = null;

    public string $editText = '';

    public string $newField = '';

    public string $newScope = '';

    public string $newValue = '';

    public ?string $notice = null;

    public function mount(int $brandId): void
    {
        $this->brandId = $brandId;
    }

    public function placeholder(): string
    {
        return '<div></div>';
    }

    public function answer(int $questionId, ?string $value = null): void
    {
        $this->authorizeAdmin();
        $question = $this->item($questionId, BrandEpicrisisItem::QUESTION);
        $value = trim((string) ($value ?? $this->answers[$questionId] ?? ''));
        if ($value === '') {
            $this->addError('answers.'.$questionId, 'Cevap boş.');

            return;
        }
        app(Epicrisis::class)->answer($question, $value, auth()->user());
        unset($this->answers[$questionId]);
        $this->notice = 'Cevap kaydedildi; bundan sonraki tüm AI işleri bunu kullanır.';
    }

    public function dismiss(int $questionId): void
    {
        $this->authorizeAdmin();
        app(Epicrisis::class)->dismiss($this->item($questionId, BrandEpicrisisItem::QUESTION), auth()->user());
    }

    public function edit(int $itemId): void
    {
        $this->authorizeAdmin();
        $item = $this->item($itemId);
        $this->editing = $item->id;
        $this->editText = (string) $item->value;
    }

    public function saveEdit(): void
    {
        $this->authorizeAdmin();
        if ($this->editing === null) {
            return;
        }
        $item = $this->item($this->editing);
        try {
            app(Epicrisis::class)->record($this->brand(), (string) $item->field, $this->editText, $item->scope, 'operator', auth()->id(), $item);
            $this->notice = 'Düzeltildi ve kilitlendi.';
        } catch (InvalidArgumentException $exception) {
            $this->addError('editText', $exception->getMessage());

            return;
        }
        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
        $this->editText = '';
    }

    /** "Doğru": the line becomes the operator's own (verified, locked). */
    public function confirm(int $itemId): void
    {
        $this->authorizeAdmin();
        $item = $this->item($itemId);
        app(Epicrisis::class)->record($this->brand(), (string) $item->field, (string) $item->value, $item->scope, 'operator', auth()->id(), $item);
    }

    /** "Yanlış": no AI work uses it again. */
    public function reject(int $itemId): void
    {
        $this->authorizeAdmin();
        app(Epicrisis::class)->reject($this->item($itemId));
    }

    public function add(): void
    {
        $this->authorizeAdmin();
        $this->validate(['newField' => 'required|string', 'newValue' => 'required|string|max:2000', 'newScope' => 'nullable|string|max:160']);
        try {
            app(Epicrisis::class)->record($this->brand(), $this->newField, $this->newValue, $this->newScope !== '' ? $this->newScope : null, 'operator', auth()->id());
        } catch (InvalidArgumentException $exception) {
            $this->addError('newField', $exception->getMessage());

            return;
        }
        $this->reset('newField', 'newScope', 'newValue');
        $this->notice = 'Eklendi; bundan sonraki tüm AI işleri bunu kullanır.';
    }

    /** Queues the pass (rules now, the site reading and synthesis again). */
    public function rebuild(): void
    {
        $this->authorizeAdmin();
        RunBrandEpicrisisJob::dispatch($this->brandId, true);
        Cache::put(self::queuedKey($this->brandId), now()->toIso8601String(), now()->addHours(2));
        $this->notice = 'Güncelleme sıraya alındı: kurallar hemen, site okuması ve sentez AI kuyruğunda.';
    }

    public static function queuedKey(int $brandId): string
    {
        return 'epicrisis-queued:'.$brandId;
    }

    public function render(): View
    {
        $brand = $this->brand();
        $head = BrandEpicrisis::query()->where('brand_id', $brand->id)->first();
        $items = BrandEpicrisisItem::query()->where('brand_id', $brand->id)->whereNotIn('status', [BrandEpicrisisItem::DROPPED, BrandEpicrisisItem::DISMISSED, BrandEpicrisisItem::ANSWERED])
            ->orderBy('id')->get();
        $order = array_flip(array_keys((array) config('moxdop-epicrisis.fields')));
        $facts = $items->where('layer', BrandEpicrisisItem::FACT)->sortBy(fn (BrandEpicrisisItem $i): array => [$order[$i->field] ?? 99, (string) $i->scope, $i->id])
            ->groupBy('field');
        $synthesisOrder = array_flip(array_keys((array) config('moxdop-epicrisis.synthesis')));
        $required = Epicrisis::required($brand);

        return view('livewire.operator.portfolio.brand-epicrisis-tab', [
            'head' => $head,
            'questions' => $items->where('layer', BrandEpicrisisItem::QUESTION)->where('status', BrandEpicrisisItem::OPEN)->values(),
            'facts' => $facts,
            'missing' => collect($required)->reject(fn (string $f): bool => $facts->has($f))->map(fn (string $f): string => (string) config('moxdop-epicrisis.fields.'.$f.'.label'))->values(),
            'synthesis' => $items->where('layer', BrandEpicrisisItem::SYNTHESIS)->sortBy(fn (BrandEpicrisisItem $i): array => [$synthesisOrder[$i->field] ?? 99, $i->id])->values(),
            'evidence' => $items->where('layer', BrandEpicrisisItem::EVIDENCE)->sortBy(fn (BrandEpicrisisItem $i): array => [$i->field, (int) data_get($i->detail, 'rank', 9), $i->id])->groupBy('field'),
            'lessons' => $items->where('layer', BrandEpicrisisItem::LESSON)->values(),
            'fieldOptions' => [...collect((array) config('moxdop-epicrisis.fields'))->map(fn (array $f): string => $f['label'])->all(),
                ...collect((array) config('moxdop-epicrisis.synthesis'))->map(fn (array $f): string => 'Sentez · '.$f['label'])->all()],
            'queuedAt' => Cache::get(self::queuedKey($brand->id)),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }

    private function item(int $id, ?string $layer = null): BrandEpicrisisItem
    {
        return BrandEpicrisisItem::query()->where('brand_id', $this->brandId)->when($layer !== null, fn ($q) => $q->where('layer', $layer))->findOrFail($id);
    }

    private function brand(): Brand
    {
        return Brand::query()->findOrFail($this->brandId);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $this->notice = null;
    }
}

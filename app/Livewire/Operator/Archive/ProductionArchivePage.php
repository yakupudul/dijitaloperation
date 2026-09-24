<?php

namespace App\Livewire\Operator\Archive;

use App\Models\AiProduction;
use App\Models\Brand;
use App\Services\Archive\ProductionArchive;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Üretim Arşivi: every AI output version (ad copy, creatives, profile texts, SEO briefs, WhatsApp replies,
 * brand setup proposals), with used / published / discarded marks and 👍 / 👎. Nothing is deleted here.
 */
#[Layout('operator.layouts.app')]
#[Title('Üretim Arşivi')]
final class ProductionArchivePage extends Component
{
    use WithPagination;

    #[Url]
    public string $kind = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $brand = '';

    #[Url]
    public string $subject = '';

    public ?int $openId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['kind', 'status', 'brand', 'subject'], true)) {
            $this->resetPage();
        }
    }

    public function toggle(int $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
    }

    public function mark(int $id, string $status, ProductionArchive $archive): void
    {
        $archive->mark(AiProduction::query()->findOrFail($id), $status, auth()->user());
    }

    public function rate(int $id, int $rating, ProductionArchive $archive): void
    {
        $archive->rate(AiProduction::query()->findOrFail($id), $rating);
    }

    public function render(): View
    {
        $query = AiProduction::query()->with('brand:id,name')
            ->when($this->kind !== '', fn ($q) => $q->where('kind', $this->kind))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->brand !== '', fn ($q) => $q->where('brand_id', (int) $this->brand))
            ->when(str_contains($this->subject, ':'), function ($q): void {
                [$type, $id] = explode(':', $this->subject, 2);
                $q->where('subject_type', $type)->where('subject_id', (int) $id);
            })
            ->orderByDesc('created_at')->orderByDesc('id');

        return view('livewire.operator.archive.production-archive', [
            'productions' => $query->paginate(25),
            'kinds' => ProductionArchive::KIND_LABELS,
            'brands' => Brand::query()->whereIn('id', AiProduction::query()->whereNotNull('brand_id')->distinct()->pluck('brand_id'))->orderBy('name')->pluck('name', 'id'),
            'statusLabels' => ['new' => 'Yeni', 'used' => 'Kullandım', 'published' => 'Yayınlandı', 'discarded' => 'Kullanılmadı'],
        ]);
    }

    /**
     * Plain text of an output for copy and preview: lists become lines, nested sections get their key.
     *
     * @param  array<string, mixed>  $content
     */
    public static function text(array $content): string
    {
        $skip = ['provider', 'model', 'prompt_version', 'created_at', 'source', 'error'];
        $lines = [];
        foreach ($content as $key => $value) {
            if (in_array((string) $key, $skip, true) || $value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value)) {
                $lines[] = $key.':';
                array_walk_recursive($value, function ($leaf) use (&$lines): void {
                    if (is_scalar($leaf) && (string) $leaf !== '') {
                        $lines[] = '• '.$leaf;
                    }
                });
            } else {
                $lines[] = $key.': '.$value;
            }
        }

        return implode("\n", $lines);
    }
}

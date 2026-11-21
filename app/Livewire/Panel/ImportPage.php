<?php

namespace App\Livewire\Panel;

use App\Panel\Models\PanelImport;
use App\Panel\Services\PanelSheets;
use App\Panel\Support\PanelPage;
use App\Panel\Support\PanelSources;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/** Excel'den yükle: a brand brings its existing people or its call list in one go, sees the result first, can undo. */
#[Layout('panel.layouts.app')]
#[Title('Excel\'den yükle')]
final class ImportPage extends Component
{
    use PanelPage;
    use WithFileUploads;

    public string $kind = PanelImport::LEADS;

    public $upload = null;

    public ?int $importId = null;

    /** @var array<int, string> */
    public array $map = [];

    public string $source = 'other';

    protected function permission(): string
    {
        return 'import.leads';
    }

    private function import(): ?PanelImport
    {
        return $this->importId ? PanelImport::query()->whereIn('kind', [PanelImport::LEADS, PanelImport::CALLS])->where('status', 'uploaded')->find($this->importId) : null;
    }

    public function updatedUpload(PanelSheets $sheets): void
    {
        $this->validate([
            'kind' => ['required', Rule::in([PanelImport::LEADS, PanelImport::CALLS])],
            'upload' => ['required', 'file', 'max:'.(int) config('panel.max_file_kb'), 'extensions:xlsx,csv'],
        ], ['upload.extensions' => 'Excel (.xlsx) ya da CSV dosyası seçin.', 'upload.max' => 'Dosya 20 MB\'tan büyük olamaz.'], ['upload' => 'dosya']);
        $import = $sheets->keep((int) $this->panel()->brandId(), (int) $this->panelUser()->id, $this->kind, $this->upload->getRealPath(), (string) $this->upload->getClientOriginalName());
        $this->upload = null;
        try {
            $rows = $sheets->rows($import);
        } catch (Throwable $exception) {
            $import->delete();
            $this->addError('upload', $exception instanceof \RuntimeException ? $exception->getMessage() : 'Dosya okunamadı. Excel\'de “Farklı kaydet” ile .xlsx olarak kaydedip yeniden deneyin.');

            return;
        }
        $this->importId = $import->id;
        $this->map = $sheets->guess($rows[0]);
    }

    public function cancel(): void
    {
        $this->import()?->delete();
        $this->importId = null;
        $this->map = [];
    }

    public function run(PanelSheets $sheets): void
    {
        $import = $this->import();
        abort_if($import === null, 404);
        $this->validate(['source' => ['required', Rule::in(array_keys(PanelSources::labels()))]]);
        if (! in_array('phone', $this->map, true)) {
            $this->addError('map', 'Hangi sütunun telefon olduğunu seçin; kişileri telefon numarası ayırır.');

            return;
        }
        $done = $sheets->apply($import, $this->map, $this->source, (int) $this->panelUser()->id);
        $this->importId = null;
        $this->map = [];
        $this->notify(($done->stats['new'] ?? 0).' yeni kişi eklendi, '.($done->stats['updated'] ?? 0).' kayıt güncellendi.');
    }

    public function undo(int $id, PanelSheets $sheets): void
    {
        $import = PanelImport::query()->whereIn('kind', [PanelImport::LEADS, PanelImport::CALLS])->find($id);
        abort_if($import === null, 404);
        abort_unless($sheets->canUndo($import), 422);
        $sheets->undo($import);
        $this->notify('Bu dosyanın eklediği kayıtlar geri alındı.');
    }

    public function render(PanelSheets $sheets): View
    {
        $import = $this->import();
        $rows = [];
        $preview = null;
        if ($import !== null) {
            try {
                $rows = $sheets->rows($import);
                $preview = $sheets->preview((int) $this->panel()->brandId(), $rows, $this->map);
            } catch (Throwable) {
                $import = null;
                $this->importId = null;
            }
        }

        return view('livewire.panel.import', [
            'import' => $import,
            'head' => $rows[0] ?? [],
            'samples' => array_slice($rows, 1, 3),
            'count' => max(0, count($rows) - 1),
            'preview' => $preview,
            'history' => PanelImport::query()->with('user:id,name')->whereIn('kind', [PanelImport::LEADS, PanelImport::CALLS])->whereIn('status', ['completed', 'undone'])->latest('id')->limit(10)->get(),
            'sheets' => $sheets,
        ]);
    }
}

<?php

namespace App\Livewire\Demo\Files;

use App\Models\OperatorFile;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('operator.layouts.app')]
#[Title('Files')]
class FilesIndex extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(as: 'scope', history: true)]
    public string $scope = '';

    #[Url(as: 'brand', history: true)]
    public string $brand = '';

    #[Url(as: 'customer', history: true)]
    public string $customer = '';

    #[Url(as: 'asset', history: true)]
    public string $asset = '';

    public mixed $upload = null;

    public string $uploadScope = 'personal';

    public string $uploadDescription = '';

    public ?int $renamingId = null;

    public string $renameName = '';

    public ?int $confirmDeleteId = null;

    public function uploadFile(): void
    {
        $this->authorize('create', OperatorFile::class);

        $this->validate([
            'upload' => [
                'required',
                'file',
                'max:10240',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $original = (string) $value->getClientOriginalName();
                    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
                    if (in_array($ext, OperatorFile::BLOCKED_EXTENSIONS, true)) {
                        $fail(__('operator.files.rejected_type'));

                        return;
                    }
                    if (! in_array($ext, OperatorFile::ALLOWED_EXTENSIONS, true)) {
                        $fail(__('operator.files.rejected_type'));
                    }
                },
            ],
            'uploadScope' => ['required', Rule::in(OperatorFile::SCOPES)],
            'uploadDescription' => ['nullable', 'string', 'max:500'],
        ]);

        $file = $this->upload;
        $original = (string) $file->getClientOriginalName();
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $storedName = Str::uuid()->toString().($ext !== '' ? '.'.$ext : '');
        $path = $file->storeAs('operator-files/'.auth()->id(), $storedName, 'local');

        OperatorFile::query()->create([
            'user_id' => auth()->id(),
            'disk' => 'local',
            'path' => $path,
            'original_name' => $original,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
            'scope_type' => $this->uploadScope,
            'description' => $this->uploadDescription !== '' ? $this->uploadDescription : null,
            'tags' => [],
        ]);

        $this->upload = null;
        $this->uploadDescription = '';
        DemoState::flash(__('operator.files.uploaded'));
    }

    public function startRename(int $id): void
    {
        $file = OperatorFile::query()->findOrFail($id);
        $this->authorize('update', $file);
        $this->renamingId = $id;
        $this->renameName = $file->original_name;
        $this->confirmDeleteId = null;
    }

    public function saveRename(): void
    {
        $file = OperatorFile::query()->findOrFail($this->renamingId);
        $this->authorize('update', $file);

        $this->validate([
            'renameName' => ['required', 'string', 'min:1', 'max:255'],
        ]);

        $name = trim($this->renameName);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, OperatorFile::BLOCKED_EXTENSIONS, true)) {
            $this->addError('renameName', __('operator.files.rejected_type'));

            return;
        }

        $file->original_name = $name;
        $file->save();

        $this->renamingId = null;
        $this->renameName = '';
        DemoState::flash(__('operator.files.renamed'));
    }

    public function cancelRename(): void
    {
        $this->renamingId = null;
        $this->renameName = '';
    }

    public function askDelete(int $id): void
    {
        $file = OperatorFile::query()->findOrFail($id);
        $this->authorize('delete', $file);
        $this->confirmDeleteId = $id;
        $this->renamingId = null;
    }

    public function cancelDelete(): void
    {
        $this->confirmDeleteId = null;
    }

    public function deleteFile(): void
    {
        $file = OperatorFile::query()->findOrFail($this->confirmDeleteId);
        $this->authorize('delete', $file);

        Storage::disk($file->disk)->delete($file->path);
        $file->delete();

        $this->confirmDeleteId = null;
        DemoState::flash(__('operator.files.deleted'));
    }

    public function render(): View
    {
        $this->authorize('viewAny', OperatorFile::class);

        $query = OperatorFile::query()->with('user')->latest();

        if (! auth()->user()?->hasRole(Roles::ADMIN)) {
            $query->where('user_id', auth()->id());
        }

        if ($this->scope !== '' && in_array($this->scope, OperatorFile::SCOPES, true)) {
            $query->where('scope_type', $this->scope);
        }

        if (trim($this->brand) !== '') {
            $query->where('brand_id', trim($this->brand));
        }

        if (trim($this->customer) !== '') {
            $query->where('customer_id', trim($this->customer));
        }

        if (trim($this->asset) !== '') {
            $query->where('digital_asset_id', trim($this->asset));
        }

        if (trim($this->search) !== '') {
            $needle = '%'.mb_strtolower(trim($this->search)).'%';
            $query->where(function ($q) use ($needle): void {
                $q->whereRaw('LOWER(original_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$needle]);
            });
        }

        return view('livewire.demo.files.files-index', [
            'files' => $query->paginate(20),
            'flash' => DemoState::pullFlash(),
            'scopes' => OperatorFile::SCOPES,
        ]);
    }
}

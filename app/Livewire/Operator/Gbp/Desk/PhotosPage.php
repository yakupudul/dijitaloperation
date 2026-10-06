<?php

namespace App\Livewire\Operator\Gbp\Desk;

use App\Models\ExternalWriteAction;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\Desk\PhotoPlan;
use App\Services\Gbp\GbpDailyWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * İşletme profilleri › Fotoğraflar (`/gbp/fotograflar`, ADR-079): per profile the photo count, the age of the newest
 * photo and logo / cover. A profile opens the brand's own site photos it does not have yet (service images first,
 * photos another branch already shows marked) and an upload box for branch photos; the Admin picks and sends them. One
 * click per brand gives every profile without a photo in 30 days its best unused site photo.
 */
#[Layout('operator.layouts.app')]
#[Title('Fotoğraflar')]
final class PhotosPage extends Component
{
    use DeskScope;
    use WithFileUploads;

    #[Url(as: 'isletme')]
    public ?int $open = null;

    #[Url(as: 'eski')]
    public bool $staleOnly = false;

    /** @var array<string, bool> candidate index => picked */
    public array $picked = [];

    /** @var array<string, string> candidate index => category */
    public array $categories = [];

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    public string $photoCategory = 'EXTERIOR';

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active, 403);
    }

    public function toggle(int $assetId): void
    {
        $this->open = $this->open === $assetId ? null : $assetId;
        $this->picked = [];
        $this->categories = [];
        $this->photo = null;
    }

    public function send(PhotoPlan $plan): void
    {
        abort_unless($this->canWrite() && $this->open !== null, 403);
        $location = $this->location($this->open);
        $candidates = $plan->candidates($location);
        $photos = [];
        foreach (array_keys(array_filter($this->picked)) as $index) {
            $candidate = $candidates[(int) $index] ?? null;
            if ($candidate !== null) {
                $photos[] = ['url' => $candidate['url'], 'title' => $candidate['title'], 'category' => $this->categories[$index] ?? $candidate['category']];
            }
        }
        try {
            $sent = $plan->send(auth()->user(), $location, $photos);
            $this->picked = [];
            $this->categories = [];
            $this->say($sent > 0 ? $sent.' fotoğraf Google’a gönderiliyor.' : 'Seçilen fotoğraflar bu profilde zaten var.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function uploadPhoto(PhotoPlan $plan): void
    {
        abort_unless($this->canWrite() && $this->open !== null, 403);
        $this->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5000'], 'photoCategory' => ['required', 'in:'.implode(',', array_keys(PhotoPlan::CATEGORIES))]],
            ['photo.required' => 'Fotoğraf seçin.', 'photo.image' => 'Dosya fotoğraf olmalı.', 'photo.mimes' => 'JPG ya da PNG seçin.', 'photo.max' => 'Fotoğraf en çok 5 MB olabilir.']);
        try {
            $plan->upload(auth()->user(), $this->location($this->open), $this->photo, $this->photoCategory);
            $this->photo = null;
            $this->say('Fotoğraf yüklendi ve Google’a gönderiliyor.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function sendMonthly(int $brandId, PhotoPlan $plan): void
    {
        abort_unless($this->canWrite(), 403);
        try {
            $result = $plan->sendMonthly(auth()->user(), $brandId);
            $this->say($result['sent'] > 0
                ? $result['sent'].' işletmeye birer yeni fotoğraf gönderiliyor.'.($result['skipped'] > 0 ? ' '.$result['skipped'].' işletme için sitede kullanılmamış uygun fotoğraf yok; şube fotoğrafı yükleyin.' : '')
                : ($result['skipped'] > 0 ? 'Sitede kullanılmamış uygun fotoğraf yok; şube fotoğrafı yükleyin.' : 'Bu markanın tüm profillerinde son 30 günde fotoğraf var.'), $result['sent'] === 0 && $result['skipped'] > 0 ? 'error' : 'info');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function undo(int $actionId, ExternalWriteService $writes): void
    {
        $action = ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_MEDIA_UPLOAD)->findOrFail($actionId);
        try {
            $writes->requestUndo(auth()->user(), $action);
            $this->say('Fotoğraf profilden kaldırılıyor.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function render(PhotoPlan $plan, GbpDailyWorkspace $daily): View
    {
        $locations = $this->scopedLocations();
        $resources = $daily->resourceIds($locations->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $status = $plan->status($resources);
        $staleCount = count(array_filter($status, fn (array $s): bool => $s['stale']));
        if ($this->staleOnly) {
            $locations = $locations->filter(fn ($l): bool => $status[$l->id]['stale'] ?? true);
        }
        $openLocation = $this->open !== null ? $locations->firstWhere('id', $this->open) : null;
        $candidates = $openLocation !== null ? $plan->candidates($openLocation) : [];
        foreach ($candidates as $index => $candidate) {
            $this->categories[(string) $index] ??= $candidate['category'];
        }

        return view('livewire.operator.gbp.desk.photos', [
            'groups' => $locations->groupBy(fn ($l): string => (string) $l->brand?->name),
            'status' => $status,
            'resources' => $resources,
            'staleCount' => $staleCount,
            'candidates' => $candidates,
            'history' => $openLocation !== null ? $plan->history($openLocation) : collect(),
            'labels' => PhotoPlan::CATEGORIES,
            'canWrite' => $this->canWrite(),
            'brandOptions' => $this->brandOptions(),
            'pickedCount' => count(array_filter($this->picked)),
        ]);
    }
}

<?php

namespace App\Livewire\Operator\Website;

use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use MoxDop\Website\Discovery\DiscoveryCandidateReviewService;
use MoxDop\Website\Workspace\WebsiteWorkspaceData;
use Throwable;

#[Layout('operator.layouts.app')]
#[Title('Public Discovery')]
class PublicDiscoveryPage extends Component
{
    public int $assetId;

    public string $statusMessage = '';

    public string $statusTone = 'info';

    public function mount(string $assetId): void
    {
        abort_unless(ctype_digit($assetId), 404);

        $asset = $this->asset((int) $assetId);
        abort_unless($asset->type === 'website', 404);

        $this->assetId = $asset->id;
    }

    public function runDiscovery(AsyncOperationService $operations): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $result = $operations->queuePublicDiscovery($this->asset(), $actor);
            $this->statusTone = ($result['ok'] ?? false) ? 'success' : 'info';
            $this->statusMessage = (string) ($result['message'] ?? 'Public discovery queued.');
        } catch (Throwable $e) {
            report($e);
            $this->statusTone = 'error';
            $this->statusMessage = 'Public discovery could not be queued: '.$e->getMessage();
        }
    }

    public function acceptCandidate(int $candidateId, DiscoveryCandidateReviewService $reviews): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $candidate = $this->candidate($candidateId);

        try {
            $reviews->accept($candidate, $actor);
            $this->statusTone = 'success';
            $this->statusMessage = 'Candidate accepted into canonical brand context.';
        } catch (ValidationException $e) {
            $this->statusTone = 'error';
            $this->statusMessage = collect($e->errors())->flatten()->first() ?? 'Candidate could not be accepted.';
        } catch (Throwable $e) {
            report($e);
            $this->statusTone = 'error';
            $this->statusMessage = 'Candidate could not be accepted: '.$e->getMessage();
        }
    }

    public function ignoreCandidate(int $candidateId, DiscoveryCandidateReviewService $reviews): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $reviews->ignore($this->candidate($candidateId), $actor);
            $this->statusTone = 'success';
            $this->statusMessage = 'Candidate ignored.';
        } catch (Throwable $e) {
            report($e);
            $this->statusTone = 'error';
            $this->statusMessage = 'Candidate could not be ignored: '.$e->getMessage();
        }
    }

    public function render(WebsiteWorkspaceData $workspace): View
    {
        $asset = $this->asset();

        return view('livewire.operator.website.public-discovery', [
            'asset' => $asset,
            'brand' => $asset->brand,
            'discovery' => $workspace->discovery($asset),
        ]);
    }

    private function asset(?int $id = null): DigitalAsset
    {
        return DigitalAsset::query()
            ->with('brand')
            ->whereKey($id ?? $this->assetId)
            ->where('type', 'website')
            ->firstOrFail();
    }

    private function candidate(int $candidateId): DiscoveryCandidate
    {
        return DiscoveryCandidate::query()
            ->whereKey($candidateId)
            ->where('digital_asset_id', $this->assetId)
            ->firstOrFail();
    }
}

<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers;

use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use MoxDop\Website\Discovery\DiscoveryCandidateReviewService;
use MoxDop\Website\Workspace\WebsiteWorkspaceData;

class WebsiteDiscoveryRelationManager extends RelationManager
{
    protected static string $relationship = 'findings';

    protected static ?string $title = 'Discovery';

    protected static bool $isLazy = false;

    public ?int $editingCandidateId = null;

    public string $editingValue = '';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof DigitalAsset && $ownerRecord->type === 'website';
    }

    public function content(Schema $schema): Schema
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();

        return $schema->components([
            View::make('website::workspace.discovery')
                ->viewData([
                    'data' => app(WebsiteWorkspaceData::class)->discovery($asset),
                    'editingCandidateId' => $this->editingCandidateId,
                    'editingValue' => $this->editingValue,
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([])->paginated(false);
    }

    public function acceptCandidate(int $candidateId): void
    {
        $this->review($candidateId, editedValue: null);
    }

    public function beginEditCandidate(int $candidateId): void
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();
        $candidate = DiscoveryCandidate::query()
            ->where('digital_asset_id', $asset->id)
            ->whereKey($candidateId)
            ->first();

        if (! $candidate instanceof DiscoveryCandidate) {
            return;
        }

        $this->editingCandidateId = $candidate->id;
        $this->editingValue = (string) $candidate->proposed_value;
    }

    public function cancelEditCandidate(): void
    {
        $this->editingCandidateId = null;
        $this->editingValue = '';
    }

    public function saveEditAcceptCandidate(int $candidateId): void
    {
        $this->review($candidateId, editedValue: $this->editingValue);
        $this->cancelEditCandidate();
    }

    public function ignoreCandidate(int $candidateId): void
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();
        $candidate = DiscoveryCandidate::query()
            ->where('digital_asset_id', $asset->id)
            ->whereKey($candidateId)
            ->first();

        if (! $candidate instanceof DiscoveryCandidate) {
            Notification::make()->title('Candidate not found')->warning()->send();

            return;
        }

        $actor = auth()->user();
        if ($actor === null) {
            Notification::make()->title('Sign in required')->warning()->send();

            return;
        }

        app(DiscoveryCandidateReviewService::class)->ignore($candidate, $actor);

        Notification::make()
            ->title('Candidate ignored')
            ->body('Source Evidence was preserved. Brand Context was not changed.')
            ->success()
            ->send();
    }

    private function review(int $candidateId, ?string $editedValue): void
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();
        $candidate = DiscoveryCandidate::query()
            ->where('digital_asset_id', $asset->id)
            ->whereKey($candidateId)
            ->first();

        if (! $candidate instanceof DiscoveryCandidate) {
            Notification::make()->title('Candidate not found')->warning()->send();

            return;
        }

        $actor = auth()->user();
        if ($actor === null) {
            Notification::make()->title('Sign in required')->warning()->send();

            return;
        }

        try {
            app(DiscoveryCandidateReviewService::class)->accept($candidate, $actor, $editedValue);
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title('Could not accept candidate')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title($editedValue !== null ? 'Candidate accepted with edits' : 'Candidate accepted')
            ->body('Canonical Brand Context updated through human review.')
            ->success()
            ->send();
    }
}

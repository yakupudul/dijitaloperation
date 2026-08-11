<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers;

use App\Models\DigitalAsset;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use MoxDop\MetaAds\Ai\MetaAdsAiRecommendationAcceptance;
use MoxDop\MetaAds\Workspace\MetaAdsWorkspaceData;

class MetaAdsIntelligenceRelationManager extends RelationManager
{
    protected static string $relationship = 'findings';

    protected static ?string $title = 'Intelligence';

    protected static bool $isLazy = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof DigitalAsset && $ownerRecord->type === 'meta_ads';
    }

    public function content(Schema $schema): Schema
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();

        return $schema->components([
            View::make('meta-ads::workspace.intelligence')
                ->viewData([
                    'data' => app(MetaAdsWorkspaceData::class)->for($asset),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([])->paginated(false);
    }

    public function acceptAiRecommendationDraft(int $findingId): void
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();

        try {
            $result = app(MetaAdsAiRecommendationAcceptance::class)->acceptDraft($asset, $findingId);
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title('Could not create recommendation')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title($result['created'] ? 'Recommendation created' : ($result['updated'] ? 'Recommendation updated' : 'Recommendation unchanged'))
            ->body($result['message'])
            ->{$result['created'] || $result['updated'] ? 'success' : 'warning'}()
            ->send();
    }
}

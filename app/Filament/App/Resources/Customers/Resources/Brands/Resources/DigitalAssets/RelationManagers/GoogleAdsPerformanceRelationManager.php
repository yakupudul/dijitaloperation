<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers;

use App\Models\DigitalAsset;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use MoxDop\GoogleAds\Workspace\GoogleAdsWorkspaceData;

class GoogleAdsPerformanceRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Performance';

    protected static bool $isLazy = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof DigitalAsset && $ownerRecord->type === 'google_ads';
    }

    public function content(Schema $schema): Schema
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();

        return $schema->components([
            View::make('google-ads::workspace.performance')
                ->viewData([
                    'data' => app(GoogleAdsWorkspaceData::class)->for($asset),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([])->paginated(false);
    }
}

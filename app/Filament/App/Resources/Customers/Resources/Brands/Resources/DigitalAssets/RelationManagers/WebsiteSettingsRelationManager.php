<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers;

use App\Enums\DigitalAssetStatus;
use App\Models\DigitalAsset;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class WebsiteSettingsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Settings';

    protected static bool $isLazy = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof DigitalAsset && $ownerRecord->type === 'website';
    }

    public function content(Schema $schema): Schema
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();

        return $schema
            ->record($asset)
            ->components([
                Section::make('Website identity')
                    ->schema([
                        TextEntry::make('brand.name')->label('Brand'),
                        TextEntry::make('name'),
                        TextEntry::make('type')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => filled($state)
                                ? str($state)->replace('_', ' ')->title()->toString()
                                : '—'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (?DigitalAssetStatus $state): string => match ($state) {
                                DigitalAssetStatus::Active => 'success',
                                DigitalAssetStatus::Inactive => 'warning',
                                DigitalAssetStatus::Archived => 'gray',
                                default => 'gray',
                            }),
                    ])
                    ->columns(2),
                Section::make('Website context')
                    ->schema([
                        TextEntry::make('domain')->placeholder('—'),
                        TextEntry::make('primary_url')->label('Primary URL')->placeholder('—'),
                        TextEntry::make('cms')->label('CMS')->placeholder('—'),
                        TextEntry::make('site_type')->label('Site type')->placeholder('—'),
                        TextEntry::make('languages')->badge()->placeholder('—'),
                        TextEntry::make('target_countries')->label('Target countries')->badge()->placeholder('—'),
                        TextEntry::make('hosting_context')->label('Hosting context')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Record metadata')
                    ->schema([
                        TextEntry::make('module_id')->label('Linked module')->placeholder('—'),
                        TextEntry::make('created_at')->dateTime()->placeholder('—'),
                        TextEntry::make('updated_at')->dateTime()->placeholder('—'),
                    ])
                    ->columns(3)
                    ->collapsed()
                    ->compact(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([])->paginated(false);
    }
}

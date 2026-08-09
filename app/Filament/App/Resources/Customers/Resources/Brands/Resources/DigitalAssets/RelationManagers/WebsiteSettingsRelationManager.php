<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers;

use App\Enums\DigitalAssetStatus;
use App\Models\DigitalAsset;
use App\Services\Integrations\DataForSeo\DataForSeoLabsMarketDirectory;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use MoxDop\Website\SeoIntelligence\DataForSeoIntegrationResolver;

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
                Section::make('Search market')
                    ->description('Used for external SEO intelligence and market-specific keyword data.')
                    ->headerActions([
                        Action::make('configureSeoMarket')
                            ->label('Configure SEO market')
                            ->color('gray')
                            ->modalHeading('Search market')
                            ->modalDescription('Choose the country and language used for DataForSEO keyword intelligence. Provider codes are resolved automatically.')
                            ->fillForm(fn (): array => [
                                'seo_market_location_code' => $asset->seo_market_location_code,
                                'seo_market_language_code' => $asset->seo_market_language_code,
                            ])
                            ->form([
                                Select::make('seo_market_location_code')
                                    ->label('Country')
                                    ->options(fn (): array => $this->locationOptions())
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->native(false)
                                    ->helperText(fn (): ?string => $this->directoryMessage()),
                                Select::make('seo_market_language_code')
                                    ->label('Language')
                                    ->options(fn (callable $get): array => $this->languageOptions(
                                        $get('seo_market_location_code') !== null
                                            ? (int) $get('seo_market_location_code')
                                            : null,
                                    ))
                                    ->searchable()
                                    ->required()
                                    ->native(false)
                                    ->disabled(fn (callable $get): bool => blank($get('seo_market_location_code'))),
                            ])
                            ->action(function (array $data): void {
                                $this->persistSeoMarket($data);
                            }),
                    ])
                    ->schema([
                        TextEntry::make('seo_market_location_name')
                            ->label('Country')
                            ->placeholder('Not configured'),
                        TextEntry::make('seo_market_language_name')
                            ->label('Language')
                            ->placeholder('Not configured'),
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

    public function configureSeoMarketAction(): Action
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();

        return Action::make('configureSeoMarket')
            ->label('Configure SEO market')
            ->color('gray')
            ->modalHeading('Search market')
            ->modalDescription('Choose the country and language used for DataForSEO keyword intelligence.')
            ->fillForm(fn (): array => [
                'seo_market_location_code' => $asset->seo_market_location_code,
                'seo_market_language_code' => $asset->seo_market_language_code,
            ])
            ->form([
                Select::make('seo_market_location_code')
                    ->label('Country')
                    ->options(fn (): array => $this->locationOptions())
                    ->searchable()
                    ->required()
                    ->live()
                    ->native(false),
                Select::make('seo_market_language_code')
                    ->label('Language')
                    ->options(fn (callable $get): array => $this->languageOptions(
                        $get('seo_market_location_code') !== null
                            ? (int) $get('seo_market_location_code')
                            : null,
                    ))
                    ->searchable()
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data): void {
                $this->persistSeoMarket($data);
            });
    }

    /**
     * @return array<int, string>
     */
    private function locationOptions(): array
    {
        $integration = app(DataForSeoIntegrationResolver::class)->active();
        $directory = app(DataForSeoLabsMarketDirectory::class);

        return $directory->locationOptions($integration);
    }

    /**
     * @return array<string, string>
     */
    private function languageOptions(?int $locationCode): array
    {
        if ($locationCode === null) {
            return [];
        }

        $integration = app(DataForSeoIntegrationResolver::class)->active();

        return app(DataForSeoLabsMarketDirectory::class)
            ->languageOptionsForLocation($integration, $locationCode);
    }

    private function directoryMessage(): ?string
    {
        $integration = app(DataForSeoIntegrationResolver::class)->active();
        if ($integration === null) {
            return 'Connect DataForSEO in Settings → Integrations to load the supported market list.';
        }

        $result = app(DataForSeoLabsMarketDirectory::class)->googleMarkets($integration);

        return $result['ok'] ? null : ($result['message'] ?? 'Market list unavailable.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistSeoMarket(array $data): void
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();
        $locationCode = (int) ($data['seo_market_location_code'] ?? 0);
        $languageCode = (string) ($data['seo_market_language_code'] ?? '');

        $integration = app(DataForSeoIntegrationResolver::class)->active();
        $directory = app(DataForSeoLabsMarketDirectory::class);
        $locationName = $directory->locationName($integration, $locationCode);
        $languageName = $directory->languageName($integration, $locationCode, $languageCode);

        if ($locationName === null || $languageName === null) {
            Notification::make()
                ->title('Invalid SEO market')
                ->body('Choose a supported country and language from the DataForSEO market list.')
                ->danger()
                ->send();

            return;
        }

        $asset->update([
            'seo_market_location_code' => $locationCode,
            'seo_market_location_name' => $locationName,
            'seo_market_language_code' => $languageCode,
            'seo_market_language_name' => $languageName,
        ]);

        Notification::make()
            ->title('Search market saved')
            ->body($locationName.' · '.$languageName)
            ->success()
            ->send();
    }
}

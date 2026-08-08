<?php

namespace App\Filament\App\Resources\Runs;

use App\Filament\App\Resources\Runs\Pages\ListRuns;
use App\Filament\App\Resources\Runs\Pages\ViewRun;
use App\Models\Run;
use App\Support\MoxDopNavigation;
use App\Support\RunTypeLabels;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use MoxDop\Website\Workspace\WebsiteWorkspaceData;
use UnitEnum;

class RunResource extends Resource
{
    protected static ?string $model = Run::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static ?string $navigationLabel = 'Activity';

    protected static string|UnitEnum|null $navigationGroup = MoxDopNavigation::OPERATIONS;

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'Activity';

    protected static ?string $pluralModelLabel = 'Activity';

    protected static ?string $slug = 'runs';

    protected static ?string $recordTitleAttribute = 'id';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                ViewEntry::make('website_run_presentation')
                    ->hiddenLabel()
                    ->visible(fn (Run $record): bool => static::isWebsitePresentable($record))
                    ->view('website::workspace.run-detail')
                    ->viewData(fn (ViewEntry $entry): array => [
                        'run' => $entry->getRecord(),
                        'presentation' => $entry->getRecord() instanceof Run
                            ? app(WebsiteWorkspaceData::class)->runPresentation($entry->getRecord())
                            : [],
                    ])
                    ->columnSpanFull(),
                Section::make('Summary')
                    ->schema([
                        TextEntry::make('activity_title')
                            ->label('Activity')
                            ->state(fn (Run $record): string => static::activityTitle($record)),
                        TextEntry::make('digitalAsset.name')
                            ->label('Digital asset')
                            ->placeholder('—'),
                        TextEntry::make('source_label')
                            ->label('Source')
                            ->state(fn (Run $record): string => static::sourceLabel($record))
                            ->placeholder('—'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'completed' => 'success',
                                'failed' => 'danger',
                                'running' => 'info',
                                'pending' => 'warning',
                                default => 'gray',
                            }),
                        TextEntry::make('period_label')
                            ->label('Period')
                            ->state(function (Run $record): string {
                                if (static::isWebsitePresentable($record)) {
                                    return app(WebsiteWorkspaceData::class)->runPresentation($record)['period_label'] ?? '—';
                                }

                                return '—';
                            })
                            ->placeholder('—'),
                        TextEntry::make('started_at')
                            ->label('Started')
                            ->dateTime(),
                        TextEntry::make('duration')
                            ->label('Duration')
                            ->state(function (Run $record): ?string {
                                if ($record->started_at === null || $record->finished_at === null) {
                                    return null;
                                }

                                return $record->started_at->diffForHumans($record->finished_at, [
                                    'parts' => 2,
                                    'short' => true,
                                    'syntax' => CarbonInterface::DIFF_ABSOLUTE,
                                ]);
                            })
                            ->placeholder('—'),
                        TextEntry::make('evidence_generated')
                            ->label('Data collected')
                            ->state(fn (Run $record): ?string => static::evidenceTypes($record))
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Technical details')
                    ->description('Raw metadata and Evidence payloads for debugging. Collapsed by default.')
                    ->schema([
                        TextEntry::make('id')
                            ->label('Run ID'),
                        TextEntry::make('module_id')
                            ->label('Internal type')
                            ->formatStateUsing(fn (?string $state): string => RunTypeLabels::label($state)),
                        TextEntry::make('metadata')
                            ->label('Execution context')
                            ->formatStateUsing(fn (mixed $state): ?string => static::prettyJson(static::sanitizeMetadata($state)))
                            ->fontFamily(FontFamily::Mono)
                            ->placeholder('No metadata')
                            ->columnSpanFull(),
                        TextEntry::make('evidence_payloads')
                            ->label('Raw evidence')
                            ->state(fn (Run $record): ?string => static::prettyJson(static::evidencePayloads($record)))
                            ->fontFamily(FontFamily::Mono)
                            ->placeholder('No evidence')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsed()
                    ->collapsible(),
            ]);
    }

    public static function activityTitle(Run $record): string
    {
        if (static::isWebsitePresentable($record)) {
            return app(WebsiteWorkspaceData::class)->runTitle($record);
        }

        return RunTypeLabels::label($record->module_id);
    }

    public static function isWebsitePresentable(Run $record): bool
    {
        return $record->module_id === 'website'
            || data_get($record->metadata, 'capability') === 'search_console'
            || data_get($record->metadata, 'capability') === 'ga4'
            || $record->module_id === 'website-diagnosis';
    }

    public static function sourceLabel(Run $record): string
    {
        $record->loadMissing([
            'coreConnection',
            'coreAssetBinding.externalResource.integration',
        ]);

        if ($record->core_asset_binding_id !== null) {
            $binding = $record->coreAssetBinding;
            $resource = $binding?->externalResource;
            $capability = match ((string) ($binding?->capability ?? '')) {
                'search_console' => 'Search Console',
                'ga4' => 'GA4',
                'google_ads' => 'Google Ads',
                'google_business_profile' => 'Business Profile',
                default => str((string) ($binding?->capability ?? 'Source'))->replace('_', ' ')->title()->toString(),
            };
            $resourceName = $resource?->display_name ?: ($resource?->external_id ?: 'Connected source');

            return $capability.' · '.$resourceName;
        }

        if ($record->coreConnection !== null) {
            return 'Site connection · '.$record->coreConnection->name;
        }

        return '—';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('digitalAsset.name')
                    ->label('Digital asset')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('module_id')
                    ->label('Activity')
                    ->formatStateUsing(fn (?string $state, Run $record): string => static::activityTitle($record))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'running' => 'info',
                        'pending' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('duration')
                    ->label('Duration')
                    ->state(function (Run $record): ?string {
                        if ($record->started_at === null || $record->finished_at === null) {
                            return null;
                        }

                        return $record->started_at->diffForHumans($record->finished_at, [
                            'parts' => 2,
                            'short' => true,
                            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
                        ]);
                    })
                    ->placeholder('—'),
                TextColumn::make('finished_at')
                    ->label('Finished')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('coreConnection.name')
                    ->label('Connection')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(fn (Run $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No runs')
            ->emptyStateDescription('No analysis runs have been recorded yet.')
            ->toolbarActions([])
            ->defaultSort('started_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRuns::route('/'),
            'view' => ViewRun::route('/{record}'),
        ];
    }

    /**
     * Prefer Evidence model rows; fall back to legacy metadata.evidence.
     * Never reads credentials / tokens.
     *
     * @return list<array<string, mixed>>|null
     */
    protected static function evidencePayloads(Run $record): ?array
    {
        $record->loadMissing('evidence');
        if ($record->evidence->isNotEmpty()) {
            return $record->evidence
                ->map(fn ($item): array => [
                    'type' => $item->type,
                    'title' => $item->title,
                    'payload' => static::sanitizeMetadata($item->payload),
                ])
                ->values()
                ->all();
        }

        $evidence = data_get($record->metadata, 'evidence');

        if (! is_array($evidence) || $evidence === []) {
            return null;
        }

        return array_values($evidence);
    }

    protected static function evidenceTypes(Run $record): ?string
    {
        $record->loadMissing('evidence');
        if ($record->evidence->isNotEmpty()) {
            $types = $record->evidence->pluck('type')->filter()->unique()->values();

            return $types->isEmpty() ? null : $types->implode(', ');
        }

        $payloads = static::evidencePayloads($record);

        if ($payloads === null) {
            return null;
        }

        $types = collect($payloads)
            ->map(fn (mixed $item): ?string => is_array($item) && isset($item['type']) && is_string($item['type'])
                ? $item['type']
                : null)
            ->filter()
            ->unique()
            ->values();

        return $types->isEmpty() ? null : $types->implode(', ');
    }

    protected static function prettyJson(mixed $state): ?string
    {
        if ($state === null || $state === '' || $state === []) {
            return null;
        }

        if (is_string($state)) {
            return $state;
        }

        if (! is_array($state)) {
            return null;
        }

        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }

    /**
     * @return array<string, mixed>|mixed
     */
    protected static function sanitizeMetadata(mixed $state): mixed
    {
        if (! is_array($state)) {
            return $state;
        }

        $blocked = ['access_token', 'refresh_token', 'client_secret', 'developer_token', 'authorization', 'token'];
        $clean = [];
        foreach ($state as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $blocked, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? static::sanitizeMetadata($value) : $value;
        }

        return $clean;
    }
}

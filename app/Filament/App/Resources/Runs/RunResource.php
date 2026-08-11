<?php

namespace App\Filament\App\Resources\Runs;

use App\Filament\App\Resources\Runs\Pages\ListRuns;
use App\Filament\App\Resources\Runs\Pages\ViewRun;
use App\Models\Brand;
use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use App\Support\Async\AsyncOperationTypes;
use App\Support\MoxDopNavigation;
use App\Support\RunTypeLabels;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
                Section::make('Operation')
                    ->schema([
                        TextEntry::make('activity_title')
                            ->label('Operation')
                            ->state(fn (Run $record): string => static::activityTitle($record)),
                        TextEntry::make('digitalAsset.brand.name')
                            ->label('Brand')
                            ->placeholder('—'),
                        TextEntry::make('digitalAsset.name')
                            ->label('Digital asset')
                            ->placeholder('—'),
                        TextEntry::make('provider_label')
                            ->label('Provider / module')
                            ->state(fn (Run $record): string => static::providerLabel($record))
                            ->placeholder('—'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state, Run $record): string => static::statusLabel($record, $state))
                            ->color(fn (?string $state, Run $record): string => static::statusColor($record, $state)),
                        TextEntry::make('phase_label')
                            ->label('Current step')
                            ->state(fn (Run $record): ?string => static::phaseLabel($record))
                            ->placeholder('—'),
                        TextEntry::make('started_at')
                            ->label('Started')
                            ->dateTime(),
                        TextEntry::make('finished_at')
                            ->label('Finished')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('duration')
                            ->label('Duration')
                            ->state(fn (Run $record): ?string => static::durationLabel($record))
                            ->placeholder('—'),
                        TextEntry::make('result_summary')
                            ->label('Result')
                            ->state(fn (Run $record): ?string => data_get($record->metadata, 'result_summary'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('failure_summary')
                            ->label('Issue')
                            ->state(fn (Run $record): ?string => data_get($record->metadata, 'failure_summary'))
                            ->visible(fn (Run $record): bool => filled(data_get($record->metadata, 'failure_summary')))
                            ->columnSpanFull(),
                        TextEntry::make('retry_eligibility')
                            ->label('Retry')
                            ->state(function (Run $record): string {
                                if (! (bool) data_get($record->metadata, 'async')) {
                                    return 'Not an async operation';
                                }

                                return app(AsyncOperationService::class)->canRetry($record)
                                    ? 'Eligible — use Retry above'
                                    : 'Not eligible (active duplicate, permanent failure, or still open)';
                            })
                            ->visible(fn (Run $record): bool => (bool) data_get($record->metadata, 'async')),
                    ])
                    ->columns(2),
                Section::make('Steps')
                    ->description('Phase history for this operation.')
                    ->schema([
                        TextEntry::make('stage_history')
                            ->hiddenLabel()
                            ->state(function (Run $record): string {
                                $stages = data_get($record->metadata, 'stages');
                                if (! is_array($stages) || $stages === []) {
                                    return 'No steps recorded yet.';
                                }

                                return collect($stages)
                                    ->map(function (mixed $stage): string {
                                        if (! is_array($stage)) {
                                            return '—';
                                        }

                                        return trim(sprintf(
                                            '%s — %s (%s)',
                                            (string) ($stage['label'] ?? $stage['phase'] ?? 'Step'),
                                            (string) ($stage['status'] ?? ''),
                                            (string) ($stage['at'] ?? ''),
                                        ));
                                    })
                                    ->implode("\n");
                            })
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (Run $record): bool => (bool) data_get($record->metadata, 'async')
                        && is_array(data_get($record->metadata, 'stages'))
                        && data_get($record->metadata, 'stages') !== [])
                    ->collapsed(false),
                Section::make('Source')
                    ->schema([
                        TextEntry::make('source_label')
                            ->label('Source')
                            ->state(fn (Run $record): string => static::sourceLabel($record))
                            ->placeholder('—'),
                        TextEntry::make('period_label')
                            ->label('Period')
                            ->state(function (Run $record): string {
                                if (static::isWebsitePresentable($record)) {
                                    return app(WebsiteWorkspaceData::class)->runPresentation($record)['period_label'] ?? '—';
                                }

                                return '—';
                            })
                            ->placeholder('—'),
                        TextEntry::make('evidence_generated')
                            ->label('Data collected')
                            ->state(fn (Run $record): ?string => static::evidenceTypes($record))
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsed(),
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
        $human = data_get($record->metadata, 'human_title');
        if (is_string($human) && $human !== '') {
            return $human;
        }

        $operationType = data_get($record->metadata, 'operation_type');
        if (is_string($operationType) && $operationType !== '') {
            return AsyncOperationTypes::label($operationType);
        }

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
                'meta_ads' => 'Meta Ads',
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

    public static function providerLabel(Run $record): string
    {
        $module = (string) ($record->module_id ?? '');

        return match (true) {
            str_contains($module, 'meta') => 'Meta Ads',
            str_contains($module, 'google-ads') => 'Google Ads',
            str_contains($module, 'seo') => 'SEO / DataForSEO',
            str_contains($module, 'discovery') || str_contains($module, 'website') => 'Website',
            str_contains($module, 'bound-collect') => 'Bound collection',
            default => RunTypeLabels::label($module),
        };
    }

    public static function phaseLabel(Run $record): ?string
    {
        $label = data_get($record->metadata, 'phase_label');

        return is_string($label) && $label !== '' ? $label : null;
    }

    public static function statusLabel(Run $record, ?string $state): string
    {
        if (data_get($record->metadata, 'needs_attention') === 'stale') {
            return 'Needs attention';
        }

        return match ($state) {
            'queued' => 'Queued',
            'running' => 'Running',
            'completed' => 'Completed',
            'partial' => 'Partial',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
            'pending' => 'Pending',
            default => $state ? str($state)->title()->toString() : 'Unknown',
        };
    }

    public static function statusColor(Run $record, ?string $state): string
    {
        if (data_get($record->metadata, 'needs_attention') === 'stale') {
            return 'warning';
        }

        return match ($state) {
            'completed' => 'success',
            'failed' => 'danger',
            'running', 'queued' => 'info',
            'partial', 'pending' => 'warning',
            'cancelled' => 'gray',
            default => 'gray',
        };
    }

    public static function durationLabel(Run $record): ?string
    {
        if ($record->started_at === null) {
            return null;
        }

        $end = $record->finished_at ?? now();

        return $record->started_at->diffForHumans($end, [
            'parts' => 2,
            'short' => true,
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['digitalAsset.brand']))
            ->columns([
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, Run $record): string => static::statusLabel($record, $state))
                    ->color(fn (?string $state, Run $record): string => static::statusColor($record, $state))
                    ->sortable(),
                TextColumn::make('module_id')
                    ->label('Operation')
                    ->formatStateUsing(fn (?string $state, Run $record): string => static::activityTitle($record))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('digitalAsset.brand.name')
                    ->label('Brand')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('digitalAsset.name')
                    ->label('Digital asset')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('provider')
                    ->label('Provider')
                    ->state(fn (Run $record): string => static::providerLabel($record))
                    ->toggleable(),
                TextColumn::make('phase')
                    ->label('Current step')
                    ->state(fn (Run $record): ?string => static::phaseLabel($record))
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('duration')
                    ->label('Duration')
                    ->state(fn (Run $record): ?string => static::durationLabel($record))
                    ->placeholder('—'),
                TextColumn::make('result')
                    ->label('Result')
                    ->state(fn (Run $record): ?string => data_get($record->metadata, 'result_summary')
                        ?? data_get($record->metadata, 'failure_summary'))
                    ->placeholder('—')
                    ->limit(48)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('finished_at')
                    ->label('Finished')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'queued' => 'Queued',
                        'running' => 'Running',
                        'completed' => 'Completed',
                        'partial' => 'Partial',
                        'failed' => 'Failed',
                        'cancelled' => 'Cancelled',
                    ]),
                SelectFilter::make('brand_id')
                    ->label('Brand')
                    ->options(fn (): array => Brand::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! filled($value)) {
                            return $query;
                        }

                        return $query->whereHas('digitalAsset', fn (Builder $q): Builder => $q->where('brand_id', $value));
                    }),
                SelectFilter::make('operation_type')
                    ->label('Operation type')
                    ->options(AsyncOperationTypes::labels())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! filled($value)) {
                            return $query;
                        }

                        return $query->where('metadata->operation_type', $value);
                    }),
                SelectFilter::make('module_id')
                    ->label('Provider / module')
                    ->options(fn (): array => Run::query()
                        ->whereNotNull('module_id')
                        ->distinct()
                        ->orderBy('module_id')
                        ->pluck('module_id')
                        ->mapWithKeys(fn (string $id): array => [$id => RunTypeLabels::label($id)])
                        ->all()),
                Filter::make('started_from')
                    ->form([
                        DatePicker::make('from')->label('Started from'),
                        DatePicker::make('until')->label('Started until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('started_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('started_at', '<=', $date));
                    }),
            ])
            ->recordUrl(fn (Run $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No activity yet')
            ->emptyStateDescription('Queued and completed operations across MoxDOP appear here.')
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

        $blocked = ['access_token', 'refresh_token', 'client_secret', 'developer_token', 'authorization', 'token', 'api_key', 'app_secret'];
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

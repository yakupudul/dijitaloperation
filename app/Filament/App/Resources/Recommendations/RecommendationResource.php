<?php

namespace App\Filament\App\Resources\Recommendations;

use App\Enums\RecommendationOrigin;
use App\Enums\RecommendationSourceKind;
use App\Filament\App\Resources\Findings\FindingResource;
use App\Filament\App\Resources\Recommendations\Pages\ListRecommendations;
use App\Filament\App\Resources\Recommendations\Pages\ViewRecommendation;
use App\Filament\App\Resources\Tasks\TaskResource;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\CreateTaskFromRecommendation;
use App\Support\MoxDopNavigation;
use App\Support\Tasks\TaskOutcomeStatus;
use App\Support\Tasks\TaskStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class RecommendationResource extends Resource
{
    protected static ?string $model = Recommendation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static ?string $navigationLabel = 'Recommendations';

    protected static string|UnitEnum|null $navigationGroup = MoxDopNavigation::OPERATIONS;

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Recommendation';

    protected static ?string $pluralModelLabel = 'Recommendations';

    protected static ?string $slug = 'recommendations';

    protected static ?string $recordTitleAttribute = 'title';

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
                TextEntry::make('id')
                    ->label('ID'),
                TextEntry::make('source_kind')
                    ->label('Source kind')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => RecommendationSourceKind::tryFrom((string) $state)?->label() ?? '—')
                    ->color(fn (?string $state): string => $state === RecommendationSourceKind::Opportunity->value ? 'info' : 'warning'),
                TextEntry::make('finding_id')
                    ->label('Finding')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? "Finding #{$state}" : '-')
                    ->url(fn ($record): ?string => $record->finding_id === null
                        ? null
                        : FindingResource::getUrl('view', ['record' => $record->finding_id]))
                    ->placeholder('-'),
                TextEntry::make('opportunity_id')
                    ->label('Opportunity')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? "Opportunity #{$state}" : '-')
                    ->placeholder('-'),
                TextEntry::make('digitalAsset.name')
                    ->label('Digital asset')
                    ->placeholder('-'),
                TextEntry::make('origin')
                    ->label('Origin')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => RecommendationOrigin::tryFrom((string) $state)?->label() ?? '—'),
                TextEntry::make('provenance')
                    ->label('Provenance')
                    ->badge()
                    ->state(fn ($record): string => ($record->source_module ?? '') === 'website-ai-insights'
                        ? 'AI-assisted'
                        : 'Deterministic'),
                TextEntry::make('source_module')
                    ->label('Source')
                    ->formatStateUsing(fn (?string $state): string => filled($state)
                        ? str($state)->replace(['-', '_'], ' ')->title()->toString()
                        : '—'),
                TextEntry::make('title'),
                TextEntry::make('action')
                    ->label('Suggested action')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('rationale')
                    ->label('Rationale')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('priority')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'gray',
                        default => 'gray',
                    }),
                TextEntry::make('effort')
                    ->placeholder('-'),
                TextEntry::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'open' => 'warning',
                        'accepted' => 'info',
                        'dismissed' => 'gray',
                        'converted' => 'success',
                        default => 'gray',
                    }),
                RepeatableEntry::make('tasks')
                    ->label('Tasks')
                    ->schema([
                        TextEntry::make('id')
                            ->label('Task')
                            ->formatStateUsing(fn (mixed $state): string => "Task #{$state}")
                            ->url(fn ($record): string => TaskResource::getUrl('view', ['record' => $record])),
                        TextEntry::make('status')
                            ->label('Task status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state !== null ? TaskStatus::label($state) : '—'),
                        TextEntry::make('outcome_status')
                            ->label('Outcome')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state !== null
                                ? TaskOutcomeStatus::label($state)
                                : '—'),
                    ])
                    ->columnSpanFull()
                    ->placeholder('No Task created'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->label('Recommendation')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(80),
                TextColumn::make('digitalAsset.name')
                    ->label('Digital asset')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('priority')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'open' => 'warning',
                        'accepted' => 'info',
                        'dismissed' => 'gray',
                        'converted' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('action')
                    ->label('Suggested action')
                    ->limit(56)
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_kind')
                    ->label('Source kind')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => RecommendationSourceKind::tryFrom((string) $state)?->label() ?? '—')
                    ->color(fn (?string $state): string => $state === RecommendationSourceKind::Opportunity->value ? 'info' : 'warning')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('origin')
                    ->label('Origin')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => RecommendationOrigin::tryFrom((string) $state)?->label() ?? '—')
                    ->toggleable(),
                TextColumn::make('source_module')
                    ->label('Source')
                    ->formatStateUsing(fn (?string $state): ?string => filled($state)
                        ? str($state)->replace(['-', '_'], ' ')->title()->toString()
                        : null)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source_kind')
                    ->label('Source kind')
                    ->options([
                        RecommendationSourceKind::Finding->value => RecommendationSourceKind::Finding->label(),
                        RecommendationSourceKind::Opportunity->value => RecommendationSourceKind::Opportunity->label(),
                    ]),
                SelectFilter::make('digital_asset_id')
                    ->label('Digital asset')
                    ->relationship('digitalAsset', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'accepted' => 'Accepted',
                        'dismissed' => 'Dismissed',
                        'converted' => 'Converted',
                    ]),
                SelectFilter::make('priority')
                    ->options([
                        'critical' => 'Critical',
                        'high' => 'High',
                        'medium' => 'Medium',
                        'low' => 'Low',
                    ]),
            ])
            ->recordUrl(fn (Recommendation $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
                static::makeCreateTaskAction(),
            ])
            ->emptyStateHeading('No recommendations')
            ->emptyStateDescription('No recommended actions are waiting right now.')
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecommendations::route('/'),
            'view' => ViewRecommendation::route('/{record}'),
        ];
    }

    public static function makeCreateTaskAction(): Action
    {
        return Action::make('createTask')
            ->label('Create Task')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->modalHeading('Create Task from Recommendation')
            ->modalSubmitActionLabel('Create Task')
            ->authorize(fn (): bool => app(CreateTaskFromRecommendation::class)->userCanConvert(auth()->user()))
            ->fillForm(function (Recommendation $record): array {
                return [
                    'title' => $record->title,
                    'priority' => $record->priority,
                    'assignee_id' => null,
                    'due_date' => null,
                ];
            })
            ->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Select::make('assignee_id')
                    ->label('Assignee')
                    ->options(fn (): array => User::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->nullable(),
                DatePicker::make('due_date')
                    ->label('Due date')
                    ->nullable(),
                Select::make('priority')
                    ->options([
                        'critical' => 'Critical',
                        'high' => 'High',
                        'medium' => 'Medium',
                        'low' => 'Low',
                    ])
                    ->required(),
            ])
            ->action(function (Recommendation $record, array $data): void {
                $service = app(CreateTaskFromRecommendation::class);

                abort_unless(
                    $service->userCanConvert(auth()->user()),
                    403,
                );

                $task = $service->create($record, [
                    'title' => $data['title'] ?? null,
                    'assignee_id' => $data['assignee_id'] ?? null,
                    'due_date' => $data['due_date'] ?? null,
                    'priority' => $data['priority'] ?? null,
                ]);

                Notification::make()
                    ->title('Task created')
                    ->body("Task #{$task->id} was created from this recommendation.")
                    ->success()
                    ->send();
            });
    }
}

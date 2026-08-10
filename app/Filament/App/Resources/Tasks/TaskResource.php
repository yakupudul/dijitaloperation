<?php

namespace App\Filament\App\Resources\Tasks;

use App\Filament\App\Resources\Findings\FindingResource;
use App\Filament\App\Resources\Recommendations\RecommendationResource;
use App\Filament\App\Resources\Runs\RunResource;
use App\Filament\App\Resources\Tasks\Pages\ListTasks;
use App\Filament\App\Resources\Tasks\Pages\ViewTask;
use App\Models\Task;
use App\Models\User;
use App\Services\CreateTaskFromRecommendation;
use App\Services\Tasks\TaskLifecycleService;
use App\Services\Tasks\TaskOutcomeEvaluator;
use App\Support\MoxDopNavigation;
use App\Support\Tasks\TaskOutcomeStatus;
use App\Support\Tasks\TaskStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Tasks';

    protected static string|UnitEnum|null $navigationGroup = MoxDopNavigation::OPERATIONS;

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Task';

    protected static ?string $pluralModelLabel = 'Tasks';

    protected static ?string $slug = 'tasks';

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
                Section::make('Before')
                    ->description('Why this Task exists — original Finding and Recommendation context.')
                    ->schema([
                        TextEntry::make('snapshot_finding_title')
                            ->label('Original Finding')
                            ->state(function (Task $record): string {
                                $findingId = data_get($record->snapshot_json, 'finding.id')
                                    ?? $record->recommendation?->finding_id;

                                return $findingId !== null ? "Finding #{$findingId}" : 'Not linked';
                            })
                            ->url(function (Task $record): ?string {
                                $findingId = data_get($record->snapshot_json, 'finding.id')
                                    ?? $record->recommendation?->finding_id;

                                return $findingId !== null
                                    ? FindingResource::getUrl('view', ['record' => $findingId])
                                    : null;
                            }),
                        TextEntry::make('snapshot_finding_severity')
                            ->label('Finding severity')
                            ->badge()
                            ->state(fn (Task $record): ?string => data_get($record->snapshot_json, 'finding.severity')
                                ?? $record->recommendation?->finding?->severity)
                            ->color(fn (?string $state): string => match ($state) {
                                'critical' => 'danger',
                                'high' => 'warning',
                                'medium' => 'info',
                                'low' => 'gray',
                                default => 'gray',
                            })
                            ->placeholder('—'),
                        TextEntry::make('recommendation_id')
                            ->label('Recommendation')
                            ->formatStateUsing(fn (?int $state): string => $state !== null ? "Recommendation #{$state}" : '—')
                            ->url(fn (Task $record): ?string => $record->recommendation_id !== null
                                ? RecommendationResource::getUrl('view', ['record' => $record->recommendation_id])
                                : null)
                            ->placeholder('—'),
                        TextEntry::make('baseline_run')
                            ->label('Baseline Run')
                            ->state(function (Task $record): string {
                                $runId = data_get($record->outcome_json, 'baseline.run_id')
                                    ?? data_get($record->snapshot_json, 'finding.last_run_id');

                                return $runId !== null ? "Run #{$runId}" : '—';
                            })
                            ->url(function (Task $record): ?string {
                                $runId = data_get($record->outcome_json, 'baseline.run_id')
                                    ?? data_get($record->snapshot_json, 'finding.last_run_id');

                                return is_numeric($runId)
                                    ? RunResource::getUrl('view', ['record' => (int) $runId])
                                    : null;
                            }),
                        TextEntry::make('brand.name')
                            ->label('Brand')
                            ->placeholder('—'),
                        TextEntry::make('digitalAsset.name')
                            ->label('Digital asset')
                            ->placeholder('—'),
                        TextEntry::make('rationale')
                            ->label('Why')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Action')
                    ->description('Planned human work. Task status is separate from Outcome.')
                    ->schema([
                        TextEntry::make('title')
                            ->label('Task'),
                        TextEntry::make('action')
                            ->label('Planned action')
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
                        TextEntry::make('assignee.name')
                            ->label('Assignee')
                            ->placeholder('Unassigned'),
                        TextEntry::make('due_date')
                            ->label('Due date')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('status')
                            ->label('Task status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state !== null ? TaskStatus::label($state) : '—')
                            ->color(fn (?string $state): string => match ($state) {
                                TaskStatus::OPEN => 'warning',
                                TaskStatus::IN_PROGRESS => 'info',
                                TaskStatus::BLOCKED => 'danger',
                                TaskStatus::COMPLETED => 'success',
                                TaskStatus::CANCELLED => 'gray',
                                default => 'gray',
                            }),
                        TextEntry::make('completed_at')
                            ->label('Completed at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('completedBy.name')
                            ->label('Completed by')
                            ->placeholder('—'),
                        TextEntry::make('completion_note')
                            ->label('Completion note')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('outcome_review_after_at')
                            ->label('Review outcome after')
                            ->dateTime()
                            ->placeholder('Immediately eligible after completion'),
                    ])
                    ->columns(2),
                Section::make('After')
                    ->description('Latest eligible follow-up evaluation linked to the source Finding.')
                    ->schema([
                        TextEntry::make('outcome_run_id')
                            ->label('Follow-up Run')
                            ->formatStateUsing(fn (?int $state): string => $state !== null ? "Run #{$state}" : '—')
                            ->url(fn (Task $record): ?string => $record->outcome_run_id !== null
                                ? RunResource::getUrl('view', ['record' => $record->outcome_run_id])
                                : null)
                            ->placeholder('—'),
                        TextEntry::make('follow_up_finding_status')
                            ->label('Finding state at follow-up')
                            ->state(fn (Task $record): ?string => data_get($record->outcome_json, 'follow_up.finding_status'))
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('follow_up_observed_at')
                            ->label('Follow-up observed')
                            ->state(fn (Task $record): ?string => data_get($record->outcome_json, 'follow_up.observed_at'))
                            ->placeholder('—'),
                        TextEntry::make('current_finding_status')
                            ->label('Current Finding status')
                            ->state(fn (Task $record): ?string => $record->recommendation?->finding?->status)
                            ->badge()
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Outcome')
                    ->description('Observed post-action Evidence signal — not causal attribution. Available Evidence does not by itself prove the Task caused the change.')
                    ->schema([
                        TextEntry::make('outcome_status')
                            ->label('Outcome')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state !== null
                                ? TaskOutcomeStatus::label($state)
                                : 'Not monitored yet')
                            ->color(fn (?string $state): string => match ($state) {
                                TaskOutcomeStatus::AWAITING_FOLLOW_UP => 'warning',
                                TaskOutcomeStatus::IMPROVEMENT_OBSERVED => 'success',
                                TaskOutcomeStatus::STILL_OBSERVED => 'info',
                                TaskOutcomeStatus::REGRESSION_OBSERVED => 'danger',
                                TaskOutcomeStatus::INSUFFICIENT_EVIDENCE => 'gray',
                                TaskOutcomeStatus::NOT_EVALUABLE => 'gray',
                                default => 'gray',
                            }),
                        TextEntry::make('outcome_checked_at')
                            ->label('Last checked')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('outcome_explanation')
                            ->label('Explanation')
                            ->state(fn (Task $record): string => data_get($record->outcome_json, 'explanation')
                                ?? ($record->outcome_status !== null
                                    ? TaskOutcomeStatus::explanation($record->outcome_status)
                                    : 'Complete the Task to begin post-action Outcome monitoring.'))
                            ->columnSpanFull(),
                        TextEntry::make('causal_attribution')
                            ->label('Causal attribution')
                            ->state('No — Outcome is an observed signal only.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->label('Task')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(72),
                TextColumn::make('brand.name')
                    ->label('Brand')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
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
                TextColumn::make('assignee.name')
                    ->label('Assignee')
                    ->placeholder('Unassigned')
                    ->toggleable(),
                TextColumn::make('due_date')
                    ->label('Due')
                    ->date()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Task status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state !== null ? TaskStatus::label($state) : '—')
                    ->color(fn (?string $state): string => match ($state) {
                        TaskStatus::OPEN => 'warning',
                        TaskStatus::IN_PROGRESS => 'info',
                        TaskStatus::BLOCKED => 'danger',
                        TaskStatus::COMPLETED => 'success',
                        TaskStatus::CANCELLED => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('outcome_status')
                    ->label('Outcome')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state !== null
                        ? TaskOutcomeStatus::label($state)
                        : '—')
                    ->color(fn (?string $state): string => match ($state) {
                        TaskOutcomeStatus::AWAITING_FOLLOW_UP => 'warning',
                        TaskOutcomeStatus::IMPROVEMENT_OBSERVED => 'success',
                        TaskOutcomeStatus::STILL_OBSERVED => 'info',
                        TaskOutcomeStatus::REGRESSION_OBSERVED => 'danger',
                        TaskOutcomeStatus::INSUFFICIENT_EVIDENCE => 'gray',
                        TaskOutcomeStatus::NOT_EVALUABLE => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Task status')
                    ->options(collect(TaskStatus::all())
                        ->mapWithKeys(fn (string $status): array => [$status => TaskStatus::label($status)])
                        ->all()),
                SelectFilter::make('outcome_status')
                    ->label('Outcome')
                    ->options(collect(TaskOutcomeStatus::all())
                        ->mapWithKeys(fn (string $status): array => [$status => TaskOutcomeStatus::label($status)])
                        ->all()),
                SelectFilter::make('assignee_id')
                    ->label('Assignee')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('brand_id')
                    ->label('Brand')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('digital_asset_id')
                    ->label('Digital asset')
                    ->relationship('digitalAsset', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('priority')
                    ->options([
                        'critical' => 'Critical',
                        'high' => 'High',
                        'medium' => 'Medium',
                        'low' => 'Low',
                    ]),
                TernaryFilter::make('overdue')
                    ->label('Overdue')
                    ->queries(
                        true: fn (Builder $query): Builder => $query
                            ->whereIn('status', TaskStatus::active())
                            ->whereNotNull('due_date')
                            ->whereDate('due_date', '<', now()->toDateString()),
                        false: fn (Builder $query): Builder => $query
                            ->where(function (Builder $inner): void {
                                $inner->whereNotIn('status', TaskStatus::active())
                                    ->orWhereNull('due_date')
                                    ->orWhereDate('due_date', '>=', now()->toDateString());
                            }),
                    ),
            ])
            ->recordUrl(fn (Task $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No tasks')
            ->emptyStateDescription('Create a Task from a Recommendation when work is ready to assign.')
            ->toolbarActions([])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'view' => ViewTask::route('/{record}'),
        ];
    }

    public static function operatorCanManage(?User $user): bool
    {
        return app(CreateTaskFromRecommendation::class)->userCanConvert($user);
    }

    public static function makeStartWorkAction(): Action
    {
        return Action::make('startWork')
            ->label('Start work')
            ->icon(Heroicon::OutlinedPlay)
            ->color('info')
            ->visible(fn (Task $record): bool => $record->status === TaskStatus::OPEN)
            ->authorize(fn (): bool => static::operatorCanManage(auth()->user()))
            ->action(function (Task $record): void {
                app(TaskLifecycleService::class)->start($record, auth()->user());
                Notification::make()->title('Work started')->success()->send();
            });
    }

    public static function makeBlockAction(): Action
    {
        return Action::make('block')
            ->label('Block')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Task $record): bool => $record->status === TaskStatus::IN_PROGRESS)
            ->authorize(fn (): bool => static::operatorCanManage(auth()->user()))
            ->action(function (Task $record): void {
                app(TaskLifecycleService::class)->block($record, auth()->user());
                Notification::make()->title('Task blocked')->warning()->send();
            });
    }

    public static function makeResumeAction(): Action
    {
        return Action::make('resume')
            ->label('Resume')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('info')
            ->visible(fn (Task $record): bool => $record->status === TaskStatus::BLOCKED)
            ->authorize(fn (): bool => static::operatorCanManage(auth()->user()))
            ->action(function (Task $record): void {
                app(TaskLifecycleService::class)->resume($record, auth()->user());
                Notification::make()->title('Task resumed')->success()->send();
            });
    }

    public static function makeCompleteAction(): Action
    {
        return Action::make('complete')
            ->label('Complete')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Task $record): bool => in_array($record->status, TaskStatus::active(), true))
            ->authorize(fn (): bool => static::operatorCanManage(auth()->user()))
            ->modalHeading('Complete Task')
            ->modalDescription('Marks the planned work as completed by you. This does not resolve the Finding or claim that the work succeeded — Outcome is observed later from follow-up Evidence.')
            ->modalSubmitActionLabel('Complete')
            ->schema([
                Textarea::make('completion_note')
                    ->label('Completion note')
                    ->rows(3)
                    ->nullable(),
                DateTimePicker::make('outcome_review_after_at')
                    ->label('Review outcome after')
                    ->helperText('Optional. When set, only Finding evaluations after this time are eligible for Outcome.')
                    ->nullable(),
            ])
            ->action(function (Task $record, array $data): void {
                app(TaskLifecycleService::class)->complete($record, [
                    'completion_note' => $data['completion_note'] ?? null,
                    'outcome_review_after_at' => $data['outcome_review_after_at'] ?? null,
                ], auth()->user());

                Notification::make()
                    ->title('Task completed')
                    ->body('Outcome is awaiting a comparable follow-up Finding evaluation.')
                    ->success()
                    ->send();
            });
    }

    public static function makeCancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (Task $record): bool => in_array($record->status, TaskStatus::active(), true))
            ->authorize(fn (): bool => static::operatorCanManage(auth()->user()))
            ->action(function (Task $record): void {
                app(TaskLifecycleService::class)->cancel($record, auth()->user());
                Notification::make()->title('Task cancelled')->success()->send();
            });
    }

    public static function makeReevaluateOutcomeAction(): Action
    {
        return Action::make('reevaluateOutcome')
            ->label('Re-evaluate outcome')
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->color('gray')
            ->visible(fn (Task $record): bool => $record->status === TaskStatus::COMPLETED)
            ->authorize(fn (): bool => static::operatorCanManage(auth()->user()))
            ->modalHeading('Re-evaluate outcome')
            ->modalDescription('Uses existing stored Run/Finding state only. No external API, AI, or write actions.')
            ->action(function (Task $record): void {
                $task = app(TaskOutcomeEvaluator::class)->reevaluateFromStoredState($record);

                Notification::make()
                    ->title('Outcome refreshed')
                    ->body(TaskOutcomeStatus::label((string) $task->outcome_status))
                    ->success()
                    ->send();
            });
    }
}

<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\RelationManagers;

use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\User;
use App\Services\BrandIntelligence\BrandContextProvider;
use App\Services\BrandIntelligence\BrandIntelligenceContextWriteService;
use App\Support\BrandIntelligence\BusinessModelOptions;
use App\Support\BrandIntelligence\ConversionGoalTypes;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class BrandIntelligenceRelationManager extends RelationManager
{
    protected static string $relationship = 'intelligenceContext';

    protected static ?string $title = 'Intelligence';

    protected static bool $isLazy = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Brand;
    }

    public function content(Schema $schema): Schema
    {
        /** @var Brand $brand */
        $brand = $this->getOwnerRecord();
        $snapshot = app(BrandContextProvider::class)->for($brand);

        return $schema->components([
            View::make('filament.app.brands.intelligence')
                ->viewData([
                    'snapshot' => $snapshot,
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([])->paginated(false)->headerActions([]);
    }

    public function editIntelligenceAction(): Action
    {
        /** @var Brand $brand */
        $brand = $this->getOwnerRecord();
        $context = $brand->intelligenceContext;

        return Action::make('editIntelligence')
            ->label($context ? 'Edit business context' : 'Add business context')
            ->modalHeading('Brand business context')
            ->modalDescription('Enter factual operator knowledge only. Leave unknowns empty. This is not Website SEO market configuration.')
            ->modalWidth('5xl')
            ->fillForm(fn (): array => $this->formStateFromContext($context))
            ->form([
                Section::make('Business')
                    ->schema([
                        Textarea::make('business_summary')
                            ->label('Business summary')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        Select::make('business_model')
                            ->label('Business model')
                            ->options(BusinessModelOptions::options())
                            ->native(false)
                            ->searchable(),
                    ])
                    ->columns(2),
                Section::make('Offerings')
                    ->schema([
                        Repeater::make('products_services')
                            ->label('Products & services')
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('description')
                                    ->maxLength(500),
                            ])
                            ->columns(2)
                            ->reorderable()
                            ->collapsible()
                            ->defaultItems(0)
                            ->addActionLabel('Add offering')
                            ->columnSpanFull(),
                        Repeater::make('priority_offerings')
                            ->label('Priority offerings (ordered)')
                            ->simple(
                                TextInput::make('name')
                                    ->label('Offering name')
                                    ->required()
                                    ->maxLength(255)
                                    ->helperText('Use the same name as in products & services.'),
                            )
                            ->reorderable()
                            ->defaultItems(0)
                            ->addActionLabel('Add priority offering')
                            ->helperText('Commercially important offerings in priority order. Not a score.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Audiences & markets')
                    ->schema([
                        Repeater::make('target_audiences')
                            ->label('Target audiences')
                            ->schema([
                                TextInput::make('name')->required()->maxLength(255),
                                TextInput::make('note')->maxLength(255),
                            ])
                            ->columns(2)
                            ->reorderable()
                            ->defaultItems(0)
                            ->addActionLabel('Add audience')
                            ->columnSpanFull(),
                        Repeater::make('target_markets')
                            ->label('Target markets')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Country / market')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('note')->maxLength(255),
                            ])
                            ->columns(2)
                            ->reorderable()
                            ->defaultItems(0)
                            ->addActionLabel('Add market')
                            ->helperText('Brand business markets. Independent from Website SEO market.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Goals')
                    ->schema([
                        Repeater::make('business_goals')
                            ->label('Business goals')
                            ->schema([
                                TextInput::make('goal')->required()->maxLength(255),
                                TextInput::make('note')->maxLength(255),
                            ])
                            ->columns(2)
                            ->reorderable()
                            ->defaultItems(0)
                            ->addActionLabel('Add business goal')
                            ->columnSpanFull(),
                        Repeater::make('conversion_goals')
                            ->label('Conversion goals')
                            ->schema([
                                Select::make('type')
                                    ->label('Goal type')
                                    ->options(ConversionGoalTypes::options())
                                    ->required()
                                    ->native(false),
                                TextInput::make('label')
                                    ->label('Label')
                                    ->maxLength(255),
                                TextInput::make('note')
                                    ->maxLength(255),
                            ])
                            ->columns(3)
                            ->reorderable()
                            ->defaultItems(0)
                            ->addActionLabel('Add conversion goal')
                            ->helperText('Business outcomes only — not mapped to GA4 events yet.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Competition')
                    ->schema([
                        Repeater::make('known_competitors')
                            ->label('Known competitors')
                            ->schema([
                                TextInput::make('name')->required()->maxLength(255),
                                TextInput::make('url')
                                    ->label('Website')
                                    ->url()
                                    ->maxLength(255),
                                TextInput::make('note')->maxLength(255),
                            ])
                            ->columns(3)
                            ->reorderable()
                            ->defaultItems(0)
                            ->addActionLabel('Add competitor')
                            ->helperText('Factual list only. No automatic competitor fetching.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Positioning & constraints')
                    ->schema([
                        Textarea::make('positioning')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        Repeater::make('differentiators')
                            ->label('Differentiators')
                            ->simple(TextInput::make('name')->required()->maxLength(255))
                            ->reorderable()
                            ->defaultItems(0)
                            ->addActionLabel('Add differentiator')
                            ->columnSpanFull(),
                        Textarea::make('important_constraints')
                            ->label('Important constraints')
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText('e.g. regulated advertising, geographic limits, capacity, language requirements.')
                            ->columnSpanFull(),
                    ]),
            ])
            ->action(function (array $data): void {
                $this->persistContext($data);
            });
    }

    public function clearIntelligenceAction(): Action
    {
        return Action::make('clearIntelligence')
            ->label('Clear context')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Clear Brand intelligence context?')
            ->modalDescription('Removes the structured business context for this Brand. Legacy Brand identity fields are not deleted.')
            ->modalSubmitActionLabel('Clear context')
            ->action(function (): void {
                /** @var Brand $brand */
                $brand = $this->getOwnerRecord();
                $brand->intelligenceContext?->delete();

                Notification::make()
                    ->title('Brand context cleared')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function formStateFromContext(?BrandIntelligenceContext $context): array
    {
        if ($context === null) {
            return [
                'business_summary' => null,
                'business_model' => null,
                'products_services' => [],
                'priority_offerings' => [],
                'target_audiences' => [],
                'target_markets' => [],
                'business_goals' => [],
                'conversion_goals' => [],
                'positioning' => null,
                'differentiators' => [],
                'known_competitors' => [],
                'important_constraints' => null,
            ];
        }

        return [
            'business_summary' => $context->business_summary,
            'business_model' => $context->business_model,
            'products_services' => is_array($context->products_services) ? $context->products_services : [],
            'priority_offerings' => is_array($context->priority_offerings) ? $context->priority_offerings : [],
            'target_audiences' => is_array($context->target_audiences) ? $context->target_audiences : [],
            'target_markets' => is_array($context->target_markets) ? $context->target_markets : [],
            'business_goals' => is_array($context->business_goals) ? $context->business_goals : [],
            'conversion_goals' => is_array($context->conversion_goals) ? $context->conversion_goals : [],
            'positioning' => $context->positioning,
            'differentiators' => is_array($context->differentiators) ? $context->differentiators : [],
            'known_competitors' => is_array($context->known_competitors) ? $context->known_competitors : [],
            'important_constraints' => $context->important_constraints,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistContext(array $data): void
    {
        /** @var Brand $brand */
        $brand = $this->getOwnerRecord();

        $payload = [
            'business_summary' => $this->nullableTrim($data['business_summary'] ?? null),
            'business_model' => $this->nullableTrim($data['business_model'] ?? null),
            'products_services' => $this->cleanNamedRows($data['products_services'] ?? [], ['name', 'description']),
            'priority_offerings' => $this->cleanStringList($data['priority_offerings'] ?? []),
            'target_audiences' => $this->cleanNamedRows($data['target_audiences'] ?? [], ['name', 'note']),
            'target_markets' => $this->cleanNamedRows($data['target_markets'] ?? [], ['name', 'note']),
            'business_goals' => $this->cleanNamedRows($data['business_goals'] ?? [], ['goal', 'note'], nameKey: 'goal'),
            'conversion_goals' => $this->cleanConversionGoals($data['conversion_goals'] ?? []),
            'positioning' => $this->nullableTrim($data['positioning'] ?? null),
            'differentiators' => $this->cleanStringList($data['differentiators'] ?? []),
            'known_competitors' => $this->cleanNamedRows($data['known_competitors'] ?? [], ['name', 'url', 'note']),
            'important_constraints' => $this->nullableTrim($data['important_constraints'] ?? null),
        ];

        /** @var User|null $actor */
        $actor = Auth::user();

        app(BrandIntelligenceContextWriteService::class)->saveFromForm(
            $brand,
            $payload,
            $actor instanceof User ? $actor : null,
        );

        Notification::make()
            ->title('Brand context saved')
            ->body('Factual business context updated.')
            ->success()
            ->send();
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<string>
     */
    private function cleanStringList(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_string($row)) {
                $value = trim($row);
                if ($value !== '') {
                    $out[] = $value;
                }

                continue;
            }

            if (is_array($row) && isset($row['name']) && is_string($row['name'])) {
                $value = trim($row['name']);
                if ($value !== '') {
                    $out[] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $rows
     * @param  list<string>  $keys
     * @return list<array<string, ?string>>
     */
    private function cleanNamedRows(array $rows, array $keys, string $nameKey = 'name'): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = isset($row[$nameKey]) && is_string($row[$nameKey]) ? trim($row[$nameKey]) : '';
            if ($name === '') {
                continue;
            }
            $clean = [];
            foreach ($keys as $key) {
                if ($key === $nameKey) {
                    $clean[$key] = $name;

                    continue;
                }
                $value = isset($row[$key]) && is_string($row[$key]) ? trim($row[$key]) : '';
                $clean[$key] = $value === '' ? null : $value;
            }
            $out[] = $clean;
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array{type: string, label: ?string, note: ?string}>
     */
    private function cleanConversionGoals(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = isset($row['type']) && is_string($row['type']) ? trim($row['type']) : '';
            if ($type === '' || ! array_key_exists($type, ConversionGoalTypes::options())) {
                continue;
            }
            $label = isset($row['label']) && is_string($row['label']) ? trim($row['label']) : '';
            $note = isset($row['note']) && is_string($row['note']) ? trim($row['note']) : '';
            $out[] = [
                'type' => $type,
                'label' => $label === '' ? null : $label,
                'note' => $note === '' ? null : $note,
            ];
        }

        return $out;
    }
}

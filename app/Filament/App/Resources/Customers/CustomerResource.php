<?php

namespace App\Filament\App\Resources\Customers;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Filament\App\Resources\Customers\Pages\CreateCustomer;
use App\Filament\App\Resources\Customers\Pages\EditCustomer;
use App\Filament\App\Resources\Customers\Pages\ListCustomers;
use App\Filament\App\Resources\Customers\Pages\ViewCustomer;
use App\Filament\App\Resources\Customers\RelationManagers\BrandsRelationManager;
use App\Models\Customer;
use App\Support\MoxDopNavigation;
use App\Support\Options\AgencyServiceOptions;
use App\Support\Options\CountryOptions;
use App\Support\Options\IndustryOptions;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Customers';

    protected static string|UnitEnum|null $navigationGroup = MoxDopNavigation::PORTFOLIO;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->schema([
                        TextInput::make('name')
                            ->label('Customer name')
                            ->helperText('The name your team uses internally.')
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->options(CustomerType::class)
                            ->required(),
                        TextInput::make('legal_name')
                            ->label('Legal name')
                            ->maxLength(255),
                        Select::make('status')
                            ->options(CustomerStatus::class)
                            ->required()
                            ->default(CustomerStatus::Active),
                    ])
                    ->columns(2),
                Section::make('Business profile')
                    ->schema([
                        Select::make('industry')
                            ->options(IndustryOptions::options())
                            ->searchable(),
                        Select::make('hq_country')
                            ->label('HQ country')
                            ->options(CountryOptions::options())
                            ->searchable(),
                        TextInput::make('hq_city')
                            ->label('HQ city')
                            ->maxLength(255),
                    ])
                    ->columns(3),
                Section::make('Contact')
                    ->schema([
                        TextInput::make('primary_email')
                            ->label('Primary email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('primary_phone')
                            ->label('Primary phone')
                            ->tel()
                            ->maxLength(255),
                    ])
                    ->columns(2),
                Section::make('Engagement')
                    ->schema([
                        DatePicker::make('service_started_at')
                            ->label('Service started'),
                        Select::make('services')
                            ->label('Services received')
                            ->options(AgencyServiceOptions::options())
                            ->multiple()
                            ->searchable()
                            ->columnSpanFull(),
                        Select::make('responsibleUsers')
                            ->label('Responsible team')
                            ->relationship('responsibleUsers', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText('Moximu team members responsible for this customer.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('type')
                            ->badge(),
                        TextEntry::make('legal_name')
                            ->label('Legal name')
                            ->placeholder('—'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (?CustomerStatus $state): string => match ($state) {
                                CustomerStatus::Active => 'success',
                                CustomerStatus::Inactive => 'warning',
                                CustomerStatus::Archived => 'gray',
                                default => 'gray',
                            }),
                    ])
                    ->columns(2),
                Section::make('Contact')
                    ->schema([
                        TextEntry::make('primary_email')
                            ->label('Primary email')
                            ->placeholder('—'),
                        TextEntry::make('primary_phone')
                            ->label('Primary phone')
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Engagement')
                    ->schema([
                        TextEntry::make('service_started_at')
                            ->label('Service started')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('services_received')
                            ->label('Services received')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Record metadata')
                    ->schema([
                        TextEntry::make('created_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('updated_at')
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columns(2)
                    ->collapsed()
                    ->compact(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?CustomerStatus $state): string => match ($state) {
                        CustomerStatus::Active => 'success',
                        CustomerStatus::Inactive => 'warning',
                        CustomerStatus::Archived => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('primary_email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('primary_phone')
                    ->label('Phone')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('legal_name')
                    ->label('Legal name')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('service_started_at')
                    ->label('Service started')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->recordUrl(fn (Customer $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            'brands' => BrandsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}

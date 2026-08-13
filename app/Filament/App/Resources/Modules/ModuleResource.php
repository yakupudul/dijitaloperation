<?php

namespace App\Filament\App\Resources\Modules;

use App\Filament\App\Resources\Modules\Pages\EditModule;
use App\Filament\App\Resources\Modules\Pages\ListModules;
use App\Models\ModuleRegistry;
use App\Models\User;
use App\Support\MoxDopNavigation;
use App\Support\Roles;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ModuleResource extends Resource
{
    protected static ?string $model = ModuleRegistry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?string $navigationLabel = 'Modules';

    protected static string|UnitEnum|null $navigationGroup = MoxDopNavigation::SYSTEM;

    protected static ?int $navigationSort = 80;

    protected static ?string $modelLabel = 'Module';

    protected static ?string $pluralModelLabel = 'Modules';

    protected static ?string $slug = 'modules';

    protected static ?string $recordTitleAttribute = 'module_id';

    /**
     * Modules are developer architecture — not normal operator navigation.
     * Admin URL access remains available for technical management.
     */
    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole(Roles::ADMIN);
    }

    public static function canCreate(): bool
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

    /**
     * Module Registry UI lists business capability modules only.
     * Developer fixtures (e.g. sample-module) remain seeded for packaging smoke tests.
     *
     * @return Builder<ModuleRegistry>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->operatorVisible();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('module_id')
                    ->label('Module ID')
                    ->disabled()
                    ->dehydrated(false),
                Toggle::make('enabled')
                    ->required(),
                TextInput::make('installed_version')
                    ->label('Installed version')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('module_id')
            ->columns([
                TextColumn::make('module_id')
                    ->label('Module ID')
                    ->searchable()
                    ->sortable(),
                ToggleColumn::make('enabled'),
                TextColumn::make('installed_version')
                    ->label('Installed version')
                    ->placeholder('—'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModules::route('/'),
            'edit' => EditModule::route('/{record}/edit'),
        ];
    }
}

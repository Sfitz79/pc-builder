<?php

namespace App\Filament\Resources\Components;

use App\Filament\Resources\Components\Pages\CreateComponent;
use App\Filament\Resources\Components\Pages\EditComponent;
use App\Filament\Resources\Components\Pages\ListComponents;
use App\Filament\Resources\Components\Schemas\ComponentForm;
use App\Filament\Resources\Components\Tables\ComponentsTable;
use App\Models\Component;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ComponentResource extends Resource
{
    protected static ?string $model = Component::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ComponentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ComponentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComponents::route('/'),
            'create' => CreateComponent::route('/create'),
            'edit' => EditComponent::route('/{record}/edit'),
        ];
    }
}

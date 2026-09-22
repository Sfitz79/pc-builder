<?php

namespace App\Filament\Resources\Builds;

use App\Filament\Resources\Builds\Pages\CreateBuild;
use App\Filament\Resources\Builds\Pages\EditBuild;
use App\Filament\Resources\Builds\Pages\ListBuilds;
use App\Filament\Resources\Builds\Schemas\BuildForm;
use App\Filament\Resources\Builds\Tables\BuildsTable;
use App\Models\Build;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BuildResource extends Resource
{
    protected static ?string $model = Build::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return BuildForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BuildsTable::configure($table);
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
            'index' => ListBuilds::route('/'),
            'create' => CreateBuild::route('/create'),
            'edit' => EditBuild::route('/{record}/edit'),
        ];
    }
}

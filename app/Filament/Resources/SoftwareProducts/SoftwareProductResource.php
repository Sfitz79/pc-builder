<?php

namespace App\Filament\Resources\SoftwareProducts;

use App\Filament\Resources\SoftwareProducts\Pages\CreateSoftwareProduct;
use App\Filament\Resources\SoftwareProducts\Pages\EditSoftwareProduct;
use App\Filament\Resources\SoftwareProducts\Pages\ListSoftwareProducts;
use App\Filament\Resources\SoftwareProducts\Schemas\SoftwareProductForm;
use App\Filament\Resources\SoftwareProducts\Tables\SoftwareProductsTable;
use App\Models\SoftwareProduct;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SoftwareProductResource extends Resource
{
    protected static ?string $model = SoftwareProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return SoftwareProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SoftwareProductsTable::configure($table);
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
            'index' => ListSoftwareProducts::route('/'),
            'create' => CreateSoftwareProduct::route('/create'),
            'edit' => EditSoftwareProduct::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Components\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ComponentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('category_id')
                    ->relationship('category', 'name')
                    ->required(),
                Select::make('manufacturer_id')
                    ->relationship('manufacturer', 'name')
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                TextInput::make('sku')
                    ->label('SKU'),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->prefix('£'),
                TextInput::make('currency')
                    ->required()
                    ->default('GBP')
                    ->maxLength(3),
                TextInput::make('socket'),
                TextInput::make('wattage')
                    ->numeric(),
                TextInput::make('stock')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('active')
                    ->default(true),
                KeyValue::make('specs')
                    ->columnSpanFull(),
                TextInput::make('source_url')
                    ->url(),
                TextInput::make('image_url')
                    ->url(),
                TextInput::make('chipset')
                    ->maxLength(255),
            ]);
    }
}

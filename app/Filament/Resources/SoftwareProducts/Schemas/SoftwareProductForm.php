<?php

namespace App\Filament\Resources\SoftwareProducts\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SoftwareProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('metenzi_product_id')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->maxLength(255),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('category')
                    ->maxLength(255),
                TextInput::make('platform')
                    ->maxLength(255),
                Textarea::make('description')
                    ->columnSpanFull(),
                Textarea::make('short_description')
                    ->columnSpanFull(),
                TextInput::make('retail_price')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->prefix('€'),
                TextInput::make('retail_price_cents')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('gbp_price')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->prefix('£'),
                TextInput::make('currency')
                    ->required()
                    ->default('GBP')
                    ->maxLength(3),
                TextInput::make('stock')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('active')
                    ->default(true),
                TextInput::make('warranty_days')
                    ->numeric(),
                TextInput::make('image_url')
                    ->url(),
                Textarea::make('instructions')
                    ->columnSpanFull(),
                TextInput::make('status')
                    ->required()
                    ->default('active')
                    ->maxLength(50),
            ]);
    }
}

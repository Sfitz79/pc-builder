<?php

namespace App\Filament\Resources\SoftwareProducts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SoftwareProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('metenzi_product_id')
                    ->searchable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('category')
                    ->searchable(),
                TextColumn::make('platform')
                    ->searchable(),
                TextColumn::make('retail_price')
                    ->money('EUR')
                    ->sortable(),
                TextColumn::make('retail_price_cents')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('gbp_price')
                    ->money('GBP')
                    ->sortable(),
                TextColumn::make('currency')
                    ->searchable(),
                TextColumn::make('stock')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('active')
                    ->boolean(),
                TextColumn::make('warranty_days')
                    ->numeric()
                    ->sortable(),
                ImageColumn::make('image_url'),
                TextColumn::make('status')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

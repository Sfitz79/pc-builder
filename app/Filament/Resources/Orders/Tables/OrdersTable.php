<?php

namespace App\Filament\Resources\Orders\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('uuid')
                    ->label('UUID')
                    ->searchable(),
                TextColumn::make('user.name')
                    ->searchable(),
                TextColumn::make('build.name')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        \App\Models\Order::STATUS_PAID => 'success',
                        \App\Models\Order::STATUS_AWAITING_PAYMENT => 'warning',
                        \App\Models\Order::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->searchable(),
                TextColumn::make('paypal_order_id')
                    ->searchable(),
                TextColumn::make('paypal_capture_id')
                    ->searchable(),
                TextColumn::make('paypal_invoice_id')
                    ->searchable(),
                TextColumn::make('currency')
                    ->searchable(),
                TextColumn::make('customer_name')
                    ->searchable(),
                TextColumn::make('customer_email')
                    ->searchable(),
                TextColumn::make('parts_total')
                    ->money('GBP')
                    ->sortable(),
                TextColumn::make('build_delivery')
                    ->money('GBP')
                    ->sortable(),
                TextColumn::make('subtotal')
                    ->money('GBP')
                    ->sortable(),
                TextColumn::make('paypal_fee')
                    ->money('GBP')
                    ->sortable(),
                TextColumn::make('total')
                    ->money('GBP')
                    ->sortable(),
                TextColumn::make('paid_at')
                    ->dateTime()
                    ->sortable(),
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

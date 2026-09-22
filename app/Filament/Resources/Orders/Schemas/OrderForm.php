<?php

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('uuid')
                    ->label('UUID')
                    ->readOnly(),
                Select::make('user_id')
                    ->relationship('user', 'name'),
                Select::make('build_id')
                    ->relationship('build', 'name'),
                Select::make('status')
                    ->options([
                        \App\Models\Order::STATUS_DRAFT => 'Draft',
                        \App\Models\Order::STATUS_AWAITING_PAYMENT => 'Awaiting payment',
                        \App\Models\Order::STATUS_PAID => 'Paid',
                        \App\Models\Order::STATUS_FAILED => 'Failed',
                    ])
                    ->required()
                    ->default(\App\Models\Order::STATUS_DRAFT),
                TextInput::make('paypal_order_id'),
                TextInput::make('paypal_capture_id'),
                TextInput::make('paypal_invoice_id'),
                TextInput::make('currency')
                    ->required()
                    ->default('GBP')
                    ->maxLength(3),
                TextInput::make('customer_name')
                    ->maxLength(255),
                TextInput::make('customer_email')
                    ->email()
                    ->maxLength(255),
                TextInput::make('parts_total')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->prefix('£'),
                TextInput::make('build_delivery')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->prefix('£'),
                TextInput::make('subtotal')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->prefix('£'),
                TextInput::make('paypal_fee')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->prefix('£'),
                TextInput::make('total')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->prefix('£'),
                DateTimePicker::make('paid_at'),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Builds\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BuildForm
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
                TextInput::make('name')
                    ->required()
                    ->default('My Build')
                    ->maxLength(255),
                TextInput::make('purpose')
                    ->maxLength(255),
                TextInput::make('resolution')
                    ->maxLength(255),
                TextInput::make('budget')
                    ->numeric()
                    ->prefix('£'),
                TextInput::make('total_price')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->prefix('£'),
                TextInput::make('performance_score')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('public')
                    ->default(false),
                TextInput::make('share_slug')
                    ->maxLength(255),
            ]);
    }
}

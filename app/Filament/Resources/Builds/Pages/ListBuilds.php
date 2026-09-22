<?php

namespace App\Filament\Resources\Builds\Pages;

use App\Filament\Resources\Builds\BuildResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBuilds extends ListRecords
{
    protected static string $resource = BuildResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

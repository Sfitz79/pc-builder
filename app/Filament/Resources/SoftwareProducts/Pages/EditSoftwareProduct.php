<?php

namespace App\Filament\Resources\SoftwareProducts\Pages;

use App\Filament\Resources\SoftwareProducts\SoftwareProductResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSoftwareProduct extends EditRecord
{
    protected static string $resource = SoftwareProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

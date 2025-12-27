<?php

declare(strict_types=1);

namespace App\Filament\Resources\FabricantProductResource\Pages;

use App\Filament\Resources\FabricantProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFabricantProduct extends EditRecord
{
    protected static string $resource = FabricantProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

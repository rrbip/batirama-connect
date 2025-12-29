<?php

declare(strict_types=1);

namespace App\Filament\Resources\FabricantCatalogResource\Pages;

use App\Filament\Resources\FabricantCatalogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFabricantCatalog extends EditRecord
{
    protected static string $resource = FabricantCatalogResource::class;

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

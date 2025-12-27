<?php

declare(strict_types=1);

namespace App\Filament\Resources\FabricantCatalogResource\Pages;

use App\Filament\Resources\FabricantCatalogResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFabricantCatalog extends CreateRecord
{
    protected static string $resource = FabricantCatalogResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

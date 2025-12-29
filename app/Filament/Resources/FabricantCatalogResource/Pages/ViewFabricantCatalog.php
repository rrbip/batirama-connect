<?php

declare(strict_types=1);

namespace App\Filament\Resources\FabricantCatalogResource\Pages;

use App\Filament\Resources\FabricantCatalogResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewFabricantCatalog extends ViewRecord
{
    protected static string $resource = FabricantCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\FabricantCatalogResource\Pages;

use App\Filament\Resources\FabricantCatalogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFabricantCatalogs extends ListRecords
{
    protected static string $resource = FabricantCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                // Masquer le bouton si le fabricant a déjà un catalogue
                ->visible(fn () => ! FabricantCatalogResource::fabricantHasCatalog()),
        ];
    }
}

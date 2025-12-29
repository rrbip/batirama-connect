<?php

declare(strict_types=1);

namespace App\Filament\Resources\FabricantProductResource\Pages;

use App\Filament\Resources\FabricantProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFabricantProducts extends ListRecords
{
    protected static string $resource = FabricantProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

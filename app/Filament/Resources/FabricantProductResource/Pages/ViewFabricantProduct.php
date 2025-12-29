<?php

declare(strict_types=1);

namespace App\Filament\Resources\FabricantProductResource\Pages;

use App\Filament\Resources\FabricantProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewFabricantProduct extends ViewRecord
{
    protected static string $resource = FabricantProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}

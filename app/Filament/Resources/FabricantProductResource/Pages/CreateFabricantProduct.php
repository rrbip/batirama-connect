<?php

declare(strict_types=1);

namespace App\Filament\Resources\FabricantProductResource\Pages;

use App\Filament\Resources\FabricantProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFabricantProduct extends CreateRecord
{
    protected static string $resource = FabricantProductResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

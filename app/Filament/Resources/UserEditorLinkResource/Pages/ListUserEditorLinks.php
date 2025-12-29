<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserEditorLinkResource\Pages;

use App\Filament\Resources\UserEditorLinkResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUserEditorLinks extends ListRecords
{
    protected static string $resource = UserEditorLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

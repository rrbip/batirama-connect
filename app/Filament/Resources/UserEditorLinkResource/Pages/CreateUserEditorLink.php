<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserEditorLinkResource\Pages;

use App\Filament\Resources\UserEditorLinkResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUserEditorLink extends CreateRecord
{
    protected static string $resource = UserEditorLinkResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserEditorLinkResource\Pages;

use App\Filament\Resources\UserEditorLinkResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUserEditorLink extends EditRecord
{
    protected static string $resource = UserEditorLinkResource::class;

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

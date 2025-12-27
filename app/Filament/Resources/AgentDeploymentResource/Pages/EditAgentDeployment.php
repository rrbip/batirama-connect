<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentDeploymentResource\Pages;

use App\Filament\Resources\AgentDeploymentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAgentDeployment extends EditRecord
{
    protected static string $resource = AgentDeploymentResource::class;

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

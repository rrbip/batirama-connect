<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentDeploymentResource\Pages;

use App\Filament\Resources\AgentDeploymentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAgentDeployment extends CreateRecord
{
    protected static string $resource = AgentDeploymentResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

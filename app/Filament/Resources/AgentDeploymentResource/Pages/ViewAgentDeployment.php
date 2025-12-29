<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentDeploymentResource\Pages;

use App\Filament\Resources\AgentDeploymentResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAgentDeployment extends ViewRecord
{
    protected static string $resource = AgentDeploymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}

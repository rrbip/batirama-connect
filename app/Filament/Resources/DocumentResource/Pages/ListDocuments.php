<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\WebCrawlResource;
use App\Jobs\RebuildAgentIndexJob;
use App\Models\Agent;
use App\Services\AI\QdrantService;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('rebuild-index')
                ->label('Réparer index Qdrant')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('warning')
                ->form([
                    Select::make('agent_id')
                        ->label('Agent')
                        ->options(Agent::whereNotNull('qdrant_collection')->pluck('name', 'id'))
                        ->required()
                        ->searchable()
                        ->helperText('La collection Qdrant sera vidée et reconstruite avec tous les chunks de cet agent.'),
                ])
                ->requiresConfirmation()
                ->modalHeading('Reconstruire l\'index Qdrant')
                ->modalDescription('Cette action va supprimer tous les points de la collection Qdrant de l\'agent et les recréer à partir des chunks en base de données. Cela peut prendre plusieurs minutes.')
                ->modalSubmitActionLabel('Reconstruire')
                ->action(function (array $data) {
                    $agent = Agent::find($data['agent_id']);

                    if (! $agent || empty($agent->qdrant_collection)) {
                        Notification::make()
                            ->title('Erreur')
                            ->body('Agent non trouvé ou sans collection Qdrant configurée.')
                            ->danger()
                            ->send();

                        return;
                    }

                    // Dispatcher le job de reconstruction
                    RebuildAgentIndexJob::dispatch($agent);

                    Notification::make()
                        ->title('Reconstruction lancée')
                        ->body("L'index Qdrant de l'agent \"{$agent->name}\" est en cours de reconstruction.")
                        ->success()
                        ->send();
                }),

            Actions\Action::make('web-crawl')
                ->label('Crawler un site')
                ->icon('heroicon-o-globe-alt')
                ->color('info')
                ->url(WebCrawlResource::getUrl('create')),

            Actions\Action::make('bulk-import')
                ->label('Import en masse')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('success')
                ->url(DocumentResource::getUrl('bulk-import')),

            Actions\CreateAction::make(),
        ];
    }
}

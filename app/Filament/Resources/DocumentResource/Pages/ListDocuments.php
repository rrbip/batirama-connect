<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\WebCrawlResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
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

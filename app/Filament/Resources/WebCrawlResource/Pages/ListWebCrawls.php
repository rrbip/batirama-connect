<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebCrawlResource\Pages;

use App\Filament\Resources\WebCrawlResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWebCrawls extends ListRecords
{
    protected static string $resource = WebCrawlResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nouveau Crawl'),
        ];
    }
}

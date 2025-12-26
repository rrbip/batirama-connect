<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebCrawlResource\Pages;

use App\Filament\Resources\WebCrawlResource;
use App\Jobs\Crawler\StartWebCrawlJob;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateWebCrawl extends CreateRecord
{
    protected static string $resource = WebCrawlResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['uuid'] = (string) Str::uuid();
        $data['status'] = 'pending';

        // Encryption des credentials si présents
        if (!empty($data['auth_username']) || !empty($data['auth_password']) || !empty($data['auth_cookies'])) {
            $credentials = [];
            if (!empty($data['auth_username'])) {
                $credentials['username'] = $data['auth_username'];
            }
            if (!empty($data['auth_password'])) {
                $credentials['password'] = $data['auth_password'];
            }
            if (!empty($data['auth_cookies'])) {
                $credentials['cookies'] = $data['auth_cookies'];
            }
            $data['auth_credentials'] = encrypt(json_encode($credentials));
        }

        // Nettoyer les champs temporaires
        unset($data['auth_username'], $data['auth_password'], $data['auth_cookies']);

        return $data;
    }

    protected function afterCreate(): void
    {
        // Dispatcher le job de démarrage du crawl
        StartWebCrawlJob::dispatch($this->record);

        Notification::make()
            ->title('Crawl démarré')
            ->body('Le crawl a été lancé et va commencer à traiter les pages.')
            ->success()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}

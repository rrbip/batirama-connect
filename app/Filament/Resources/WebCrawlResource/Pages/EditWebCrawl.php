<?php

declare(strict_types=1);

namespace App\Filament\Resources\WebCrawlResource\Pages;

use App\Filament\Resources\WebCrawlResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditWebCrawl extends EditRecord
{
    protected static string $resource = WebCrawlResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('view')
                ->label('Voir')
                ->icon('heroicon-o-eye')
                ->url(fn () => $this->getResource()::getUrl('view', ['record' => $this->record])),

            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->isCompleted()),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Déchiffrer les credentials pour l'édition
        if (!empty($this->record->auth_credentials)) {
            $credentials = $this->record->decrypted_credentials;
            if ($credentials) {
                $data['auth_username'] = $credentials['username'] ?? null;
                $data['auth_password'] = $credentials['password'] ?? null;
                $data['auth_cookies'] = $credentials['cookies'] ?? null;
            }
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
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
        } else {
            $data['auth_credentials'] = null;
        }

        // Nettoyer les champs temporaires
        unset($data['auth_username'], $data['auth_password'], $data['auth_cookies']);

        return $data;
    }

    protected function afterSave(): void
    {
        Notification::make()
            ->title('Configuration sauvegardée')
            ->body('Les paramètres du crawl ont été mis à jour.')
            ->success()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}

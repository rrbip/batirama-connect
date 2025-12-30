<?php

declare(strict_types=1);

namespace App\Filament\Resources\FabricantCatalogResource\Pages;

use App\Filament\Resources\FabricantCatalogResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateFabricantCatalog extends CreateRecord
{
    protected static string $resource = FabricantCatalogResource::class;

    public function mount(): void
    {
        // Vérifier si le fabricant a déjà un catalogue
        if (FabricantCatalogResource::fabricantHasCatalog()) {
            Notification::make()
                ->title('Catalogue existant')
                ->body('Vous avez déjà un catalogue. Vous ne pouvez créer qu\'un seul catalogue.')
                ->warning()
                ->send();

            $this->redirect(FabricantCatalogResource::getUrl('index'));

            return;
        }

        // Vérifier si le fabricant a une URL de site web configurée
        if (FabricantCatalogResource::isFabricantOnly() && ! FabricantCatalogResource::getFabricantWebsiteUrl()) {
            Notification::make()
                ->title('URL du site web manquante')
                ->body('Veuillez d\'abord configurer l\'URL de votre site web dans votre profil utilisateur (company_info.website).')
                ->danger()
                ->send();

            $this->redirect(FabricantCatalogResource::getUrl('index'));

            return;
        }

        parent::mount();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Pour les fabricants, forcer l'URL depuis le profil
        if (FabricantCatalogResource::isFabricantOnly()) {
            $data['website_url'] = FabricantCatalogResource::getFabricantWebsiteUrl();
            $data['fabricant_id'] = auth()->id();
        }

        return $data;
    }
}

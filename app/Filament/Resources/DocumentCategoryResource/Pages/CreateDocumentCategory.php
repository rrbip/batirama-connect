<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentCategoryResource\Pages;

use App\Filament\Resources\DocumentCategoryResource;
use App\Models\DocumentCategory;
use Filament\Resources\Pages\CreateRecord;

class CreateDocumentCategory extends CreateRecord
{
    protected static string $resource = DocumentCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Générer une couleur aléatoire si non définie
        if (empty($data['color'])) {
            $data['color'] = DocumentCategory::getRandomColor();
        }

        $data['is_ai_generated'] = false;

        return $data;
    }
}

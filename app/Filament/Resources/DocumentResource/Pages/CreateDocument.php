<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['uuid'] = (string) Str::uuid();
        $data['uploaded_by'] = auth()->id();
        $data['extraction_status'] = 'pending';

        // Extraire le nom original et le type du fichier uploadé
        if (isset($data['storage_path'])) {
            $data['original_name'] = basename($data['storage_path']);
            $extension = pathinfo($data['original_name'], PATHINFO_EXTENSION);
            $data['document_type'] = strtolower($extension);
            $data['mime_type'] = mime_content_type(storage_path('app/' . $data['storage_path'])) ?? 'application/octet-stream';
            $data['file_size'] = filesize(storage_path('app/' . $data['storage_path'])) ?? 0;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reprocess')
                ->label('Retraiter')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => $this->record->extraction_status === 'failed')
                ->action(function () {
                    $this->record->update([
                        'extraction_status' => 'pending',
                        'extraction_error' => null,
                    ]);
                }),

            Actions\DeleteAction::make(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentResource\Pages;

use App\Filament\Resources\AgentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAgent extends EditRecord
{
    protected static string $resource = AgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('test')
                ->label('Tester')
                ->icon('heroicon-o-play')
                ->color('success')
                ->url(fn () => $this->getResource()::getUrl('test', ['record' => $this->record])),

            Actions\DeleteAction::make(),
        ];
    }
}

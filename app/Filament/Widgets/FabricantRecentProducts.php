<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\FabricantProductResource;
use App\Models\FabricantCatalog;
use App\Models\FabricantProduct;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class FabricantRecentProducts extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Produits récents';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user && $user->hasRole('fabricant') && ! $user->hasRole('super-admin') && ! $user->hasRole('admin');
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $catalogIds = FabricantCatalog::where('fabricant_id', $user->id)->pluck('id');

        return $table
            ->query(
                FabricantProduct::query()
                    ->whereIn('catalog_id', $catalogIds)
                    ->with('catalog')
                    ->latest('created_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Produit')
                    ->limit(40)
                    ->searchable(),

                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('catalog.name')
                    ->label('Catalogue')
                    ->limit(20),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'active' => 'Actif',
                        'inactive' => 'Inactif',
                        'pending_review' => 'En attente',
                        'archived' => 'Archivé',
                        default => $state ?? '-',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        'pending_review' => 'warning',
                        'archived' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('price_ht')
                    ->label('Prix HT')
                    ->money('EUR')
                    ->alignRight(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Voir')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => FabricantProductResource::getUrl('edit', ['record' => $record->id])),
            ])
            ->paginated([5, 10])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Aucun produit')
            ->emptyStateDescription('Vous n\'avez pas encore de produits dans vos catalogues.')
            ->emptyStateIcon('heroicon-o-cube');
    }
}

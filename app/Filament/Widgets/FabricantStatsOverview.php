<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\FabricantCatalog;
use App\Models\FabricantProduct;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FabricantStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user && $user->hasRole('fabricant') && ! $user->hasRole('super-admin') && ! $user->hasRole('admin');
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        // Get fabricant's catalogs
        $catalogIds = FabricantCatalog::where('fabricant_id', $user->id)->pluck('id');

        $catalogCount = $catalogIds->count();
        $productCount = FabricantProduct::whereIn('catalog_id', $catalogIds)->count();
        $activeProductCount = FabricantProduct::whereIn('catalog_id', $catalogIds)
            ->where('status', 'active')
            ->count();
        $pendingReviewCount = FabricantProduct::whereIn('catalog_id', $catalogIds)
            ->where('status', 'pending_review')
            ->count();

        return [
            Stat::make('Catalogues', $catalogCount)
                ->description('Vos catalogues produits')
                ->descriptionIcon('heroicon-m-folder')
                ->color('primary'),

            Stat::make('Produits', $productCount)
                ->description($activeProductCount . ' actifs')
                ->descriptionIcon('heroicon-m-cube')
                ->color('success'),

            Stat::make('En attente', $pendingReviewCount)
                ->description('Produits à valider')
                ->descriptionIcon('heroicon-m-clock')
                ->color($pendingReviewCount > 0 ? 'warning' : 'gray'),

            Stat::make('Ventes', '-')
                ->description('Bientôt disponible')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('gray'),
        ];
    }
}

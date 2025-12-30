<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\FabricantCatalog;
use Filament\Widgets\ChartWidget;

class FabricantProductsChart extends ChartWidget
{
    protected static ?string $heading = 'Produits par catalogue';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'half';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user && $user->hasRole('fabricant') && ! $user->hasRole('super-admin') && ! $user->hasRole('admin');
    }

    protected function getData(): array
    {
        $user = auth()->user();

        $catalogs = FabricantCatalog::withCount('products')
            ->where('fabricant_id', $user->id)
            ->orderByDesc('products_count')
            ->limit(5)
            ->get();

        if ($catalogs->isEmpty()) {
            return [
                'datasets' => [
                    [
                        'data' => [1],
                        'backgroundColor' => ['rgba(156, 163, 175, 0.5)'],
                    ],
                ],
                'labels' => ['Aucun catalogue'],
            ];
        }

        $colors = [
            'rgba(59, 130, 246, 0.8)',   // blue
            'rgba(16, 185, 129, 0.8)',   // green
            'rgba(245, 158, 11, 0.8)',   // amber
            'rgba(239, 68, 68, 0.8)',    // red
            'rgba(139, 92, 246, 0.8)',   // purple
        ];

        return [
            'datasets' => [
                [
                    'data' => $catalogs->pluck('products_count')->toArray(),
                    'backgroundColor' => array_slice($colors, 0, $catalogs->count()),
                ],
            ],
            'labels' => $catalogs->pluck('name')->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'right',
                ],
            ],
        ];
    }
}

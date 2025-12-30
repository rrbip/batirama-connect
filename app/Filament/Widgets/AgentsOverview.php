<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Agent;
use App\Models\Document;
use Filament\Widgets\ChartWidget;

class AgentsOverview extends ChartWidget
{
    protected static ?string $heading = 'Documents par agent';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'half';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRole('super-admin') || $user->hasRole('admin'));
    }

    protected function getData(): array
    {
        $agents = Agent::withCount('documents')
            ->where('is_active', true)
            ->orderByDesc('documents_count')
            ->limit(5)
            ->get();

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
                    'data' => $agents->pluck('documents_count')->toArray(),
                    'backgroundColor' => array_slice($colors, 0, $agents->count()),
                ],
            ],
            'labels' => $agents->pluck('name')->toArray(),
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

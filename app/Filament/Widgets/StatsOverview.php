<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\AiSession;
use App\Models\Agent;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRole('super-admin') || $user->hasRole('admin'));
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Utilisateurs', User::count())
                ->description('Total des utilisateurs')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->chart($this->getUsersChart()),

            Stat::make('Agents IA', Agent::where('is_active', true)->count())
                ->description('Agents actifs')
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color('success'),

            Stat::make('Sessions IA', AiSession::whereDate('created_at', today())->count())
                ->description('Sessions aujourd\'hui')
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color('info'),

            Stat::make('Messages', $this->getTotalMessagesToday())
                ->description('Messages aujourd\'hui')
                ->descriptionIcon('heroicon-m-envelope')
                ->color('warning'),
        ];
    }

    protected function getUsersChart(): array
    {
        // Nombre d'utilisateurs créés par jour sur les 7 derniers jours
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $data[] = User::whereDate('created_at', $date)->count();
        }

        return $data;
    }

    protected function getTotalMessagesToday(): int
    {
        // Vérifier si la table ai_messages existe
        try {
            return \DB::table('ai_messages')
                ->whereDate('created_at', today())
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }
}

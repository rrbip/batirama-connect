<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\AuditLog;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentActivity extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Activité récente';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRole('super-admin') || $user->hasRole('admin'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AuditLog::query()
                    ->latest('created_at')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'create' => 'Création',
                        'update' => 'Modification',
                        'delete' => 'Suppression',
                        'login' => 'Connexion',
                        'logout' => 'Déconnexion',
                        'export' => 'Export',
                        default => $state ?? '-',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'create' => 'success',
                        'update' => 'warning',
                        'delete' => 'danger',
                        'login' => 'info',
                        'logout' => 'gray',
                        'export' => 'primary',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'App\\Models\\User' => 'Utilisateur',
                        'App\\Models\\Agent' => 'Agent IA',
                        'App\\Models\\Document' => 'Document',
                        'App\\Models\\AiSession' => 'Session IA',
                        'App\\Models\\Tenant' => 'Organisation',
                        default => $state ? class_basename($state) : 'Système',
                    }),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Utilisateur')
                    ->default(fn (AuditLog $record) => $record->user_email ?? 'Système'),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP'),
            ])
            ->paginated(false);
    }
}

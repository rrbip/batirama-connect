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
                    ->formatStateUsing(fn (AuditLog $record) => $record->getActionLabel())
                    ->color(fn (AuditLog $record) => $record->getActionColor()),

                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('Type')
                    ->formatStateUsing(fn (AuditLog $record) => $record->getAuditableLabel()),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Utilisateur')
                    ->default(fn (AuditLog $record) => $record->user_email ?? 'Système'),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP'),
            ])
            ->paginated(false);
    }
}

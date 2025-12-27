<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\WebCrawlResource\Pages;
use App\Models\WebCrawl;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WebCrawlResource extends Resource
{
    protected static ?string $model = WebCrawl::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Crawler Web';

    protected static ?string $modelLabel = 'Crawl Web';

    protected static ?string $pluralModelLabel = 'Crawls Web';

    protected static ?string $navigationGroup = 'Intelligence Artificielle';

    protected static ?int $navigationSort = 35;

    // Masqué - accessible via Gestion RAG
    protected static bool $shouldRegisterNavigation = false;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Crawler')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Configuration')
                            ->icon('heroicon-o-cog-6-tooth')
                            ->schema([
                                Forms\Components\Section::make('Site à crawler')
                                    ->description('Configuration du cache. Les agents sont ajoutés après création.')
                                    ->schema([
                                        Forms\Components\TextInput::make('start_url')
                                            ->label('URL de départ')
                                            ->url()
                                            ->required()
                                            ->placeholder('https://example.com')
                                            ->helperText('Page d\'accueil du site à crawler')
                                            ->columnSpanFull(),

                                        Forms\Components\Textarea::make('allowed_domains')
                                            ->label('Domaines autorisés')
                                            ->placeholder("example.com\nwww.example.com")
                                            ->helperText('Un domaine par ligne. Vide = même domaine que l\'URL de départ')
                                            ->rows(3)
                                            ->dehydrateStateUsing(fn ($state) => $state ? array_filter(array_map('trim', explode("\n", $state))) : [])
                                            ->formatStateUsing(fn ($state) => is_array($state) ? implode("\n", $state) : $state),
                                    ])
                                    ->columns(1),

                                Forms\Components\Section::make('Limites')
                                    ->schema([
                                        Forms\Components\Select::make('max_depth')
                                            ->label('Profondeur max')
                                            ->options([
                                                1 => '1 niveau',
                                                2 => '2 niveaux',
                                                3 => '3 niveaux',
                                                5 => '5 niveaux',
                                                10 => '10 niveaux',
                                                99 => 'Illimité',
                                            ])
                                            ->default(5),

                                        Forms\Components\Select::make('max_pages')
                                            ->label('Limite de pages')
                                            ->options([
                                                0 => '🚀 Illimité',
                                                500 => '500 pages',
                                                1000 => '1 000 pages',
                                                5000 => '5 000 pages',
                                                10000 => '10 000 pages',
                                                50000 => '50 000 pages',
                                                100000 => '100 000 pages',
                                            ])
                                            ->default(0),

                                        Forms\Components\TextInput::make('max_disk_mb')
                                            ->label('Limite disque (Mo)')
                                            ->numeric()
                                            ->placeholder('Illimité')
                                            ->helperText('Espace disque max. Vide = illimité'),

                                        Forms\Components\TextInput::make('delay_ms')
                                            ->label('Délai entre requêtes (ms)')
                                            ->numeric()
                                            ->default(500)
                                            ->minValue(100)
                                            ->helperText('Minimum 100ms'),
                                    ])
                                    ->columns(4),
                            ]),

                        Forms\Components\Tabs\Tab::make('Authentification')
                            ->icon('heroicon-o-key')
                            ->schema([
                                Forms\Components\Section::make('Authentification HTTP')
                                    ->schema([
                                        Forms\Components\Select::make('auth_type')
                                            ->label('Type d\'authentification')
                                            ->options([
                                                'none' => 'Aucune',
                                                'basic' => 'Basic Auth',
                                                'cookies' => 'Cookies',
                                            ])
                                            ->default('none')
                                            ->live(),

                                        Forms\Components\TextInput::make('auth_username')
                                            ->label('Nom d\'utilisateur')
                                            ->visible(fn ($get) => $get('auth_type') === 'basic'),

                                        Forms\Components\TextInput::make('auth_password')
                                            ->label('Mot de passe')
                                            ->password()
                                            ->visible(fn ($get) => $get('auth_type') === 'basic'),

                                        Forms\Components\Textarea::make('auth_cookies')
                                            ->label('Cookies')
                                            ->placeholder('session_id=abc123; auth_token=xyz789')
                                            ->visible(fn ($get) => $get('auth_type') === 'cookies')
                                            ->helperText('Format: nom=valeur; nom2=valeur2'),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Options avancées')
                                    ->schema([
                                        Forms\Components\Toggle::make('respect_robots_txt')
                                            ->label('Respecter robots.txt')
                                            ->default(true)
                                            ->helperText('Recommandé pour être un bon citoyen du web'),

                                        Forms\Components\Toggle::make('use_browser')
                                            ->label('Mode navigateur (FlareSolverr)')
                                            ->default(false)
                                            ->helperText('Utiliser un navigateur headless pour bypass Cloudflare'),

                                        Forms\Components\TextInput::make('user_agent')
                                            ->label('User-Agent')
                                            ->default(WebCrawl::DEFAULT_USER_AGENT)
                                            ->placeholder(WebCrawl::DEFAULT_USER_AGENT)
                                            ->helperText('Laissez vide pour le Chrome par défaut'),
                                    ])
                                    ->columns(3),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('start_url')
                    ->label('URL')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->start_url)
                    ->searchable(),

                Tables\Columns\TextColumn::make('agents_count')
                    ->label('Agents')
                    ->counts('agents')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Statut')
                    ->colors([
                        'secondary' => 'pending',
                        'warning' => 'running',
                        'info' => 'paused',
                        'success' => 'completed',
                        'danger' => fn ($state) => in_array($state, ['failed', 'cancelled']),
                    ])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending' => 'En attente',
                        'running' => 'En cours',
                        'paused' => 'Pausé',
                        'completed' => 'Terminé',
                        'failed' => 'Échoué',
                        'cancelled' => 'Annulé',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('progress')
                    ->label('Progression')
                    ->state(fn ($record) => "{$record->pages_crawled}/{$record->pages_discovered}")
                    ->description(fn ($record) => $record->progress_percent . '%'),

                Tables\Columns\TextColumn::make('total_pages_indexed')
                    ->label('Indexées')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_size_for_humans')
                    ->label('Taille'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'pending' => 'En attente',
                        'running' => 'En cours',
                        'paused' => 'Pausé',
                        'completed' => 'Terminé',
                        'failed' => 'Échoué',
                        'cancelled' => 'Annulé',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Détails')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => static::getUrl('view', ['record' => $record])),

                Tables\Actions\Action::make('edit')
                    ->label('Modifier')
                    ->icon('heroicon-o-pencil')
                    ->url(fn ($record) => static::getUrl('edit', ['record' => $record])),

                Tables\Actions\Action::make('pause')
                    ->label('Pause')
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->visible(fn ($record) => $record->status === 'running')
                    ->action(function ($record) {
                        $record->update([
                            'status' => 'paused',
                            'paused_at' => now(),
                        ]);
                        Notification::make()->title('Crawl mis en pause')->success()->send();
                    }),

                Tables\Actions\Action::make('resume')
                    ->label('Reprendre')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === 'paused')
                    ->action(function ($record) {
                        $record->update([
                            'status' => 'running',
                            'paused_at' => null,
                        ]);
                        Notification::make()->title('Crawl repris')->success()->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn ($record) => $record->isCompleted()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebCrawls::route('/'),
            'create' => Pages\CreateWebCrawl::route('/create'),
            'view' => Pages\ViewWebCrawl::route('/{record}'),
            'edit' => Pages\EditWebCrawl::route('/{record}/edit'),
        ];
    }
}

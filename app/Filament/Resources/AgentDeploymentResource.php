<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AgentDeploymentResource\Pages;
use App\Models\AgentDeployment;
use App\Models\Role;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AgentDeploymentResource extends Resource
{
    protected static ?string $model = AgentDeployment::class;

    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $navigationGroup = 'Marketplace';

    protected static ?string $modelLabel = 'Déploiement';

    protected static ?string $pluralModelLabel = 'Déploiements';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRole('super-admin') || $user->hasRole('admin'));
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Deployment')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Informations')
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                Forms\Components\Section::make('Identité')
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Nom du déploiement')
                                            ->required()
                                            ->maxLength(100)
                                            ->placeholder('Déploiement EBP Production')
                                            ->helperText('Nom descriptif pour identifier ce déploiement'),

                                        Forms\Components\Select::make('agent_id')
                                            ->label('Agent IA')
                                            ->relationship('agent', 'name', fn (Builder $query) =>
                                                $query->where('is_whitelabel_enabled', true)
                                            )
                                            ->required()
                                            ->searchable()
                                            ->preload()
                                            ->helperText('Seuls les agents avec whitelabel activé sont listés'),

                                        Forms\Components\Select::make('editor_id')
                                            ->label('Éditeur')
                                            ->relationship('editor', 'name', function (Builder $query) {
                                                $editeurRole = Role::where('slug', 'editeur')->first();
                                                if ($editeurRole) {
                                                    $query->whereHas('roles', fn ($q) => $q->where('role_id', $editeurRole->id));
                                                }
                                            })
                                            ->required()
                                            ->searchable()
                                            ->preload()
                                            ->helperText('Utilisateur avec le rôle Éditeur'),

                                        Forms\Components\Select::make('deployment_mode')
                                            ->label('Mode de déploiement')
                                            ->options([
                                                'shared' => 'Partagé (même RAG pour tous)',
                                                'dedicated' => 'Dédié (collection RAG personnalisée)',
                                            ])
                                            ->default('shared')
                                            ->required()
                                            ->live()
                                            ->helperText('Shared = même base de connaissances, Dedicated = base séparée'),

                                        Forms\Components\TextInput::make('dedicated_collection')
                                            ->label('Collection Qdrant dédiée')
                                            ->maxLength(100)
                                            ->placeholder('ebp_documents')
                                            ->visible(fn (callable $get) => $get('deployment_mode') === 'dedicated')
                                            ->helperText('Nom de la collection Qdrant pour ce déploiement'),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Clé API')
                                    ->schema([
                                        Forms\Components\TextInput::make('deployment_key')
                                            ->label('Clé de déploiement')
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->helperText('Générée automatiquement à la création'),

                                        Forms\Components\Placeholder::make('uuid_display')
                                            ->label('UUID')
                                            ->content(fn ($record) => $record?->uuid ?? 'Généré à la création'),
                                    ])
                                    ->columns(2)
                                    ->visible(fn ($record) => $record !== null),

                                Forms\Components\Section::make('Statut')
                                    ->schema([
                                        Forms\Components\Toggle::make('is_active')
                                            ->label('Actif')
                                            ->default(true)
                                            ->helperText('Désactiver pour bloquer les nouvelles sessions'),
                                    ]),
                            ]),

                        Forms\Components\Tabs\Tab::make('Domaines autorisés')
                            ->icon('heroicon-o-globe-alt')
                            ->schema([
                                Forms\Components\Section::make('Domaines')
                                    ->description('Liste des domaines autorisés à utiliser ce déploiement. Utilisez * pour les wildcards (ex: *.ebp.com).')
                                    ->schema([
                                        Forms\Components\Repeater::make('allowedDomains')
                                            ->relationship()
                                            ->label('')
                                            ->schema([
                                                Forms\Components\TextInput::make('domain')
                                                    ->label('Domaine')
                                                    ->required()
                                                    ->maxLength(255)
                                                    ->placeholder('app.ebp.com ou *.ebp.com'),

                                                Forms\Components\Toggle::make('is_active')
                                                    ->label('Actif')
                                                    ->default(true)
                                                    ->inline(false),
                                            ])
                                            ->columns(2)
                                            ->addActionLabel('Ajouter un domaine')
                                            ->defaultItems(1)
                                            ->collapsible()
                                            ->itemLabel(fn (array $state): ?string => $state['domain'] ?? null),
                                    ]),
                            ]),

                        Forms\Components\Tabs\Tab::make('Quotas & Limites')
                            ->icon('heroicon-o-chart-bar')
                            ->schema([
                                Forms\Components\Section::make('Quotas journaliers')
                                    ->schema([
                                        Forms\Components\TextInput::make('max_sessions_day')
                                            ->label('Max sessions/jour')
                                            ->numeric()
                                            ->placeholder('Illimité')
                                            ->helperText('Laisser vide pour illimité'),

                                        Forms\Components\TextInput::make('max_messages_day')
                                            ->label('Max messages/jour')
                                            ->numeric()
                                            ->placeholder('Illimité')
                                            ->helperText('Laisser vide pour illimité'),

                                        Forms\Components\TextInput::make('rate_limit_per_ip')
                                            ->label('Rate limit par IP (req/min)')
                                            ->numeric()
                                            ->default(60)
                                            ->required()
                                            ->helperText('Nombre de requêtes par minute par IP'),
                                    ])
                                    ->columns(3),

                                Forms\Components\Section::make('Statistiques')
                                    ->schema([
                                        Forms\Components\Placeholder::make('sessions_count_display')
                                            ->label('Total sessions')
                                            ->content(fn ($record) => $record?->sessions_count ?? 0),

                                        Forms\Components\Placeholder::make('messages_count_display')
                                            ->label('Total messages')
                                            ->content(fn ($record) => $record?->messages_count ?? 0),

                                        Forms\Components\Placeholder::make('last_activity_display')
                                            ->label('Dernière activité')
                                            ->content(fn ($record) => $record?->last_activity_at?->format('d/m/Y H:i') ?? 'Jamais'),
                                    ])
                                    ->columns(3)
                                    ->visible(fn ($record) => $record !== null),
                            ]),

                        Forms\Components\Tabs\Tab::make('Branding')
                            ->icon('heroicon-o-paint-brush')
                            ->schema([
                                Forms\Components\Section::make('Personnalisation visuelle')
                                    ->description('Ces paramètres remplacent le branding par défaut de l\'agent')
                                    ->schema([
                                        Forms\Components\TextInput::make('branding.chat_title')
                                            ->label('Titre du chat')
                                            ->maxLength(100)
                                            ->placeholder('Assistant EBP'),

                                        Forms\Components\Textarea::make('branding.welcome_message')
                                            ->label('Message de bienvenue')
                                            ->rows(3)
                                            ->placeholder('Bonjour, je suis votre assistant EBP...'),

                                        Forms\Components\ColorPicker::make('branding.primary_color')
                                            ->label('Couleur principale'),

                                        Forms\Components\TextInput::make('branding.logo_url')
                                            ->label('URL du logo')
                                            ->url()
                                            ->maxLength(500)
                                            ->placeholder('https://ebp.com/logo.png'),

                                        Forms\Components\TextInput::make('branding.signature')
                                            ->label('Signature')
                                            ->maxLength(100)
                                            ->placeholder('Powered by EBP'),
                                    ])
                                    ->columns(2),
                            ]),

                        Forms\Components\Tabs\Tab::make('Configuration avancée')
                            ->icon('heroicon-o-cog-6-tooth')
                            ->schema([
                                Forms\Components\Section::make('Override modèle & paramètres')
                                    ->description('Ces paramètres remplacent la configuration de l\'agent')
                                    ->schema([
                                        Forms\Components\TextInput::make('config_overlay.temperature')
                                            ->label('Température')
                                            ->numeric()
                                            ->step(0.1)
                                            ->minValue(0)
                                            ->maxValue(2)
                                            ->placeholder('Utiliser la valeur de l\'agent'),

                                        Forms\Components\TextInput::make('config_overlay.max_tokens')
                                            ->label('Max tokens réponse')
                                            ->numeric()
                                            ->placeholder('Utiliser la valeur de l\'agent'),

                                        Forms\Components\Textarea::make('config_overlay.system_prompt_append')
                                            ->label('Texte à ajouter au system prompt')
                                            ->rows(3)
                                            ->placeholder('Instructions supplémentaires...')
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Override Ollama - Chat')
                                    ->description('Serveur Ollama dédié pour ce déploiement (vide = config agent)')
                                    ->schema([
                                        Forms\Components\TextInput::make('config_overlay.chat_ollama_host')
                                            ->label('Host')
                                            ->placeholder('ollama'),

                                        Forms\Components\TextInput::make('config_overlay.chat_ollama_port')
                                            ->label('Port')
                                            ->numeric()
                                            ->placeholder('11434'),

                                        Forms\Components\TextInput::make('config_overlay.chat_model')
                                            ->label('Modèle')
                                            ->placeholder('llama3.2'),
                                    ])
                                    ->columns(3)
                                    ->collapsible()
                                    ->collapsed(),

                                Forms\Components\Section::make('Override Ollama - Vision')
                                    ->description('Serveur Ollama pour l\'extraction Vision (vide = config agent)')
                                    ->schema([
                                        Forms\Components\TextInput::make('config_overlay.vision_ollama_host')
                                            ->label('Host')
                                            ->placeholder('ollama-vision'),

                                        Forms\Components\TextInput::make('config_overlay.vision_ollama_port')
                                            ->label('Port')
                                            ->numeric()
                                            ->placeholder('11434'),

                                        Forms\Components\Select::make('config_overlay.vision_model')
                                            ->label('Modèle Vision')
                                            ->options(\App\Models\VisionSetting::getModelOptions())
                                            ->placeholder('Config agent'),
                                    ])
                                    ->columns(3)
                                    ->collapsible()
                                    ->collapsed(),

                                Forms\Components\Section::make('Override Ollama - Chunking')
                                    ->description('Serveur Ollama pour le chunking LLM (vide = config agent)')
                                    ->schema([
                                        Forms\Components\TextInput::make('config_overlay.chunking_ollama_host')
                                            ->label('Host')
                                            ->placeholder('ollama-chunk'),

                                        Forms\Components\TextInput::make('config_overlay.chunking_ollama_port')
                                            ->label('Port')
                                            ->numeric()
                                            ->placeholder('11434'),

                                        Forms\Components\TextInput::make('config_overlay.chunking_model')
                                            ->label('Modèle')
                                            ->placeholder('mistral'),
                                    ])
                                    ->columns(3)
                                    ->collapsible()
                                    ->collapsed(),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('agent.name')
                    ->label('Agent')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('editor.name')
                    ->label('Éditeur')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('deployment_mode')
                    ->label('Mode')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'shared' => 'Partagé',
                        'dedicated' => 'Dédié',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'shared' => 'success',
                        'dedicated' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('allowedDomains_count')
                    ->label('Domaines')
                    ->counts('allowedDomains')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('sessions_count')
                    ->label('Sessions')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label('Dernière activité')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('agent')
                    ->relationship('agent', 'name')
                    ->label('Agent'),

                Tables\Filters\SelectFilter::make('editor')
                    ->relationship('editor', 'name')
                    ->label('Éditeur'),

                Tables\Filters\SelectFilter::make('deployment_mode')
                    ->label('Mode')
                    ->options([
                        'shared' => 'Partagé',
                        'dedicated' => 'Dédié',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Actif'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('copyKey')
                    ->label('Copier la clé')
                    ->icon('heroicon-o-clipboard')
                    ->action(function (AgentDeployment $record) {
                        Notification::make()
                            ->title('Clé copiée')
                            ->body('deployment_key: ' . $record->deployment_key)
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('regenerateKey')
                    ->label('Regénérer clé')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Attention : cette action invalidera l\'ancienne clé. Les intégrations existantes cesseront de fonctionner.')
                    ->action(function (AgentDeployment $record) {
                        $record->update([
                            'deployment_key' => $record->generateDeploymentKey(),
                        ]);
                        Notification::make()
                            ->title('Clé regénérée')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),

                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activer')
                        ->icon('heroicon-o-check')
                        ->action(fn ($records) => $records->each->update(['is_active' => true])),

                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Désactiver')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->action(fn ($records) => $records->each->update(['is_active' => false])),
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
            'index' => Pages\ListAgentDeployments::route('/'),
            'create' => Pages\CreateAgentDeployment::route('/create'),
            'view' => Pages\ViewAgentDeployment::route('/{record}'),
            'edit' => Pages\EditAgentDeployment::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('is_active', true)->count();
    }
}

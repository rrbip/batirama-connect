<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\IndexingMethod;
use App\Filament\Resources\AgentResource\Pages;
use App\Models\Agent;
use App\Services\AgentResetService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class AgentResource extends Resource
{
    protected static ?string $model = Agent::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = 'Intelligence Artificielle';

    protected static ?string $modelLabel = 'Agent IA';

    protected static ?string $pluralModelLabel = 'Agents IA';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRole('super-admin') || $user->hasRole('admin'));
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Agent')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Informations')
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                Forms\Components\Section::make('Identité')
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Nom')
                                            ->required()
                                            ->maxLength(100)
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn ($state, callable $set) =>
                                                $set('slug', Str::slug($state))
                                            ),

                                        Forms\Components\TextInput::make('slug')
                                            ->label('Slug')
                                            ->required()
                                            ->maxLength(100)
                                            ->unique(ignoreRecord: true),

                                        Forms\Components\Textarea::make('description')
                                            ->label('Description')
                                            ->rows(3)
                                            ->columnSpanFull(),

                                        Forms\Components\TextInput::make('icon')
                                            ->label('Icone')
                                            ->placeholder('heroicon-o-cpu-chip')
                                            ->helperText('Nom de l\'icone Heroicon'),

                                        Forms\Components\ColorPicker::make('color')
                                            ->label('Couleur'),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Statut')
                                    ->schema([
                                        Forms\Components\Toggle::make('is_active')
                                            ->label('Actif')
                                            ->default(true)
                                            ->helperText('Désactiver pour mettre l\'agent hors ligne'),

                                        Forms\Components\Toggle::make('allow_public_access')
                                            ->label('Accès public')
                                            ->helperText('Permettre l\'accès via des liens publics'),

                                        Forms\Components\Toggle::make('allow_attachments')
                                            ->label('Pièces jointes')
                                            ->helperText('Autoriser l\'envoi de fichiers'),

                                        Forms\Components\TextInput::make('default_token_expiry_hours')
                                            ->label('Expiration tokens (heures)')
                                            ->numeric()
                                            ->default(24),
                                    ])
                                    ->columns(4),
                            ]),

                        Forms\Components\Tabs\Tab::make('Configuration IA')
                            ->icon('heroicon-o-cog-6-tooth')
                            ->schema([
                                Forms\Components\Section::make('Ollama - Chat')
                                    ->description('Serveur Ollama pour les conversations (vide = config globale)')
                                    ->schema([
                                        Forms\Components\TextInput::make('model')
                                            ->label('Modèle')
                                            ->placeholder('llama3.2')
                                            ->helperText('Modèle Ollama à utiliser'),

                                        Forms\Components\TextInput::make('fallback_model')
                                            ->label('Modèle de secours')
                                            ->placeholder('mistral'),

                                        Forms\Components\TextInput::make('ollama_host')
                                            ->label('Host')
                                            ->placeholder('ollama'),

                                        Forms\Components\TextInput::make('ollama_port')
                                            ->label('Port')
                                            ->numeric()
                                            ->placeholder('11434'),
                                    ])
                                    ->columns(4)
                                    ->collapsible(),

                                Forms\Components\Section::make('Ollama - Vision (extraction PDF)')
                                    ->description('Serveur Ollama pour l\'extraction de texte par vision (vide = config globale)')
                                    ->schema([
                                        Forms\Components\TextInput::make('vision_ollama_host')
                                            ->label('Host')
                                            ->placeholder('ollama-vision'),

                                        Forms\Components\TextInput::make('vision_ollama_port')
                                            ->label('Port')
                                            ->numeric()
                                            ->placeholder('11434'),

                                        Forms\Components\Select::make('vision_model')
                                            ->label('Modèle Vision')
                                            ->options(\App\Models\VisionSetting::getModelOptions())
                                            ->placeholder('Utiliser config globale'),
                                    ])
                                    ->columns(3)
                                    ->collapsible()
                                    ->collapsed(),

                                Forms\Components\Section::make('Ollama - Chunking LLM')
                                    ->description('Serveur Ollama pour le découpage sémantique des documents (vide = config globale)')
                                    ->schema([
                                        Forms\Components\TextInput::make('chunking_ollama_host')
                                            ->label('Host')
                                            ->placeholder('ollama-chunk'),

                                        Forms\Components\TextInput::make('chunking_ollama_port')
                                            ->label('Port')
                                            ->numeric()
                                            ->placeholder('11434'),

                                        Forms\Components\TextInput::make('chunking_model')
                                            ->label('Modèle')
                                            ->placeholder('mistral')
                                            ->helperText('Vide = modèle chat de l\'agent'),
                                    ])
                                    ->columns(3)
                                    ->collapsible()
                                    ->collapsed(),

                                Forms\Components\Section::make('Paramètres de génération')
                                    ->schema([
                                        Forms\Components\TextInput::make('temperature')
                                            ->label('Température')
                                            ->numeric()
                                            ->step(0.1)
                                            ->minValue(0)
                                            ->maxValue(2)
                                            ->default(0.7)
                                            ->helperText('0 = déterministe, 2 = créatif'),

                                        Forms\Components\TextInput::make('max_tokens')
                                            ->label('Max tokens réponse')
                                            ->numeric()
                                            ->default(2048),

                                        Forms\Components\TextInput::make('context_window_size')
                                            ->label('Fenêtre de contexte')
                                            ->numeric()
                                            ->default(4096),

                                        Forms\Components\Select::make('response_format')
                                            ->label('Format de réponse')
                                            ->options([
                                                'text' => 'Texte libre',
                                                'json' => 'JSON structuré',
                                                'markdown' => 'Markdown',
                                            ])
                                            ->default('text'),
                                    ])
                                    ->columns(2),
                            ]),

                        Forms\Components\Tabs\Tab::make('RAG & Retrieval')
                            ->icon('heroicon-o-magnifying-glass')
                            ->schema([
                                Forms\Components\Section::make('Configuration RAG')
                                    ->schema([
                                        Forms\Components\Select::make('retrieval_mode')
                                            ->label('Mode de récupération')
                                            ->options([
                                                'VECTOR_ONLY' => 'Vecteurs uniquement',
                                                'SQL_HYDRATION' => 'Hydratation SQL',
                                                'HYBRID' => 'Hybride',
                                            ])
                                            ->default('VECTOR_ONLY'),

                                        Forms\Components\Select::make('indexing_method')
                                            ->label('Méthode d\'indexation')
                                            ->options(collect(IndexingMethod::cases())->mapWithKeys(fn ($m) => [
                                                $m->value => $m->label(),
                                            ]))
                                            ->default('qr_atomique')
                                            ->helperText(fn ($state) => IndexingMethod::tryFrom($state ?? 'qr_atomique')?->description() ?? '')
                                            ->disabled()
                                            ->dehydrated(),

                                        Forms\Components\TextInput::make('qdrant_collection')
                                            ->label('Collection Qdrant')
                                            ->placeholder('agent_documents'),

                                        Forms\Components\TextInput::make('max_rag_results')
                                            ->label('Max résultats RAG')
                                            ->numeric()
                                            ->default(5),

                                        Forms\Components\TextInput::make('min_rag_score')
                                            ->label('Score minimum RAG')
                                            ->numeric()
                                            ->step(0.05)
                                            ->minValue(0)
                                            ->maxValue(1)
                                            ->placeholder('0.5')
                                            ->helperText('0.5 = permissif, 0.8 = strict'),

                                        Forms\Components\Toggle::make('allow_iterative_search')
                                            ->label('Recherche itérative')
                                            ->helperText('Permet plusieurs requêtes de recherche'),

                                        Forms\Components\Toggle::make('use_category_filtering')
                                            ->label('Filtrage par catégorie')
                                            ->helperText('Détecte la catégorie de la question pour filtrer les résultats RAG. Améliore la précision quand les chunks ont des catégories.'),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Réponses apprises')
                                    ->description('Configuration du système d\'apprentissage continu')
                                    ->schema([
                                        Forms\Components\TextInput::make('max_learned_responses')
                                            ->label('Max réponses apprises')
                                            ->numeric()
                                            ->placeholder('3')
                                            ->helperText('Nombre de cas similaires à inclure'),

                                        Forms\Components\TextInput::make('learned_min_score')
                                            ->label('Score minimum')
                                            ->numeric()
                                            ->step(0.05)
                                            ->minValue(0)
                                            ->maxValue(1)
                                            ->placeholder('0.75')
                                            ->helperText('Score minimum pour les réponses apprises'),

                                        Forms\Components\TextInput::make('context_token_limit')
                                            ->label('Limite tokens contexte')
                                            ->numeric()
                                            ->placeholder('4000')
                                            ->helperText('Limite de tokens pour le contexte documentaire'),
                                    ])
                                    ->columns(3),

                                Forms\Components\Section::make('Mode de fonctionnement')
                                    ->schema([
                                        Forms\Components\Toggle::make('strict_mode')
                                            ->label('Mode strict')
                                            ->helperText('Ajoute automatiquement des garde-fous contre les hallucinations. Recommandé pour les agents factuels (support, BTP, médical).')
                                            ->columnSpanFull(),
                                    ]),

                                Forms\Components\Section::make('Traitement des documents')
                                    ->description('Paramètres par défaut pour les nouveaux documents et le crawler')
                                    ->schema([
                                        Forms\Components\Select::make('default_extraction_method')
                                            ->label('Méthode d\'extraction PDF')
                                            ->options([
                                                'auto' => 'Automatique (texte si disponible, sinon OCR)',
                                                'text' => 'Texte uniquement (pdftotext)',
                                                'ocr' => 'OCR forcé (Tesseract)',
                                                'vision' => 'Vision IA (préserve tableaux)',
                                            ])
                                            ->default('auto')
                                            ->helperText('Vision: modèle IA pour tableaux et documents complexes'),

                                        Forms\Components\Select::make('default_chunk_strategy')
                                            ->label('Stratégie de découpage')
                                            ->options([
                                                'sentence' => 'Par phrases (recommandé)',
                                                'paragraph' => 'Par paragraphes',
                                                'fixed' => 'Taille fixe (500 tokens)',
                                                'llm_assisted' => 'Assisté par LLM (qualité premium)',
                                            ])
                                            ->default('sentence')
                                            ->helperText('Méthode de découpage du texte en chunks pour l\'indexation'),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Configuration Hydratation')
                                    ->schema([
                                        Forms\Components\KeyValue::make('hydration_config')
                                            ->label('Configuration')
                                            ->keyLabel('Clé')
                                            ->valueLabel('Valeur')
                                            ->addActionLabel('Ajouter un paramètre'),
                                    ])
                                    ->collapsed()
                                    ->visible(fn (callable $get) => $get('retrieval_mode') === 'SQL_HYDRATION'),
                            ]),

                        Forms\Components\Tabs\Tab::make('System Prompt')
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                Forms\Components\Section::make('Instructions système')
                                    ->schema([
                                        Forms\Components\MarkdownEditor::make('system_prompt')
                                            ->label('')
                                            ->columnSpanFull()
                                            ->helperText('Instructions données au modèle pour définir son comportement'),
                                    ]),
                            ]),

                        Forms\Components\Tabs\Tab::make('Whitelabel')
                            ->icon('heroicon-o-rocket-launch')
                            ->schema([
                                Forms\Components\Section::make('Configuration Whitelabel')
                                    ->description('Permettre aux éditeurs tiers d\'intégrer cet agent dans leurs applications')
                                    ->schema([
                                        Forms\Components\Toggle::make('is_whitelabel_enabled')
                                            ->label('Activer le whitelabel')
                                            ->helperText('Permet aux éditeurs de créer des déploiements de cet agent')
                                            ->live(),

                                        Forms\Components\Select::make('deployment_mode')
                                            ->label('Mode de déploiement')
                                            ->options([
                                                'internal' => 'Interne uniquement (pas de whitelabel)',
                                                'shared' => 'Partagé (même RAG pour tous)',
                                                'dedicated' => 'Dédié (collection RAG par déploiement)',
                                            ])
                                            ->default('internal')
                                            ->helperText('Détermine comment le RAG est partagé entre les déploiements')
                                            ->visible(fn (callable $get) => $get('is_whitelabel_enabled')),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Branding par défaut')
                                    ->description('Valeurs par défaut pour les nouveaux déploiements (peuvent être surchargées)')
                                    ->schema([
                                        Forms\Components\TextInput::make('whitelabel_config.default_branding.chat_title')
                                            ->label('Titre du chat')
                                            ->placeholder('Assistant IA'),

                                        Forms\Components\Textarea::make('whitelabel_config.default_branding.welcome_message')
                                            ->label('Message de bienvenue')
                                            ->rows(2)
                                            ->placeholder('Bonjour, comment puis-je vous aider ?'),

                                        Forms\Components\ColorPicker::make('whitelabel_config.default_branding.primary_color')
                                            ->label('Couleur principale'),

                                        Forms\Components\TextInput::make('whitelabel_config.default_branding.signature')
                                            ->label('Signature')
                                            ->placeholder('Powered by Batirama'),
                                    ])
                                    ->columns(2)
                                    ->visible(fn (callable $get) => $get('is_whitelabel_enabled')),

                                Forms\Components\Section::make('Permissions éditeurs')
                                    ->description('Ce que les éditeurs peuvent personnaliser')
                                    ->schema([
                                        Forms\Components\Toggle::make('whitelabel_config.allow_prompt_override')
                                            ->label('Override du system prompt')
                                            ->helperText('Permettre aux éditeurs d\'ajouter des instructions au prompt'),

                                        Forms\Components\Toggle::make('whitelabel_config.allow_rag_override')
                                            ->label('Override de la config RAG')
                                            ->helperText('Permettre de modifier max_results, min_score, etc.'),

                                        Forms\Components\Toggle::make('whitelabel_config.allow_model_override')
                                            ->label('Override du modèle LLM')
                                            ->helperText('Permettre de changer le modèle LLM'),

                                        Forms\Components\Toggle::make('whitelabel_config.required_branding')
                                            ->label('Branding "Powered by" obligatoire')
                                            ->default(true)
                                            ->helperText('Forcer l\'affichage du branding Batirama'),
                                    ])
                                    ->columns(2)
                                    ->visible(fn (callable $get) => $get('is_whitelabel_enabled')),

                                Forms\Components\Section::make('Limites')
                                    ->schema([
                                        Forms\Components\TextInput::make('whitelabel_config.min_rate_limit')
                                            ->label('Rate limit minimum (req/min)')
                                            ->numeric()
                                            ->default(30)
                                            ->helperText('Les éditeurs ne peuvent pas descendre en dessous'),

                                        Forms\Components\Placeholder::make('deployments_count')
                                            ->label('Déploiements actifs')
                                            ->content(fn ($record) => $record?->deployments()->where('is_active', true)->count() ?? 0),
                                    ])
                                    ->columns(2)
                                    ->visible(fn (callable $get) => $get('is_whitelabel_enabled')),
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

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('model')
                    ->label('Modèle')
                    ->default('Par défaut')
                    ->badge(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean(),

                Tables\Columns\IconColumn::make('allow_public_access')
                    ->label('Public')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_whitelabel_enabled')
                    ->label('Whitelabel')
                    ->boolean()
                    ->trueIcon('heroicon-o-rocket-launch')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('deployments_count')
                    ->label('Déploiements')
                    ->counts('deployments')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('sessions_count')
                    ->label('Sessions')
                    ->counts('sessions')
                    ->sortable(),

                Tables\Columns\TextColumn::make('documents_count')
                    ->label('Documents')
                    ->counts('documents')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Modifié')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Actif'),

                Tables\Filters\TernaryFilter::make('allow_public_access')
                    ->label('Accès public'),

                Tables\Filters\TernaryFilter::make('is_whitelabel_enabled')
                    ->label('Whitelabel'),
            ])
            ->actions([
                Tables\Actions\Action::make('test')
                    ->label('Tester')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->url(fn (Agent $record) => route('filament.admin.resources.agents.test', $record)),

                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('duplicate')
                    ->label('Dupliquer')
                    ->icon('heroicon-o-document-duplicate')
                    ->action(function (Agent $record) {
                        $newAgent = $record->replicate();
                        $newAgent->name = $record->name . ' (copie)';
                        $newAgent->slug = $record->slug . '-copy-' . time();
                        $newAgent->save();
                    }),

                Tables\Actions\Action::make('reset')
                    ->label('Reinitialiser')
                    ->icon('heroicon-o-arrow-path')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalHeading('Reinitialiser l\'agent')
                    ->modalDescription(fn (Agent $record) =>
                        "Attention ! Cette action va supprimer definitivement :\n" .
                        "- Toutes les sessions IA ({$record->sessions()->count()} sessions)\n" .
                        "- Tous les messages et contextes RAG envoyes a l'IA\n" .
                        "- Tous les documents de l'agent ({$record->documents()->count()} documents)\n" .
                        "- Tous les chunks et embeddings dans Qdrant\n" .
                        "- Toutes les reponses apprises (learned responses)\n\n" .
                        "Cette action est irreversible. Continuer ?"
                    )
                    ->modalSubmitActionLabel('Oui, reinitialiser')
                    ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
                    ->action(function (Agent $record) {
                        try {
                            $resetService = app(AgentResetService::class);
                            $stats = $resetService->reset($record);

                            Notification::make()
                                ->title('Agent reinitialise')
                                ->body(sprintf(
                                    "Sessions: %d supprimees (%d messages)\n" .
                                    "Documents: %d supprimes (%d chunks, %d fichiers)\n" .
                                    "Collection Qdrant: %s\n" .
                                    "Reponses apprises: %d supprimees",
                                    $stats['sessions_deleted'],
                                    $stats['messages_deleted'],
                                    $stats['documents_deleted'],
                                    $stats['chunks_deleted'],
                                    $stats['files_deleted'],
                                    $stats['collection_reset'] ? 'recreee' : 'non modifiee',
                                    $stats['learned_responses_deleted']
                                ))
                                ->success()
                                ->duration(10000)
                                ->send();

                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Erreur lors de la reinitialisation')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
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
                        ->action(fn ($records) => $records->each->update(['is_active' => false])),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAgents::route('/'),
            'create' => Pages\CreateAgent::route('/create'),
            'edit' => Pages\EditAgent::route('/{record}/edit'),
            'test' => Pages\TestAgent::route('/{record}/test'),
        ];
    }
}

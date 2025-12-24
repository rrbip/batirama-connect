<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AgentResource\Pages;
use App\Models\Agent;
use Filament\Forms;
use Filament\Forms\Form;
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
                                Forms\Components\Section::make('Modèle LLM')
                                    ->schema([
                                        Forms\Components\TextInput::make('model')
                                            ->label('Modèle principal')
                                            ->placeholder('llama3.2')
                                            ->helperText('Modèle Ollama à utiliser'),

                                        Forms\Components\TextInput::make('fallback_model')
                                            ->label('Modèle de secours')
                                            ->placeholder('mistral'),

                                        Forms\Components\TextInput::make('ollama_host')
                                            ->label('Host Ollama')
                                            ->placeholder('ollama'),

                                        Forms\Components\TextInput::make('ollama_port')
                                            ->label('Port Ollama')
                                            ->numeric()
                                            ->placeholder('11434'),
                                    ])
                                    ->columns(2),

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

                                        Forms\Components\TextInput::make('qdrant_collection')
                                            ->label('Collection Qdrant')
                                            ->placeholder('agent_documents'),

                                        Forms\Components\TextInput::make('max_rag_results')
                                            ->label('Max résultats RAG')
                                            ->numeric()
                                            ->default(5),

                                        Forms\Components\Toggle::make('allow_iterative_search')
                                            ->label('Recherche itérative')
                                            ->helperText('Permet plusieurs requêtes de recherche'),
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

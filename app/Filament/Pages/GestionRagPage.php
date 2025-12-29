<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\DocumentCategoryResource;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\WebCrawlResource;
use App\Jobs\ProcessDocumentJob;
use App\Jobs\RebuildAgentIndexJob;
use App\Models\Agent;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\LlmChunkingSetting;
use App\Models\PipelineToolsSetting;
use App\Models\QrAtomiqueSetting;
use App\Models\VisionSetting;
use App\Models\WebCrawl;
use App\Services\AI\OllamaService;
use App\Services\LlmChunkingService;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

class GestionRagPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    protected static string $view = 'filament.pages.gestion-rag-page';

    protected static ?string $navigationGroup = 'Intelligence Artificielle';

    protected static ?string $navigationLabel = 'Gestion RAG';

    protected static ?string $title = 'Gestion RAG';

    protected static ?int $navigationSort = 2;

    #[Url]
    public string $activeTab = 'documents';

    // Form data states
    public ?array $visionData = [];
    public ?array $chunkingData = [];
    public ?array $qrData = [];
    public ?array $toolsData = [];

    public array $queueStats = [];

    public function mount(): void
    {
        $this->loadAllSettings();
        $this->refreshQueueStats();
    }

    protected function loadAllSettings(): void
    {
        try {
            // Vision settings
            $visionSettings = VisionSetting::getInstance();
            $this->visionForm->fill([
                'model' => $visionSettings->model,
                'ollama_host' => $visionSettings->ollama_host,
                'ollama_port' => $visionSettings->ollama_port,
                'temperature' => $visionSettings->temperature,
                'timeout_seconds' => $visionSettings->timeout_seconds,
                'system_prompt' => $visionSettings->system_prompt,
            ]);
        } catch (\Exception $e) {
            // Table doesn't exist yet - use defaults
            $this->visionForm->fill([
                'ollama_host' => 'ollama',
                'ollama_port' => 11434,
                'temperature' => 0.3,
                'timeout_seconds' => 300,
                'system_prompt' => VisionSetting::getDefaultPrompt(),
            ]);
        }

        try {
            // Chunking LLM settings
            $chunkingSettings = LlmChunkingSetting::getInstance();
            $this->chunkingForm->fill([
                'model' => $chunkingSettings->model,
                'ollama_host' => $chunkingSettings->ollama_host,
                'ollama_port' => $chunkingSettings->ollama_port,
                'temperature' => $chunkingSettings->temperature,
                'window_size' => $chunkingSettings->window_size,
                'overlap_percent' => $chunkingSettings->overlap_percent,
                'max_retries' => $chunkingSettings->max_retries,
                'timeout_seconds' => $chunkingSettings->timeout_seconds,
                'system_prompt' => $chunkingSettings->system_prompt,
            ]);
        } catch (\Exception $e) {
            // Table doesn't exist yet - use defaults
        }

        try {
            // Q/R Atomique settings
            $qrSettings = QrAtomiqueSetting::getInstance();
            $this->qrForm->fill([
                'model' => $qrSettings->model,
                'ollama_host' => $qrSettings->ollama_host,
                'ollama_port' => $qrSettings->ollama_port,
                'temperature' => $qrSettings->temperature,
                'threshold' => $qrSettings->threshold,
                'timeout_seconds' => $qrSettings->timeout_seconds,
                'system_prompt' => $qrSettings->system_prompt,
            ]);
        } catch (\Exception $e) {
            // Table doesn't exist yet - use defaults
            $this->qrForm->fill([
                'ollama_host' => 'ollama',
                'ollama_port' => 11434,
                'temperature' => 0.3,
                'threshold' => 1500,
                'timeout_seconds' => 120,
                'system_prompt' => QrAtomiqueSetting::getDefaultPrompt(),
            ]);
        }

        try {
            // Pipeline tools settings
            $toolsSettings = PipelineToolsSetting::getInstance();
            $this->toolsForm->fill([
                'pdf_tools' => $toolsSettings->pdf_tools,
                'image_tools' => $toolsSettings->image_tools,
                'html_tools' => $toolsSettings->html_tools,
                'markdown_tools' => $toolsSettings->markdown_tools,
            ]);
        } catch (\Exception $e) {
            // Table doesn't exist yet - use defaults
            $defaults = PipelineToolsSetting::getDefaults();
            $this->toolsForm->fill($defaults);
        }
    }

    public function refreshQueueStats(): void
    {
        try {
            $this->queueStats = [
                'pending' => DB::table('jobs')
                    ->whereIn('queue', ['llm-chunking', 'pipeline'])
                    ->count(),
                'failed' => DB::table('failed_jobs')
                    ->whereIn('queue', ['llm-chunking', 'pipeline'])
                    ->count(),
            ];
        } catch (\Exception $e) {
            $this->queueStats = [
                'pending' => 0,
                'failed' => 0,
            ];
        }
    }

    /**
     * Table configuration - changes based on active tab
     */
    public function table(Table $table): Table
    {
        return match ($this->activeTab) {
            'categories' => $this->categoriesTable($table),
            'crawlers' => $this->crawlersTable($table),
            default => $this->documentsTable($table),
        };
    }

    protected function documentsTable(Table $table): Table
    {
        return $table
            ->query(Document::query())
            ->columns([
                TextColumn::make('title')
                    ->label('Titre')
                    ->default(fn ($record) => $record->original_name)
                    ->searchable(['title', 'original_name'])
                    ->sortable()
                    ->limit(40),

                TextColumn::make('agent.name')
                    ->label('Agent')
                    ->searchable()
                    ->sortable()
                    ->badge(),

                TextColumn::make('document_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pdf' => 'danger',
                        'docx', 'doc' => 'info',
                        'txt', 'md' => 'gray',
                        'html' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('extraction_status')
                    ->label('Extraction')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'completed' => 'success',
                        'processing' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'pending' => 'En attente',
                        'processing' => 'En cours',
                        'completed' => 'Terminé',
                        'failed' => 'Échoué',
                        default => '-',
                    }),

                IconColumn::make('is_indexed')
                    ->label('Indexé')
                    ->boolean(),

                TextColumn::make('chunk_count')
                    ->label('Chunks')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('file_size')
                    ->label('Taille')
                    ->formatStateUsing(fn ($record) => $record->getFileSizeForHumans())
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('agent')
                    ->relationship('agent', 'name'),

                SelectFilter::make('extraction_status')
                    ->label('Statut extraction')
                    ->options([
                        'pending' => 'En attente',
                        'processing' => 'En cours',
                        'completed' => 'Terminé',
                        'failed' => 'Échoué',
                    ]),

                TernaryFilter::make('is_indexed')
                    ->label('Indexé'),
            ])
            ->actions([
                Action::make('download')
                    ->label('Télécharger')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn ($record) => route('admin.documents.download', $record))
                    ->openUrlInNewTab()
                    ->visible(fn ($record) => Storage::disk('local')->exists($record->storage_path ?? '')),

                Action::make('reprocess')
                    ->label('Retraiter')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (Document $record) {
                        $record->update([
                            'extraction_status' => 'pending',
                            'extraction_error' => null,
                            'is_indexed' => false,
                        ]);
                        ProcessDocumentJob::dispatch($record);
                        Notification::make()->title('Traitement relancé')->success()->send();
                    }),

                Action::make('chunks')
                    ->label('Chunks')
                    ->icon('heroicon-o-squares-2x2')
                    ->color('info')
                    ->visible(fn ($record) => $record->chunk_count > 0)
                    ->url(fn ($record) => DocumentResource::getUrl('chunks', ['record' => $record])),

                Action::make('edit')
                    ->label('Modifier')
                    ->icon('heroicon-o-pencil')
                    ->url(fn ($record) => DocumentResource::getUrl('edit', ['record' => $record])),

                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('reprocess')
                        ->label('Retraiter')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                $record->update([
                                    'extraction_status' => 'pending',
                                    'extraction_error' => null,
                                    'is_indexed' => false,
                                ]);
                                ProcessDocumentJob::dispatch($record);
                            }
                            Notification::make()->title('Traitement en cours')->success()->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->recordUrl(fn (Document $record) => DocumentResource::getUrl('edit', ['record' => $record]));
    }

    protected function categoriesTable(Table $table): Table
    {
        return $table
            ->query(DocumentCategory::query())
            ->columns([
                ColorColumn::make('color')
                    ->label('')
                    ->width(20),

                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->toggleable(),

                IconColumn::make('is_ai_generated')
                    ->label('IA')
                    ->boolean()
                    ->trueIcon('heroicon-o-cpu-chip')
                    ->falseIcon('heroicon-o-user')
                    ->trueColor('info')
                    ->falseColor('gray'),

                TextColumn::make('usage_count')
                    ->label('Utilisations')
                    ->numeric()
                    ->sortable()
                    ->badge(),
            ])
            ->filters([
                TernaryFilter::make('is_ai_generated')
                    ->label('Générée par IA'),
            ])
            ->actions([
                Action::make('edit')
                    ->label('Modifier')
                    ->icon('heroicon-o-pencil')
                    ->url(fn ($record) => DocumentCategoryResource::getUrl('edit', ['record' => $record])),

                Action::make('recalculate')
                    ->label('Recalculer')
                    ->icon('heroicon-o-arrow-path')
                    ->action(fn (DocumentCategory $record) => $record->recalculateUsage()),

                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('usage_count', 'desc')
            ->recordUrl(fn (DocumentCategory $record) => DocumentCategoryResource::getUrl('edit', ['record' => $record]));
    }

    protected function crawlersTable(Table $table): Table
    {
        return $table
            ->query(WebCrawl::query())
            ->columns([
                TextColumn::make('start_url')
                    ->label('URL')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->start_url)
                    ->searchable(),

                TextColumn::make('agents_count')
                    ->label('Agents')
                    ->counts('agents')
                    ->badge()
                    ->color('primary'),

                BadgeColumn::make('status')
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

                TextColumn::make('progress')
                    ->label('Progression')
                    ->state(fn ($record) => "{$record->pages_crawled}/{$record->pages_discovered}")
                    ->description(fn ($record) => $record->progress_percent . '%'),

                TextColumn::make('total_pages_indexed')
                    ->label('Indexées')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'pending' => 'En attente',
                        'running' => 'En cours',
                        'completed' => 'Terminé',
                        'failed' => 'Échoué',
                    ]),
            ])
            ->actions([
                Action::make('view')
                    ->label('Détails')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => WebCrawlResource::getUrl('view', ['record' => $record])),

                Action::make('edit')
                    ->label('Modifier')
                    ->icon('heroicon-o-pencil')
                    ->url(fn ($record) => WebCrawlResource::getUrl('edit', ['record' => $record])),

                Action::make('pause')
                    ->label('Pause')
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->visible(fn ($record) => $record->status === 'running')
                    ->action(function ($record) {
                        $record->update(['status' => 'paused', 'paused_at' => now()]);
                        Notification::make()->title('Crawl mis en pause')->success()->send();
                    }),

                Action::make('resume')
                    ->label('Reprendre')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === 'paused')
                    ->action(function ($record) {
                        $record->update(['status' => 'running', 'paused_at' => null]);
                        Notification::make()->title('Crawl repris')->success()->send();
                    }),

                DeleteAction::make()
                    ->visible(fn ($record) => $record->isCompleted()),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (WebCrawl $record) => WebCrawlResource::getUrl('view', ['record' => $record]));
    }

    /**
     * All settings forms
     */
    protected function getForms(): array
    {
        return [
            'visionForm' => $this->makeForm()
                ->schema([
                    Section::make('Connexion Ollama')
                        ->schema([
                            Select::make('model')
                                ->label('Modèle Vision')
                                ->options(fn () => $this->getVisionModels())
                                ->placeholder('Utiliser le modèle de l\'agent'),

                            TextInput::make('ollama_host')
                                ->label('Host')
                                ->required()
                                ->default('ollama'),

                            TextInput::make('ollama_port')
                                ->label('Port')
                                ->numeric()
                                ->required()
                                ->default(11434),

                            TextInput::make('temperature')
                                ->label('Température')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(1)
                                ->step(0.1)
                                ->default(0.3),

                            TextInput::make('timeout_seconds')
                                ->label('Timeout')
                                ->numeric()
                                ->default(300)
                                ->suffix('s'),
                        ])
                        ->columns(5),

                    Section::make('Prompt système')
                        ->schema([
                            Textarea::make('system_prompt')
                                ->label('')
                                ->rows(8)
                                ->required()
                                ->columnSpanFull(),
                        ]),
                ])
                ->statePath('visionData'),

            'chunkingForm' => $this->makeForm()
                ->schema([
                    Section::make('Connexion Ollama')
                        ->schema([
                            Select::make('model')
                                ->label('Modèle')
                                ->options(fn () => $this->getAvailableModels())
                                ->placeholder('Utiliser le modèle de l\'agent'),

                            TextInput::make('ollama_host')
                                ->label('Host')
                                ->required()
                                ->default('ollama'),

                            TextInput::make('ollama_port')
                                ->label('Port')
                                ->numeric()
                                ->required()
                                ->default(11434),

                            TextInput::make('temperature')
                                ->label('Température')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(1)
                                ->step(0.1)
                                ->default(0.3),
                        ])
                        ->columns(4),

                    Section::make('Pré-découpage')
                        ->schema([
                            TextInput::make('window_size')
                                ->label('Taille fenêtre (tokens)')
                                ->numeric()
                                ->required()
                                ->default(2000),

                            TextInput::make('overlap_percent')
                                ->label('Chevauchement')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(50)
                                ->required()
                                ->default(10)
                                ->suffix('%'),

                            TextInput::make('max_retries')
                                ->label('Tentatives')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(5)
                                ->required()
                                ->default(1),

                            TextInput::make('timeout_seconds')
                                ->label('Timeout')
                                ->numeric()
                                ->required()
                                ->default(0)
                                ->suffix('s'),
                        ])
                        ->columns(4),

                    Section::make('Prompt système')
                        ->schema([
                            MarkdownEditor::make('system_prompt')
                                ->label('')
                                ->required()
                                ->columnSpanFull(),
                        ]),
                ])
                ->statePath('chunkingData'),

            'qrForm' => $this->makeForm()
                ->schema([
                    Section::make('Connexion Ollama')
                        ->schema([
                            Select::make('model')
                                ->label('Modèle')
                                ->options(fn () => $this->getAvailableModels())
                                ->placeholder('Utiliser le modèle de l\'agent'),

                            TextInput::make('ollama_host')
                                ->label('Host')
                                ->required()
                                ->default('ollama'),

                            TextInput::make('ollama_port')
                                ->label('Port')
                                ->numeric()
                                ->required()
                                ->default(11434),

                            TextInput::make('temperature')
                                ->label('Température')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(1)
                                ->step(0.1)
                                ->default(0.3),
                        ])
                        ->columns(4),

                    Section::make('Paramètres Q/R Atomique')
                        ->schema([
                            TextInput::make('threshold')
                                ->label('Seuil de découpage')
                                ->helperText('Nombre de caractères max avant découpage en paragraphes')
                                ->numeric()
                                ->required()
                                ->default(1500)
                                ->suffix('caractères'),

                            TextInput::make('timeout_seconds')
                                ->label('Timeout')
                                ->numeric()
                                ->required()
                                ->default(120)
                                ->suffix('s'),
                        ])
                        ->columns(2),

                    Section::make('Prompt système')
                        ->schema([
                            Textarea::make('system_prompt')
                                ->label('')
                                ->rows(12)
                                ->required()
                                ->columnSpanFull(),
                        ]),
                ])
                ->statePath('qrData'),

            'toolsForm' => $this->makeForm()
                ->schema([
                    Section::make('Pipeline PDF')
                        ->description('PDF → Images → Markdown → Q/R + Qdrant')
                        ->schema([
                            Select::make('pdf_tools.0.tool')
                                ->label('Étape 1: PDF vers Images')
                                ->options(['pdftoppm' => 'pdftoppm (ImageMagick)'])
                                ->default('pdftoppm'),
                            Select::make('pdf_tools.1.tool')
                                ->label('Étape 2: Images vers Markdown')
                                ->options(['vision_llm' => 'Vision LLM (Llama 3.2 Vision)'])
                                ->default('vision_llm'),
                            Select::make('pdf_tools.2.tool')
                                ->label('Étape 3: Markdown vers Q/R')
                                ->options(['qr_atomique' => 'Q/R Atomique (LLM)'])
                                ->default('qr_atomique'),
                        ])
                        ->columns(3),

                    Section::make('Pipeline Image')
                        ->description('Image → Markdown → Q/R + Qdrant')
                        ->schema([
                            Select::make('image_tools.0.tool')
                                ->label('Étape 1: Image vers Markdown')
                                ->options(['vision_llm' => 'Vision LLM (Llama 3.2 Vision)'])
                                ->default('vision_llm'),
                            Select::make('image_tools.1.tool')
                                ->label('Étape 2: Markdown vers Q/R')
                                ->options(['qr_atomique' => 'Q/R Atomique (LLM)'])
                                ->default('qr_atomique'),
                        ])
                        ->columns(2),

                    Section::make('Pipeline HTML')
                        ->description('HTML → Markdown → Q/R + Qdrant')
                        ->schema([
                            Select::make('html_tools.0.tool')
                                ->label('Étape 1: HTML vers Markdown')
                                ->options(['turndown' => 'Turndown (JS library)'])
                                ->default('turndown'),
                            Select::make('html_tools.1.tool')
                                ->label('Étape 2: Markdown vers Q/R')
                                ->options(['qr_atomique' => 'Q/R Atomique (LLM)'])
                                ->default('qr_atomique'),
                        ])
                        ->columns(2),

                    Section::make('Pipeline Markdown')
                        ->description('Markdown → Q/R + Qdrant (direct)')
                        ->schema([
                            Select::make('markdown_tools.0.tool')
                                ->label('Étape 1: Markdown vers Q/R')
                                ->options(['qr_atomique' => 'Q/R Atomique (LLM)'])
                                ->default('qr_atomique'),
                        ])
                        ->columns(1),
                ])
                ->statePath('toolsData'),
        ];
    }

    protected function getAvailableModels(): array
    {
        try {
            $ollama = app(OllamaService::class);
            $models = $ollama->listModels();

            return array_combine($models, $models);
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function getVisionModels(): array
    {
        // Vision models typically have 'vision' in the name
        $models = $this->getAvailableModels();

        $visionModels = array_filter($models, function ($model) {
            return str_contains(strtolower($model), 'vision') ||
                   str_contains(strtolower($model), 'llava') ||
                   str_contains(strtolower($model), 'bakllava');
        }, ARRAY_FILTER_USE_KEY);

        return !empty($visionModels) ? $visionModels : $models;
    }

    // Save actions for each form
    public function saveVisionSettings(): void
    {
        try {
            $data = $this->visionForm->getState();
            $settings = VisionSetting::getInstance();
            $settings->update($data);

            Notification::make()
                ->title('Configuration Vision sauvegardée')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur')
                ->body('La table vision_settings n\'existe pas. Exécutez les migrations.')
                ->danger()
                ->send();
        }
    }

    public function saveChunkingSettings(): void
    {
        try {
            $data = $this->chunkingForm->getState();
            $settings = LlmChunkingSetting::getInstance();
            $settings->update($data);

            Notification::make()
                ->title('Configuration Chunking LLM sauvegardée')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur')
                ->body('La table llm_chunking_settings n\'existe pas. Exécutez les migrations.')
                ->danger()
                ->send();
        }
    }

    public function saveQrSettings(): void
    {
        try {
            $data = $this->qrForm->getState();
            $settings = QrAtomiqueSetting::getInstance();
            $settings->update($data);

            Notification::make()
                ->title('Configuration Q/R Atomique sauvegardée')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur')
                ->body('La table qr_atomique_settings n\'existe pas. Exécutez les migrations.')
                ->danger()
                ->send();
        }
    }

    public function saveToolsSettings(): void
    {
        try {
            $data = $this->toolsForm->getState();

            // Transform flat structure to array structure
            $transformed = [
                'pdf_tools' => [
                    ['name' => 'pdf_to_images', 'tool' => $data['pdf_tools'][0]['tool'] ?? 'pdftoppm', 'enabled' => true],
                    ['name' => 'images_to_markdown', 'tool' => $data['pdf_tools'][1]['tool'] ?? 'vision_llm', 'enabled' => true],
                    ['name' => 'markdown_to_qr', 'tool' => $data['pdf_tools'][2]['tool'] ?? 'qr_atomique', 'enabled' => true],
                ],
                'image_tools' => [
                    ['name' => 'image_to_markdown', 'tool' => $data['image_tools'][0]['tool'] ?? 'vision_llm', 'enabled' => true],
                    ['name' => 'markdown_to_qr', 'tool' => $data['image_tools'][1]['tool'] ?? 'qr_atomique', 'enabled' => true],
                ],
                'html_tools' => [
                    ['name' => 'html_to_markdown', 'tool' => $data['html_tools'][0]['tool'] ?? 'turndown', 'enabled' => true],
                    ['name' => 'markdown_to_qr', 'tool' => $data['html_tools'][1]['tool'] ?? 'qr_atomique', 'enabled' => true],
                ],
                'markdown_tools' => [
                    ['name' => 'markdown_to_qr', 'tool' => $data['markdown_tools'][0]['tool'] ?? 'qr_atomique', 'enabled' => true],
                ],
            ];

            $settings = PipelineToolsSetting::getInstance();
            $settings->update($transformed);

            Notification::make()
                ->title('Configuration des outils sauvegardée')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur')
                ->body('La table pipeline_tools_settings n\'existe pas. Exécutez les migrations.')
                ->danger()
                ->send();
        }
    }

    public function testOllamaConnection(): void
    {
        try {
            $service = app(LlmChunkingService::class);

            if ($service->isAvailable()) {
                Notification::make()
                    ->title('Connexion réussie')
                    ->body('Le service Ollama est accessible.')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Service indisponible')
                    ->body('Impossible de contacter Ollama.')
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur de connexion')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function resetVisionPrompt(): void
    {
        $this->visionForm->fill([
            ...$this->visionForm->getState(),
            'system_prompt' => VisionSetting::getDefaultPrompt(),
        ]);

        Notification::make()
            ->title('Prompt Vision réinitialisé')
            ->info()
            ->send();
    }

    public function resetChunkingPrompt(): void
    {
        $this->chunkingForm->fill([
            ...$this->chunkingForm->getState(),
            'system_prompt' => LlmChunkingSetting::getDefaultPrompt(),
        ]);

        Notification::make()
            ->title('Prompt Chunking réinitialisé')
            ->info()
            ->send();
    }

    public function resetQrPrompt(): void
    {
        $this->qrForm->fill([
            ...$this->qrForm->getState(),
            'system_prompt' => QrAtomiqueSetting::getDefaultPrompt(),
        ]);

        Notification::make()
            ->title('Prompt Q/R réinitialisé')
            ->info()
            ->send();
    }

    public function rebuildQdrantIndex(array $data): void
    {
        $agent = Agent::find($data['agent_id']);

        if (! $agent || empty($agent->qdrant_collection)) {
            Notification::make()
                ->title('Erreur')
                ->body('Agent non trouvé ou sans collection Qdrant configurée.')
                ->danger()
                ->send();

            return;
        }

        RebuildAgentIndexJob::dispatch($agent);

        Notification::make()
            ->title('Reconstruction lancée')
            ->body("L'index Qdrant de l'agent \"{$agent->name}\" est en cours de reconstruction.")
            ->success()
            ->send();
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetTable();
    }
}

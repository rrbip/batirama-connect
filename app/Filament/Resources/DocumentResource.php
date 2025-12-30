<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Intelligence Artificielle';

    protected static ?string $modelLabel = 'Document RAG';

    protected static ?string $pluralModelLabel = 'Documents RAG';

    protected static ?int $navigationSort = 2;

    // Masqué - accessible via Gestion RAG
    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRole('super-admin') || $user->hasRole('admin'));
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Document')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Informations')
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                // Section Agent (obligatoire)
                                Forms\Components\Section::make('Agent')
                                    ->schema([
                                        Forms\Components\Select::make('agent_id')
                                            ->label('Agent')
                                            ->relationship('agent', 'name')
                                            ->required()
                                            ->searchable()
                                            ->preload()
                                            ->live()
                                            ->helperText('L\'agent détermine la collection Qdrant où sera indexé le document'),
                                    ])
                                    ->columns(1),

                                // Section Source (fichier OU URL)
                                Forms\Components\Section::make('Source du document')
                                    ->schema([
                                        Forms\Components\ToggleButtons::make('source_type')
                                            ->label('Type de source')
                                            ->options([
                                                'file' => 'Fichier',
                                                'url' => 'URL',
                                            ])
                                            ->default('file')
                                            ->inline()
                                            ->live()
                                            ->visible(fn ($record) => !$record),

                                        // Upload pour fichier
                                        Forms\Components\FileUpload::make('storage_path')
                                            ->label('Document')
                                            ->required(fn (Forms\Get $get) => $get('source_type') === 'file')
                                            ->disk('local')
                                            ->directory('documents')
                                            ->acceptedFileTypes([
                                                'application/pdf',
                                                'text/plain',
                                                'text/markdown',
                                                'text/html',
                                                'image/png',
                                                'image/jpeg',
                                                'image/gif',
                                                'image/webp',
                                            ])
                                            ->maxSize(100 * 1024) // 100MB
                                            ->columnSpanFull()
                                            ->visible(fn (Forms\Get $get, $record) => !$record && $get('source_type') === 'file')
                                            ->live()
                                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                                if ($state) {
                                                    // Auto-fill title from filename
                                                    $filename = is_string($state) ? basename($state) : '';
                                                    if ($filename) {
                                                        $title = pathinfo($filename, PATHINFO_FILENAME);
                                                        $title = str_replace(['_', '-'], ' ', $title);
                                                        $title = ucfirst($title);
                                                        $set('title', $title);
                                                    }
                                                }
                                            }),

                                        // Input URL
                                        Forms\Components\TextInput::make('source_url')
                                            ->label('URL')
                                            ->url()
                                            ->required(fn (Forms\Get $get) => $get('source_type') === 'url')
                                            ->maxLength(2000)
                                            ->columnSpanFull()
                                            ->visible(fn (Forms\Get $get, $record) => !$record && $get('source_type') === 'url')
                                            ->live()
                                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                                if ($state) {
                                                    // Auto-extract title from URL
                                                    $parsed = parse_url($state);
                                                    $path = $parsed['path'] ?? '';
                                                    $title = basename($path) ?: ($parsed['host'] ?? '');
                                                    $title = pathinfo($title, PATHINFO_FILENAME) ?: $title;
                                                    $title = str_replace(['_', '-'], ' ', $title);
                                                    $title = ucfirst($title);
                                                    $set('title', $title);
                                                }
                                            }),
                                    ])
                                    ->visible(fn ($record) => !$record),

                                // Section Métadonnées
                                Forms\Components\Section::make('Métadonnées')
                                    ->schema([
                                        Forms\Components\TextInput::make('title')
                                            ->label('Titre')
                                            ->maxLength(255)
                                            ->columnSpanFull(),

                                        Forms\Components\Textarea::make('description')
                                            ->label('Description')
                                            ->rows(3)
                                            ->columnSpanFull(),
                                    ]),

                                // Section Pipeline (aperçu)
                                Forms\Components\Section::make('Pipeline de traitement')
                                    ->schema([
                                        Forms\Components\Placeholder::make('pipeline_preview')
                                            ->label('')
                                            ->content(function (Forms\Get $get) {
                                                $sourceType = $get('source_type') ?? 'file';
                                                $storagePath = $get('storage_path');
                                                $sourceUrl = $get('source_url');

                                                $extension = null;
                                                if ($sourceType === 'file' && $storagePath) {
                                                    $extension = strtolower(pathinfo($storagePath, PATHINFO_EXTENSION));
                                                } elseif ($sourceType === 'url' && $sourceUrl) {
                                                    $parsed = parse_url($sourceUrl);
                                                    $path = $parsed['path'] ?? '';
                                                    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                                                    if (empty($extension) || !in_array($extension, ['pdf', 'html', 'htm', 'md', 'png', 'jpg', 'jpeg', 'gif', 'webp'])) {
                                                        $extension = 'html'; // Default for URLs
                                                    }
                                                }

                                                if (!$extension) {
                                                    return new \Illuminate\Support\HtmlString(
                                                        '<div class="text-gray-500 italic">Sélectionnez un fichier ou une URL pour voir le pipeline</div>'
                                                    );
                                                }

                                                $pipeline = match ($extension) {
                                                    'pdf' => [
                                                        ['name' => 'PDF vers Images', 'tool' => 'pdftoppm', 'icon' => 'document'],
                                                        ['name' => 'Images vers Markdown', 'tool' => 'Vision LLM', 'icon' => 'eye'],
                                                        ['name' => 'Markdown vers Q/R', 'tool' => 'Q/R Atomique', 'icon' => 'chat-bubble-left-right'],
                                                    ],
                                                    'png', 'jpg', 'jpeg', 'gif', 'webp' => [
                                                        ['name' => 'Image vers Markdown', 'tool' => 'Vision LLM', 'icon' => 'eye'],
                                                        ['name' => 'Markdown vers Q/R', 'tool' => 'Q/R Atomique', 'icon' => 'chat-bubble-left-right'],
                                                    ],
                                                    'html', 'htm' => [
                                                        ['name' => 'HTML vers Markdown', 'tool' => 'Turndown', 'icon' => 'code-bracket'],
                                                        ['name' => 'Markdown vers Q/R', 'tool' => 'Q/R Atomique', 'icon' => 'chat-bubble-left-right'],
                                                    ],
                                                    'md' => [
                                                        ['name' => 'Markdown vers Q/R', 'tool' => 'Q/R Atomique (direct)', 'icon' => 'chat-bubble-left-right'],
                                                    ],
                                                    default => [
                                                        ['name' => 'Type non supporté', 'tool' => '-', 'icon' => 'exclamation-triangle'],
                                                    ],
                                                };

                                                $html = '<div class="flex items-center gap-2 flex-wrap">';
                                                foreach ($pipeline as $index => $step) {
                                                    if ($index > 0) {
                                                        $html .= '<svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>';
                                                    }
                                                    $html .= sprintf(
                                                        '<div class="flex items-center gap-1 px-3 py-1.5 bg-primary-50 dark:bg-primary-900/20 rounded-lg border border-primary-200 dark:border-primary-800">
                                                            <span class="text-sm font-medium text-primary-700 dark:text-primary-300">%s</span>
                                                            <span class="text-xs text-primary-500 dark:text-primary-400">(%s)</span>
                                                        </div>',
                                                        e($step['name']),
                                                        e($step['tool'])
                                                    );
                                                }
                                                $html .= '</div>';

                                                return new \Illuminate\Support\HtmlString($html);
                                            })
                                            ->columnSpanFull(),
                                    ])
                                    ->visible(fn ($record) => !$record),

                                // Section fichier actuel (uniquement en édition)
                                Forms\Components\Section::make('Fichier actuel')
                                    ->schema([
                                        Forms\Components\Placeholder::make('current_file_info')
                                            ->label('')
                                            ->content(function ($record) {
                                                if (!$record || !$record->storage_path) {
                                                    return 'Aucun fichier';
                                                }

                                                $exists = Storage::disk('local')->exists($record->storage_path);
                                                $icon = $exists ? '✓' : '✗';
                                                $statusClass = $exists ? 'text-success-600' : 'text-danger-600';

                                                return new \Illuminate\Support\HtmlString(sprintf(
                                                    '<div class="space-y-2">
                                                        <div class="flex items-center gap-3">
                                                            <span class="font-medium">%s</span>
                                                            <span class="px-2 py-1 text-xs rounded bg-gray-100 dark:bg-gray-700">%s</span>
                                                            <span class="%s">%s %s</span>
                                                        </div>
                                                        <div class="text-sm text-gray-500">
                                                            Taille: %s | Ajouté le: %s
                                                        </div>
                                                        <div class="text-xs text-gray-400 font-mono">
                                                            %s
                                                        </div>
                                                    </div>',
                                                    e($record->original_name ?? $record->storage_path),
                                                    strtoupper($record->document_type ?? 'N/A'),
                                                    $statusClass,
                                                    $icon,
                                                    $exists ? 'Fichier présent' : 'Fichier manquant',
                                                    $record->getFileSizeForHumans() ?? 'N/A',
                                                    $record->created_at?->format('d/m/Y H:i') ?? 'N/A',
                                                    e($record->storage_path)
                                                ));
                                            })
                                            ->columnSpanFull(),

                                        Forms\Components\Actions::make([
                                            Forms\Components\Actions\Action::make('download')
                                                ->label('Télécharger')
                                                ->icon('heroicon-o-arrow-down-tray')
                                                ->color('primary')
                                                ->url(fn ($record) => $record ? route('admin.documents.download', $record) : null)
                                                ->openUrlInNewTab()
                                                ->visible(fn ($record) => $record && Storage::disk('local')->exists($record->storage_path ?? '')),

                                            Forms\Components\Actions\Action::make('view')
                                                ->label('Voir')
                                                ->icon('heroicon-o-eye')
                                                ->color('gray')
                                                ->url(fn ($record) => $record ? route('admin.documents.view', $record) : null)
                                                ->openUrlInNewTab()
                                                ->visible(fn ($record) => $record && in_array($record->document_type, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'webp']) && Storage::disk('local')->exists($record->storage_path ?? '')),
                                        ])->columnSpanFull(),
                                    ])
                                    ->visible(fn ($record) => $record !== null)
                                    ->collapsed(false),

                                // Section remplacement de fichier
                                Forms\Components\Section::make('Remplacer le fichier')
                                    ->schema([
                                        Forms\Components\FileUpload::make('new_file')
                                            ->label('Nouveau fichier')
                                            ->disk('local')
                                            ->directory('documents')
                                            ->acceptedFileTypes([
                                                'application/pdf',
                                                'text/plain',
                                                'text/markdown',
                                                'text/html',
                                                'image/png',
                                                'image/jpeg',
                                                'image/gif',
                                                'image/webp',
                                            ])
                                            ->maxSize(100 * 1024) // 100MB
                                            ->helperText('Uploadez un nouveau fichier pour remplacer l\'actuel. Le document sera automatiquement retraité.')
                                            ->columnSpanFull()
                                            ->live(),
                                    ])
                                    ->visible(fn ($record) => $record !== null)
                                    ->collapsed(),
                            ]),

                        Forms\Components\Tabs\Tab::make('Pipeline')
                            ->icon('heroicon-o-adjustments-horizontal')
                            ->schema([
                                // Pipeline Steps (Vue Blade dédiée)
                                Forms\Components\Section::make('Étapes du pipeline')
                                    ->schema([
                                        Forms\Components\ViewField::make('pipeline_display')
                                            ->view('filament.resources.document-resource.pipeline-steps')
                                            ->columnSpanFull(),

                                        // Actions
                                        Forms\Components\Actions::make([
                                            Forms\Components\Actions\Action::make('restart_pipeline')
                                                ->label('Relancer tout le pipeline')
                                                ->icon('heroicon-o-arrow-path')
                                                ->color('warning')
                                                ->requiresConfirmation()
                                                ->modalHeading('Relancer le pipeline')
                                                ->modalDescription('Cette action va supprimer les chunks existants et relancer tout le pipeline. Continuer ?')
                                                ->action(function ($record, $livewire) {
                                                    // Debug: log action start
                                                    \Log::info('Pipeline restart action triggered', [
                                                        'document_id' => $record?->id,
                                                        'mime_type' => $record?->mime_type,
                                                        'has_storage_path' => !empty($record?->storage_path),
                                                        'has_extracted_text' => !empty($record?->extracted_text),
                                                    ]);

                                                    try {
                                                        $orchestrator = app(\App\Services\Pipeline\PipelineOrchestratorService::class);

                                                        // Detect document type first
                                                        $documentType = $orchestrator->detectDocumentType($record);
                                                        \Log::info('Document type detected', [
                                                            'document_id' => $record->id,
                                                            'type' => $documentType,
                                                        ]);

                                                        if ($documentType === 'unknown') {
                                                            Notification::make()
                                                                ->title('Type de document non reconnu')
                                                                ->body("MIME type: {$record->mime_type}. Aucun pipeline disponible.")
                                                                ->danger()
                                                                ->persistent()
                                                                ->send();
                                                            return;
                                                        }

                                                        $orchestrator->startPipeline($record);

                                                        // Refresh to show updated pipeline_steps
                                                        $record->refresh();

                                                        $queueDriver = config('queue.default');
                                                        $jobCount = \DB::table('jobs')->where('queue', 'pipeline')->count();
                                                        $totalJobs = \DB::table('jobs')->count();

                                                        // Debug: check if pipeline_steps was saved
                                                        $pipelineSteps = $record->pipeline_steps;
                                                        $stepsCount = count($pipelineSteps['steps'] ?? []);
                                                        $pipelineStatus = $pipelineSteps['status'] ?? 'N/A';

                                                        \Log::info('Pipeline started - state check', [
                                                            'document_id' => $record->id,
                                                            'pipeline_status' => $pipelineStatus,
                                                            'steps_count' => $stepsCount,
                                                            'extraction_status' => $record->extraction_status,
                                                        ]);

                                                        Notification::make()
                                                            ->title('Pipeline relancé')
                                                            ->body("Type: {$documentType} | Étapes: {$stepsCount} | Status: {$pipelineStatus} | Queue: {$queueDriver} | Jobs: {$jobCount}")
                                                            ->success()
                                                            ->send();

                                                        // Force page refresh to show updated pipeline
                                                        return redirect(request()->header('Referer'));

                                                    } catch (\Throwable $e) {
                                                        \Log::error('Pipeline start failed', [
                                                            'document_id' => $record->id,
                                                            'error' => $e->getMessage(),
                                                            'trace' => $e->getTraceAsString(),
                                                        ]);

                                                        Notification::make()
                                                            ->title('Erreur lors du lancement')
                                                            ->body($e->getMessage())
                                                            ->danger()
                                                            ->persistent()
                                                            ->send();
                                                    }
                                                }),

                                            Forms\Components\Actions\Action::make('continue_pipeline')
                                                ->label('Continuer depuis l\'échec')
                                                ->icon('heroicon-o-play')
                                                ->color('success')
                                                ->visible(fn ($record) => in_array($record?->pipeline_steps['status'] ?? '', ['failed', 'error']))
                                                ->action(function ($record) {
                                                    $pipelineData = $record->pipeline_steps ?? [];
                                                    $failedIndex = null;
                                                    foreach (($pipelineData['steps'] ?? []) as $index => $step) {
                                                        // Check for both 'failed' and 'error' status
                                                        if (in_array($step['status'] ?? '', ['failed', 'error'])) {
                                                            $failedIndex = $index;
                                                            break;
                                                        }
                                                    }
                                                    if ($failedIndex !== null) {
                                                        $orchestrator = app(\App\Services\Pipeline\PipelineOrchestratorService::class);
                                                        $orchestrator->relaunchStep($record, $failedIndex);
                                                        Notification::make()
                                                            ->title('Étape relancée')
                                                            ->body("Étape {$failedIndex} remise en file d'attente")
                                                            ->success()
                                                            ->persistent()
                                                            ->send();
                                                    } else {
                                                        Notification::make()
                                                            ->title('Aucune étape en échec trouvée')
                                                            ->warning()
                                                            ->send();
                                                    }
                                                }),
                                        ])->columnSpanFull(),
                                    ]),

                                // Extracted Text (collapsible)
                                Forms\Components\Section::make('Texte extrait (Markdown)')
                                    ->schema([
                                        Forms\Components\Textarea::make('extracted_text')
                                            ->label('')
                                            ->rows(15)
                                            ->columnSpanFull()
                                            ->hint('Contenu Markdown généré par le pipeline'),
                                    ])
                                    ->collapsed()
                                    ->visible(fn ($record) => $record?->extracted_text),

                                // Error Section
                                Forms\Components\Section::make('Erreur extraction')
                                    ->schema([
                                        Forms\Components\Textarea::make('extraction_error')
                                            ->label('')
                                            ->rows(3)
                                            ->disabled()
                                            ->columnSpanFull(),
                                    ])
                                    ->visible(fn ($record) => $record?->extraction_error),
                            ])
                            ->visible(fn ($record) => $record !== null),

                        Forms\Components\Tabs\Tab::make('Indexation')
                            ->icon('heroicon-o-magnifying-glass')
                            ->schema([
                                Forms\Components\Section::make('Statut indexation')
                                    ->schema([
                                        Forms\Components\Toggle::make('is_indexed')
                                            ->label('Indexé dans Qdrant')
                                            ->disabled(),

                                        Forms\Components\Placeholder::make('indexed_at_display')
                                            ->label('Indexé le')
                                            ->content(fn ($record) => $record?->indexed_at?->format('d/m/Y H:i') ?? '-'),

                                        Forms\Components\Select::make('chunk_strategy')
                                            ->label('Stratégie de chunking')
                                            ->options([
                                                'sentence' => 'Par phrase',
                                                'paragraph' => 'Par paragraphe',
                                                'fixed_size' => 'Taille fixe',
                                                'recursive' => 'Récursif',
                                                'llm_assisted' => 'Assisté par LLM',
                                            ])
                                            ->default('sentence'),
                                    ])
                                    ->columns(3),

                                Forms\Components\Section::make('Réponses LLM (données brutes)')
                                    ->schema([
                                        Forms\Components\Placeholder::make('llm_chunking_info')
                                            ->label('')
                                            ->content(function ($record) {
                                                $metadata = $record?->extraction_metadata ?? [];
                                                $llmData = $metadata['llm_chunking'] ?? null;

                                                if (!$llmData) {
                                                    return 'Aucune donnée LLM disponible';
                                                }

                                                $html = '<div class="space-y-2 mb-4">';
                                                $html .= '<div class="text-sm"><strong>Modèle:</strong> ' . e($llmData['model'] ?? '-') . '</div>';
                                                $html .= '<div class="text-sm"><strong>Traité le:</strong> ' . ($llmData['processed_at'] ? \Carbon\Carbon::parse($llmData['processed_at'])->format('d/m/Y H:i') : '-') . '</div>';
                                                $html .= '<div class="text-sm"><strong>Fenêtres:</strong> ' . ($llmData['window_count'] ?? 0) . '</div>';
                                                $html .= '</div>';

                                                return new \Illuminate\Support\HtmlString($html);
                                            })
                                            ->columnSpanFull(),

                                        Forms\Components\Placeholder::make('llm_raw_responses')
                                            ->label('Réponses JSON brutes')
                                            ->content(function ($record) {
                                                $metadata = $record?->extraction_metadata ?? [];
                                                $llmData = $metadata['llm_chunking'] ?? null;
                                                $responses = $llmData['responses'] ?? [];

                                                if (empty($responses)) {
                                                    return 'Aucune réponse disponible';
                                                }

                                                $html = '<div class="space-y-4">';
                                                foreach ($responses as $resp) {
                                                    $windowIndex = $resp['window_index'] ?? 0;
                                                    $rawResponse = $resp['raw_response'] ?? '';
                                                    $parsedChunks = $resp['parsed_chunks'] ?? 0;

                                                    // Formatter le JSON pour l'affichage
                                                    $formattedJson = $rawResponse;
                                                    $decoded = json_decode($rawResponse, true);
                                                    if ($decoded !== null) {
                                                        $formattedJson = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                                                    }

                                                    $html .= sprintf(
                                                        '<div class="border border-gray-200 dark:border-gray-700 rounded-lg">
                                                            <div class="px-3 py-2 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 flex justify-between">
                                                                <span class="font-medium">Fenêtre #%d</span>
                                                                <span class="text-sm text-gray-500">%d chunks générés</span>
                                                            </div>
                                                            <pre class="p-3 text-xs overflow-x-auto max-h-64 overflow-y-auto bg-gray-900 text-gray-100 rounded-b-lg"><code>%s</code></pre>
                                                        </div>',
                                                        $windowIndex,
                                                        $parsedChunks,
                                                        e($formattedJson)
                                                    );
                                                }
                                                $html .= '</div>';

                                                return new \Illuminate\Support\HtmlString($html);
                                            })
                                            ->columnSpanFull(),
                                    ])
                                    ->collapsed()
                                    ->visible(fn ($record) => $record?->chunk_strategy === 'llm_assisted'),
                            ])
                            ->visible(fn ($record) => $record !== null),

                        Forms\Components\Tabs\Tab::make('Chunks')
                            ->icon('heroicon-o-squares-2x2')
                            ->schema([
                                Forms\Components\Section::make('Chunks indexés')
                                    ->headerActions([
                                        Forms\Components\Actions\Action::make('manage_chunks')
                                            ->label('Gérer les chunks')
                                            ->icon('heroicon-o-pencil-square')
                                            ->color('primary')
                                            ->url(fn ($record) => $record ? static::getUrl('chunks', ['record' => $record]) : null),
                                    ])
                                    ->schema([
                                        Forms\Components\Placeholder::make('chunks_list')
                                            ->label('')
                                            ->content(function ($record) {
                                                if (!$record || $record->chunks->isEmpty()) {
                                                    return 'Aucun chunk disponible';
                                                }

                                                // Charger les chunks avec leur catégorie
                                                $chunks = $record->chunks()->with('category')->orderBy('chunk_index')->get();

                                                $html = '<div class="space-y-4">';
                                                foreach ($chunks as $chunk) {
                                                    $status = $chunk->is_indexed ? '✓ Indexé' : '✗ Non indexé';
                                                    $statusColor = $chunk->is_indexed ? 'text-success-600' : 'text-danger-600';
                                                    $tokens = $chunk->token_count ?? 0;

                                                    // Badge de catégorie
                                                    $categoryBadge = '';
                                                    if ($chunk->category) {
                                                        $color = $chunk->category->color ?? '#6B7280';
                                                        $categoryBadge = sprintf(
                                                            '<span class="text-xs px-2 py-0.5 rounded" style="background-color: %s20; color: %s;">%s</span>',
                                                            $color,
                                                            $color,
                                                            e($chunk->category->name)
                                                        );
                                                    } else {
                                                        $categoryBadge = '<span class="text-xs px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-500">Sans catégorie</span>';
                                                    }

                                                    $html .= sprintf(
                                                        '<div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                                                            <div class="flex justify-between items-center mb-2">
                                                                <div class="flex items-center gap-2">
                                                                    <span class="font-semibold">Chunk #%d</span>
                                                                    %s
                                                                </div>
                                                                <div class="flex items-center gap-3">
                                                                    <span class="text-xs text-gray-500">%d tokens</span>
                                                                    <span class="text-xs %s">%s</span>
                                                                </div>
                                                            </div>
                                                            <div class="text-sm text-gray-600 dark:text-gray-400 whitespace-pre-wrap max-h-32 overflow-y-auto">%s</div>
                                                        </div>',
                                                        $chunk->chunk_index,
                                                        $categoryBadge,
                                                        $tokens,
                                                        $statusColor,
                                                        $status,
                                                        e(\Illuminate\Support\Str::limit($chunk->content, 500))
                                                    );
                                                }
                                                $html .= '</div>';

                                                return new \Illuminate\Support\HtmlString($html);
                                            })
                                            ->columnSpanFull(),
                                    ]),
                            ])
                            ->visible(fn ($record) => $record !== null && $record->chunk_count > 0),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Titre')
                    ->default(fn ($record) => $record->original_name)
                    ->searchable(['title', 'original_name'])
                    ->sortable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('agent.name')
                    ->label('Agent')
                    ->searchable()
                    ->sortable()
                    ->badge(),

                Tables\Columns\TextColumn::make('document_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pdf' => 'danger',
                        'docx', 'doc' => 'info',
                        'txt', 'md' => 'gray',
                        'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'webp' => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('extraction_status')
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

                Tables\Columns\IconColumn::make('is_indexed')
                    ->label('Indexé')
                    ->boolean(),

                Tables\Columns\TextColumn::make('chunk_count')
                    ->label('Chunks')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('file_size')
                    ->label('Taille')
                    ->formatStateUsing(fn ($record) => $record->getFileSizeForHumans())
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ajouté')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('agent')
                    ->relationship('agent', 'name'),

                Tables\Filters\SelectFilter::make('extraction_status')
                    ->label('Statut extraction')
                    ->options([
                        'pending' => 'En attente',
                        'processing' => 'En cours',
                        'completed' => 'Terminé',
                        'failed' => 'Échoué',
                    ]),

                Tables\Filters\TernaryFilter::make('is_indexed')
                    ->label('Indexé'),

                Tables\Filters\SelectFilter::make('document_type')
                    ->label('Type')
                    ->options([
                        'pdf' => 'PDF',
                        'html' => 'HTML',
                        'md' => 'Markdown',
                        'png' => 'PNG',
                        'jpg' => 'JPG',
                        'jpeg' => 'JPEG',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label('Télécharger')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn ($record) => route('admin.documents.download', $record))
                    ->openUrlInNewTab()
                    ->visible(fn ($record) => Storage::disk('local')->exists($record->storage_path ?? '')),

                Tables\Actions\Action::make('reprocess')
                    ->label('Retraiter')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Retraiter le document')
                    ->modalDescription('Le document sera ré-extrait, re-découpé et ré-indexé. Continuer ?')
                    ->action(function (Document $record) {
                        $record->update([
                            'extraction_status' => 'pending',
                            'extraction_error' => null,
                            'is_indexed' => false,
                        ]);

                        ProcessDocumentJob::dispatch($record);

                        Notification::make()
                            ->title('Traitement relancé')
                            ->body("Le document \"{$record->original_name}\" est en cours de retraitement.")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('index')
                    ->label('Indexer')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('success')
                    ->visible(fn ($record) => $record->extraction_status === 'completed' && !$record->is_indexed)
                    ->action(function (Document $record) {
                        // Relancer le job pour indexer uniquement
                        ProcessDocumentJob::dispatch($record, reindex: true);

                        Notification::make()
                            ->title('Indexation en cours')
                            ->body("Le document \"{$record->original_name}\" est en cours d'indexation.")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('chunks')
                    ->label('Chunks')
                    ->icon('heroicon-o-squares-2x2')
                    ->color('info')
                    ->visible(fn ($record) => $record->chunk_count > 0)
                    ->url(fn ($record) => static::getUrl('chunks', ['record' => $record])),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),

                    Tables\Actions\BulkAction::make('reprocess')
                        ->label('Retraiter')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $record) {
                                $record->update([
                                    'extraction_status' => 'pending',
                                    'extraction_error' => null,
                                    'is_indexed' => false,
                                ]);
                                ProcessDocumentJob::dispatch($record);
                                $count++;
                            }

                            Notification::make()
                                ->title('Traitement en cours')
                                ->body("{$count} document(s) en cours de retraitement.")
                                ->success()
                                ->send();
                        }),

                    Tables\Actions\BulkAction::make('index')
                        ->label('Indexer')
                        ->icon('heroicon-o-magnifying-glass')
                        ->color('success')
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->extraction_status === 'completed' && !$record->is_indexed) {
                                    ProcessDocumentJob::dispatch($record, reindex: true);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->title('Indexation en cours')
                                ->body("{$count} document(s) en cours d'indexation.")
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
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
            'index' => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'edit' => Pages\EditDocument::route('/{record}/edit'),
            'chunks' => Pages\ManageChunks::route('/{record}/chunks'),
            'bulk-import' => Pages\BulkImportDocuments::route('/bulk-import'),
        ];
    }
}

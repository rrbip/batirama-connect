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
                                            }),
                                        Forms\Components\Select::make('category')
                                            ->label('Catégorie')
                                            ->options([
                                                'documentation' => 'Documentation',
                                                'faq' => 'FAQ',
                                                'product' => 'Produit',
                                                'support' => 'Support',
                                                'legal' => 'Légal',
                                                'other' => 'Autre',
                                            ]),

                                        Forms\Components\Select::make('extraction_method')
                                            ->label('Méthode d\'extraction (PDF)')
                                            ->options([
                                                'auto' => 'Automatique (recommandé)',
                                                'text' => 'Texte uniquement',
                                                'ocr' => 'OCR (Tesseract)',
                                                'vision' => 'Vision IA (tableaux)',
                                            ])
                                            ->default('auto')
                                            ->helperText('Vision: préserve la structure des tableaux. OCR: pour les PDF scannés.')
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
                                // Pipeline Status Overview
                                Forms\Components\Section::make('Statut du pipeline')
                                    ->schema([
                                        Forms\Components\Placeholder::make('pipeline_status_display')
                                            ->label('')
                                            ->content(function ($record) {
                                                $pipelineData = $record?->pipeline_steps ?? [];
                                                $status = $pipelineData['status'] ?? 'not_started';

                                                $statusInfo = match ($status) {
                                                    'not_started' => ['label' => 'Non démarré', 'color' => 'gray', 'icon' => 'clock'],
                                                    'running' => ['label' => 'En cours', 'color' => 'warning', 'icon' => 'arrow-path'],
                                                    'completed' => ['label' => 'Terminé', 'color' => 'success', 'icon' => 'check-circle'],
                                                    'failed' => ['label' => 'Échoué', 'color' => 'danger', 'icon' => 'x-circle'],
                                                    default => ['label' => $status, 'color' => 'gray', 'icon' => 'question-mark-circle'],
                                                };

                                                $startedAt = isset($pipelineData['started_at']) ? \Carbon\Carbon::parse($pipelineData['started_at'])->format('d/m/Y H:i') : '-';
                                                $completedAt = isset($pipelineData['completed_at']) ? \Carbon\Carbon::parse($pipelineData['completed_at'])->format('d/m/Y H:i') : '-';

                                                return new \Illuminate\Support\HtmlString(sprintf(
                                                    '<div class="flex items-center gap-4">
                                                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium bg-%s-100 text-%s-800 dark:bg-%s-900/30 dark:text-%s-300">
                                                            %s
                                                        </span>
                                                        <span class="text-sm text-gray-500">Démarré: %s</span>
                                                        <span class="text-sm text-gray-500">Terminé: %s</span>
                                                    </div>',
                                                    $statusInfo['color'], $statusInfo['color'], $statusInfo['color'], $statusInfo['color'],
                                                    $statusInfo['label'],
                                                    $startedAt,
                                                    $completedAt
                                                ));
                                            })
                                            ->columnSpanFull(),
                                        Forms\Components\Placeholder::make('extraction_method_display')
                                            ->label('Méthode utilisée')
                                            ->content(fn ($record) => match ($record?->extraction_method) {
                                                'auto' => 'Automatique',
                                                'text' => 'Texte uniquement',
                                                'ocr' => 'OCR (Tesseract)',
                                                'vision' => 'Vision IA',
                                                null => '-',
                                                default => $record?->extraction_method,
                                            }),
                                    ]),

                                // Pipeline Steps
                                Forms\Components\Section::make('Étapes du pipeline')
                                    ->schema([
                                        Forms\Components\Placeholder::make('pipeline_steps_display')
                                            ->label('')
                                            ->content(function ($record) {
                                                $pipelineData = $record?->pipeline_steps ?? [];
                                                $steps = $pipelineData['steps'] ?? [];

                                                if (empty($steps)) {
                                                    return new \Illuminate\Support\HtmlString(
                                                        '<div class="text-gray-500 italic">Aucune étape de pipeline configurée</div>'
                                                    );
                                                }

                                                $html = '<div class="space-y-4">';
                                                foreach ($steps as $index => $step) {
                                                    $status = $step['status'] ?? 'pending';
                                                    $statusInfo = match ($status) {
                                                        'pending' => ['label' => 'En attente', 'color' => 'gray', 'bg' => 'bg-gray-100 dark:bg-gray-800'],
                                                        'running' => ['label' => 'En cours', 'color' => 'warning', 'bg' => 'bg-warning-50 dark:bg-warning-900/20'],
                                                        'completed' => ['label' => 'Terminé', 'color' => 'success', 'bg' => 'bg-success-50 dark:bg-success-900/20'],
                                                        'failed' => ['label' => 'Échoué', 'color' => 'danger', 'bg' => 'bg-danger-50 dark:bg-danger-900/20'],
                                                        default => ['label' => $status, 'color' => 'gray', 'bg' => 'bg-gray-100 dark:bg-gray-800'],
                                                    };

                                                    $stepName = $step['name'] ?? "Étape {$index}";
                                                    $tool = $step['tool'] ?? '-';
                                                    $startedAt = isset($step['started_at']) ? \Carbon\Carbon::parse($step['started_at'])->format('H:i:s') : '-';
                                                    $completedAt = isset($step['completed_at']) ? \Carbon\Carbon::parse($step['completed_at'])->format('H:i:s') : '-';
                                                    $duration = isset($step['duration_seconds']) ? round($step['duration_seconds'], 2) . 's' : '-';
                                                    $inputSummary = $step['input_summary'] ?? '-';
                                                    $outputSummary = $step['output_summary'] ?? '-';
                                                    $error = $step['error'] ?? null;

                                                    $html .= sprintf(
                                                        '<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                                                            <div class="px-4 py-3 %s flex justify-between items-center">
                                                                <div class="flex items-center gap-3">
                                                                    <span class="font-semibold">Étape %d: %s</span>
                                                                    <span class="text-xs px-2 py-0.5 rounded bg-gray-200 dark:bg-gray-700">%s</span>
                                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-%s-100 text-%s-800 dark:bg-%s-900/50 dark:text-%s-300">%s</span>
                                                                </div>
                                                                <span class="text-sm text-gray-500">Durée: %s</span>
                                                            </div>
                                                            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                                                                <div class="grid grid-cols-2 gap-4 text-sm">
                                                                    <div>
                                                                        <span class="text-gray-500">Entrée:</span>
                                                                        <span class="ml-2">%s</span>
                                                                    </div>
                                                                    <div>
                                                                        <span class="text-gray-500">Sortie:</span>
                                                                        <span class="ml-2">%s</span>
                                                                    </div>
                                                                    <div>
                                                                        <span class="text-gray-500">Début:</span>
                                                                        <span class="ml-2">%s</span>
                                                                    </div>
                                                                    <div>
                                                                        <span class="text-gray-500">Fin:</span>
                                                                        <span class="ml-2">%s</span>
                                                                    </div>
                                                                </div>
                                                                %s
                                                            </div>
                                                        </div>',
                                                        $statusInfo['bg'],
                                                        $index + 1,
                                                        e($stepName),
                                                        e($tool),
                                                        $statusInfo['color'], $statusInfo['color'], $statusInfo['color'], $statusInfo['color'],
                                                        $statusInfo['label'],
                                                        $duration,
                                                        e($inputSummary),
                                                        e($outputSummary),
                                                        $startedAt,
                                                        $completedAt,
                                                        $error ? sprintf('<div class="mt-3 p-2 bg-danger-50 dark:bg-danger-900/20 rounded text-sm text-danger-700 dark:text-danger-300"><strong>Erreur:</strong> %s</div>', e($error)) : ''
                                                    );
                                                }
                                // Section Pipeline Vision (uniquement pour extraction vision)
                                Forms\Components\Section::make('Pipeline d\'extraction Vision')
                                    ->description('Détails du traitement PDF → Images → Markdown')
                                    ->schema([
                                        Forms\Components\Placeholder::make('vision_pipeline_info')
                                            ->label('')
                                            ->content(function ($record) {
                                                $metadata = $record?->extraction_metadata ?? [];
                                                $visionData = $metadata['vision_extraction'] ?? null;

                                                if (!$visionData) {
                                                    return new \Illuminate\Support\HtmlString(
                                                        '<div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg">
                                                            <div class="flex items-center gap-2 text-yellow-700 dark:text-yellow-400">
                                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                                </svg>
                                                                <strong>Métadonnées de traçage non disponibles</strong>
                                                            </div>
                                                            <p class="mt-2 text-sm text-yellow-600 dark:text-yellow-500">
                                                                Ce document a été extrait avant l\'ajout du système de traçage du pipeline.
                                                                Pour voir le détail complet de chaque étape, utilisez le bouton <strong>Retraiter</strong>.
                                                            </p>
                                                        </div>'
                                                    );
                                                }

                                                // Informations générales
                                                $html = '<div class="space-y-6">';

                                                // 1. Étape PDF → Images
                                                $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                                                $html .= '<div class="px-4 py-2 bg-blue-50 dark:bg-blue-900/20 border-b border-gray-200 dark:border-gray-700">';
                                                $html .= '<h4 class="font-medium text-blue-700 dark:text-blue-400">1. PDF → Images</h4>';
                                                $html .= '</div>';
                                                $html .= '<div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">';
                                                $html .= '<div><span class="text-gray-500">Outil:</span><br><strong class="text-blue-600">' . e($visionData['pdf_converter'] ?? 'pdftoppm') . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Pages totales:</span><br><strong>' . ($visionData['total_pages'] ?? '-') . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">DPI:</span><br><strong>' . ($visionData['dpi'] ?? '300') . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Stockage:</span><br><strong class="font-mono text-xs">' . e($visionData['storage_path'] ?? '-') . '</strong></div>';
                                                $html .= '</div></div>';

                                                // 2. Étape Images → Markdown (Vision LLM)
                                                $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                                                $html .= '<div class="px-4 py-2 bg-purple-50 dark:bg-purple-900/20 border-b border-gray-200 dark:border-gray-700">';
                                                $html .= '<h4 class="font-medium text-purple-700 dark:text-purple-400">2. Images → Markdown (Vision IA)</h4>';
                                                $html .= '</div>';
                                                $html .= '<div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">';
                                                $html .= '<div><span class="text-gray-500">Bibliothèque:</span><br><strong class="text-purple-600">' . e($visionData['vision_library'] ?? 'Ollama API') . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Modèle:</span><br><strong class="text-purple-600">' . e($visionData['vision_model'] ?? $visionData['model'] ?? '-') . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Pages traitées:</span><br><strong>' . ($visionData['pages_processed'] ?? '-') . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Durée totale:</span><br><strong>' . ($visionData['duration_seconds'] ?? '-') . 's</strong></div>';
                                                $html .= '</div></div>';

                                                // 3. Détail par page
                                                $pages = $visionData['pages'] ?? [];
                                                $storagePath = $visionData['storage_path'] ?? '';

                                                // Récupérer le disque de stockage depuis les métadonnées, sinon utiliser les settings actuels
                                                $storageDisk = $visionData['storage_disk']
                                                    ?? \App\Models\VisionSetting::getInstance()->storage_disk
                                                    ?? 'public';

                                                // Message si le store_images était désactivé ou les images ont été supprimées
                                                $imagesConfigured = $visionData['store_images'] ?? true;

                                                if (!empty($pages)) {
                                                    $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                                                    $html .= '<div class="px-4 py-2 bg-green-50 dark:bg-green-900/20 border-b border-gray-200 dark:border-gray-700">';
                                                    $html .= '<h4 class="font-medium text-green-700 dark:text-green-400">3. Détail par page</h4>';
                                                    $html .= '</div>';
                                                    $html .= '<div class="p-4">';
                                                    $html .= '<table class="w-full text-sm">';
                                                    $html .= '<thead class="text-gray-500 border-b dark:border-gray-700">';
                                                    $html .= '<tr><th class="text-left py-2">Page</th><th class="text-left py-2">Image</th><th class="text-left py-2">Markdown</th><th class="text-right py-2">Taille MD</th><th class="text-right py-2">Temps</th><th class="text-center py-2">Actions</th></tr>';
                                                    $html .= '</thead><tbody>';

                                                    foreach ($pages as $index => $page) {
                                                        $imagePath = $page['image_path'] ?? '';
                                                        $mdPath = $page['markdown_path'] ?? '';
                                                        $mdContent = $page['markdown_content'] ?? null;
                                                        $mdLength = $page['markdown_length'] ?? 0;
                                                        $time = $page['processing_time'] ?? 0;
                                                        $pageNum = $page['page'] ?? 0;

                                                        // Générer l'URL de l'image si disponible
                                                        // Essayer d'abord le disque des métadonnées, puis fallback sur l'autre
                                                        $imageUrl = '';
                                                        $imageExists = false;
                                                        if ($imagePath) {
                                                            // Essayer le disque principal
                                                            if (\Storage::disk($storageDisk)->exists($imagePath)) {
                                                                $imageUrl = \Storage::disk($storageDisk)->url($imagePath);
                                                                $imageExists = true;
                                                            }
                                                            // Fallback: essayer l'autre disque
                                                            elseif ($storageDisk === 'public' && \Storage::disk('local')->exists($imagePath)) {
                                                                // Les fichiers 'local' ne sont pas accessibles via URL
                                                                $imageExists = true;
                                                            }
                                                            elseif ($storageDisk === 'local' && \Storage::disk('public')->exists($imagePath)) {
                                                                $imageUrl = \Storage::disk('public')->url($imagePath);
                                                                $imageExists = true;
                                                            }
                                                        }

                                                        // Raccourcir les chemins pour l'affichage
                                                        $imageDisplay = $imagePath ? basename($imagePath) : '-';
                                                        $mdDisplay = $mdPath ? basename($mdPath) : '-';

                                                        // Boutons d'action
                                                        $actions = '<div class="flex gap-1 justify-center">';
                                                        if ($imageUrl) {
                                                            $actions .= '<a href="' . e($imageUrl) . '" target="_blank" class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-600 bg-blue-50 dark:bg-blue-900/30 dark:text-blue-400 rounded hover:bg-blue-100 dark:hover:bg-blue-900/50" title="Voir l\'image"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg></a>';
                                                        } elseif ($imageExists && !$imageUrl) {
                                                            // Image existe sur disque local mais pas accessible via URL
                                                            $actions .= '<span class="inline-flex items-center px-2 py-1 text-xs font-medium text-gray-500 bg-gray-100 dark:bg-gray-700 dark:text-gray-400 rounded" title="Image stockée localement (non accessible via URL)"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg></span>';
                                                        } elseif ($imagePath && !$imageExists) {
                                                            // Image dans les métadonnées mais fichier introuvable
                                                            $actions .= '<span class="inline-flex items-center px-2 py-1 text-xs font-medium text-danger-500 bg-danger-50 dark:bg-danger-900/30 dark:text-danger-400 rounded" title="Image non trouvée - Retraitez le document pour régénérer"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></span>';
                                                        } elseif (!$imagePath && !$imagesConfigured) {
                                                            // store_images était désactivé
                                                            $actions .= '<span class="inline-flex items-center px-2 py-1 text-xs font-medium text-gray-500 bg-gray-100 dark:bg-gray-700 dark:text-gray-400 rounded" title="Stockage images désactivé lors du traitement">-</span>';
                                                        }
                                                        if ($mdContent) {
                                                            $actions .= '<button type="button" onclick="document.getElementById(\'md-content-' . $pageNum . '\').classList.toggle(\'hidden\')" class="inline-flex items-center px-2 py-1 text-xs font-medium text-purple-600 bg-purple-50 dark:bg-purple-900/30 dark:text-purple-400 rounded hover:bg-purple-100 dark:hover:bg-purple-900/50" title="Voir le markdown"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg></button>';
                                                        }
                                                        $actions .= '</div>';

                                                        $html .= sprintf(
                                                            '<tr class="border-b dark:border-gray-700">
                                                                <td class="py-2 font-medium">%d</td>
                                                                <td class="py-2 text-gray-500 font-mono text-xs">%s</td>
                                                                <td class="py-2 text-gray-500 font-mono text-xs">%s</td>
                                                                <td class="py-2 text-right">%s</td>
                                                                <td class="py-2 text-right">%ss</td>
                                                                <td class="py-2 text-center">%s</td>
                                                            </tr>',
                                                            $pageNum,
                                                            e($imageDisplay),
                                                            e($mdDisplay),
                                                            number_format($mdLength) . ' chars',
                                                            $time,
                                                            $actions
                                                        );

                                                        // Ligne expandable pour le contenu markdown
                                                        if ($mdContent) {
                                                            $html .= '<tr id="md-content-' . $pageNum . '" class="hidden">';
                                                            $html .= '<td colspan="6" class="p-0">';
                                                            $html .= '<div class="p-4 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700">';
                                                            $html .= '<div class="flex items-center justify-between mb-2">';
                                                            $html .= '<span class="text-xs font-medium text-gray-500">Markdown extrait - Page ' . $pageNum . '</span>';
                                                            $html .= '<button onclick="navigator.clipboard.writeText(document.getElementById(\'md-text-' . $pageNum . '\').innerText)" class="text-xs text-primary-600 hover:underline">Copier</button>';
                                                            $html .= '</div>';
                                                            $html .= '<pre id="md-text-' . $pageNum . '" class="p-3 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded text-xs font-mono whitespace-pre-wrap overflow-x-auto max-h-64 overflow-y-auto">' . e($mdContent) . '</pre>';
                                                            $html .= '</div></td></tr>';
                                                        }
                                                    }
                                                    $html .= '</tbody></table>';
                                                    $html .= '</div></div>';
                                                }

                                                // 4. Étape Chunking + Indexation
                                                $chunksUrl = '/admin/documents/' . $record->id . '/chunks';
                                                $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                                                $html .= '<div class="px-4 py-2 bg-amber-50 dark:bg-amber-900/20 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">';
                                                $html .= '<h4 class="font-medium text-amber-700 dark:text-amber-400">4. Chunking + Indexation</h4>';
                                                $html .= '<a href="' . $chunksUrl . '" class="inline-flex items-center gap-1 px-3 py-1 text-xs font-medium text-amber-700 bg-amber-100 rounded-full hover:bg-amber-200 transition">';
                                                $html .= '<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg>';
                                                $html .= 'Gérer les chunks</a>';
                                                $html .= '</div>';
                                                $html .= '<div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">';

                                                $chunkStrategy = $record->chunk_strategy ?? 'sentence';
                                                $strategyLabel = match ($chunkStrategy) {
                                                    'sentence' => 'Par phrase',
                                                    'paragraph' => 'Par paragraphe',
                                                    'fixed_size' => 'Taille fixe',
                                                    'markdown' => 'Markdown (headers)',
                                                    'llm_assisted' => 'Assisté par LLM',
                                                    default => $chunkStrategy,
                                                };

                                                $html .= '<div><span class="text-gray-500">Stratégie:</span><br><strong class="text-amber-600">' . e($strategyLabel) . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Chunks générés:</span><br><strong>' . ($record->chunk_count ?? 0) . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Vectorisation:</span><br><strong class="text-amber-600">Ollama (nomic-embed-text)</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Base vectorielle:</span><br><strong class="text-amber-600">Qdrant</strong></div>';
                                                $html .= '</div>';

                                                // Zone expandable avec les chunks
                                                $chunks = $record->chunks()->orderBy('chunk_index')->get();
                                                if ($chunks->isNotEmpty()) {
                                                    $html .= '<details class="border-t border-gray-200 dark:border-gray-700">';
                                                    $html .= '<summary class="px-4 py-2 text-sm font-medium text-amber-600 dark:text-amber-400 cursor-pointer hover:bg-amber-50 dark:hover:bg-amber-900/20">Voir les ' . $chunks->count() . ' chunks</summary>';
                                                    $html .= '<div class="p-4 space-y-3 max-h-96 overflow-y-auto">';
                                                    foreach ($chunks as $chunk) {
                                                        $statusIcon = $chunk->is_indexed ? '✓' : '✗';
                                                        $statusColor = $chunk->is_indexed ? 'text-success-600' : 'text-danger-600';
                                                        $html .= '<div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">';
                                                        $html .= '<div class="flex justify-between items-center mb-2">';
                                                        $html .= '<span class="font-medium text-sm">Chunk #' . $chunk->chunk_index . '</span>';
                                                        $html .= '<div class="flex items-center gap-2">';
                                                        $html .= '<span class="text-xs text-gray-500">' . ($chunk->token_count ?? 0) . ' tokens</span>';
                                                        $html .= '<span class="text-xs ' . $statusColor . '">' . $statusIcon . ' ' . ($chunk->is_indexed ? 'Indexé' : 'Non indexé') . '</span>';
                                                        $html .= '</div></div>';
                                                        $html .= '<div class="text-xs text-gray-600 dark:text-gray-400 whitespace-pre-wrap max-h-24 overflow-y-auto">' . e(\Illuminate\Support\Str::limit($chunk->content, 300)) . '</div>';
                                                        $html .= '</div>';
                                                    }
                                                    $html .= '</div></details>';
                                                }
                                                $html .= '</div>';

                                                // 5. Erreurs éventuelles
                                                $errors = $visionData['errors'] ?? [];
                                                if (!empty($errors)) {
                                                    $html .= '<div class="border border-red-200 dark:border-red-700 rounded-lg overflow-hidden">';
                                                    $html .= '<div class="px-4 py-2 bg-red-50 dark:bg-red-900/20 border-b border-red-200 dark:border-red-700">';
                                                    $html .= '<h4 class="font-medium text-red-700 dark:text-red-400">Erreurs</h4>';
                                                    $html .= '</div>';
                                                    $html .= '<div class="p-4 space-y-2">';
                                                    foreach ($errors as $error) {
                                                        $html .= sprintf(
                                                            '<div class="text-sm text-red-600 dark:text-red-400">Page %d: %s</div>',
                                                            $error['page'] ?? 0,
                                                            e($error['error'] ?? 'Erreur inconnue')
                                                        );
                                                    }
                                                    $html .= '</div></div>';
                                                }

                                                $html .= '</div>';

                                                return new \Illuminate\Support\HtmlString($html);
                                            })
                                            ->columnSpanFull(),
                                    ])
                                    ->collapsible()
                                    ->visible(fn ($record) => $record?->extraction_method === 'vision' || !empty($record?->extraction_metadata['vision_extraction'] ?? null)),

                                // Section Pipeline HTML (uniquement pour extraction HTML)
                                Forms\Components\Section::make('Pipeline d\'extraction HTML')
                                    ->description('Détails de la conversion HTML → Markdown')
                                    ->schema([
                                        Forms\Components\Placeholder::make('html_pipeline_info')
                                            ->label('')
                                            ->content(function ($record) {
                                                $metadata = $record?->extraction_metadata ?? [];
                                                $htmlData = $metadata['html_extraction'] ?? null;

                                                if (!$htmlData) {
                                                    return new \Illuminate\Support\HtmlString(
                                                        '<div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg">
                                                            <div class="flex items-center gap-2 text-yellow-700 dark:text-yellow-400">
                                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                                </svg>
                                                                <strong>Métadonnées de traçage non disponibles</strong>
                                                            </div>
                                                            <p class="mt-2 text-sm text-yellow-600 dark:text-yellow-500">
                                                                Ce document a été extrait avant l\'ajout du système de traçage du pipeline.
                                                                Pour voir le détail complet de chaque étape, utilisez le bouton <strong>Retraiter</strong>.
                                                            </p>
                                                        </div>'
                                                    );
                                                }

                                                $html = '<div class="space-y-6">';

                                                // 1. Étape Fetch HTML
                                                $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                                                $html .= '<div class="px-4 py-2 bg-blue-50 dark:bg-blue-900/20 border-b border-gray-200 dark:border-gray-700">';
                                                $html .= '<h4 class="font-medium text-blue-700 dark:text-blue-400">1. Récupération HTML</h4>';
                                                $html .= '</div>';
                                                $html .= '<div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">';
                                                $html .= '<div><span class="text-gray-500">Source:</span><br><strong class="text-blue-600">URL crawlée</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Taille HTML:</span><br><strong>' . number_format($htmlData['html_size'] ?? 0) . ' chars</strong></div>';
                                                $html .= '<div><span class="text-gray-500">URL:</span><br><span class="text-xs text-gray-400 truncate block max-w-[200px]" title="' . e($record->source_url ?? '-') . '">' . e(\Illuminate\Support\Str::limit($record->source_url ?? '-', 40)) . '</span></div>';
                                                $html .= '<div class="flex items-center justify-end">';
                                                if (!empty($htmlData['original_html'])) {
                                                    $html .= '<button type="button" onclick="document.getElementById(\'html-original\').classList.toggle(\'hidden\')" class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-600 bg-blue-50 dark:bg-blue-900/30 dark:text-blue-400 rounded hover:bg-blue-100" title="Voir HTML original"><svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path></svg>HTML</button>';
                                                }
                                                $html .= '</div>';
                                                $html .= '</div>';
                                                // Zone expandable pour HTML original
                                                if (!empty($htmlData['original_html'])) {
                                                    $html .= '<div id="html-original" class="hidden border-t border-gray-200 dark:border-gray-700 p-4 bg-gray-50 dark:bg-gray-800">';
                                                    $html .= '<div class="flex items-center justify-between mb-2">';
                                                    $html .= '<span class="text-xs font-medium text-gray-500">HTML Original</span>';
                                                    $html .= '<button onclick="navigator.clipboard.writeText(document.getElementById(\'html-original-content\').innerText)" class="text-xs text-primary-600 hover:underline">Copier</button>';
                                                    $html .= '</div>';
                                                    $html .= '<pre id="html-original-content" class="p-3 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded text-xs font-mono whitespace-pre-wrap overflow-x-auto max-h-64 overflow-y-auto">' . e(\Illuminate\Support\Str::limit($htmlData['original_html'], 5000)) . '</pre>';
                                                    $html .= '</div>';
                                                }
                                                $html .= '</div>';

                                                // 2. Étape Nettoyage HTML
                                                $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                                                $html .= '<div class="px-4 py-2 bg-orange-50 dark:bg-orange-900/20 border-b border-gray-200 dark:border-gray-700">';
                                                $html .= '<h4 class="font-medium text-orange-700 dark:text-orange-400">2. Nettoyage HTML</h4>';
                                                $html .= '</div>';
                                                $html .= '<div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">';
                                                $html .= '<div><span class="text-gray-500">Taille après nettoyage:</span><br><strong>' . number_format($htmlData['cleaned_html_size'] ?? 0) . ' chars</strong></div>';
                                                $compressionRatio = $htmlData['compression_ratio'] ?? 0;
                                                $html .= '<div><span class="text-gray-500">Compression:</span><br><strong class="text-green-600">' . $compressionRatio . '%</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Éléments supprimés:</span><br><strong>scripts, styles, nav</strong></div>';
                                                $html .= '<div class="flex items-center justify-end">';
                                                if (!empty($htmlData['cleaned_html'])) {
                                                    $html .= '<button type="button" onclick="document.getElementById(\'html-cleaned\').classList.toggle(\'hidden\')" class="inline-flex items-center px-2 py-1 text-xs font-medium text-orange-600 bg-orange-50 dark:bg-orange-900/30 dark:text-orange-400 rounded hover:bg-orange-100" title="Voir HTML nettoyé"><svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path></svg>HTML</button>';
                                                }
                                                $html .= '</div>';
                                                $html .= '</div>';
                                                // Zone expandable pour HTML nettoyé
                                                if (!empty($htmlData['cleaned_html'])) {
                                                    $html .= '<div id="html-cleaned" class="hidden border-t border-gray-200 dark:border-gray-700 p-4 bg-gray-50 dark:bg-gray-800">';
                                                    $html .= '<div class="flex items-center justify-between mb-2">';
                                                    $html .= '<span class="text-xs font-medium text-gray-500">HTML Nettoyé</span>';
                                                    $html .= '<button onclick="navigator.clipboard.writeText(document.getElementById(\'html-cleaned-content\').innerText)" class="text-xs text-primary-600 hover:underline">Copier</button>';
                                                    $html .= '</div>';
                                                    $html .= '<pre id="html-cleaned-content" class="p-3 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded text-xs font-mono whitespace-pre-wrap overflow-x-auto max-h-64 overflow-y-auto">' . e(\Illuminate\Support\Str::limit($htmlData['cleaned_html'], 5000)) . '</pre>';
                                                    $html .= '</div>';
                                                }
                                                $html .= '</div>';

                                                // 3. Étape Conversion Markdown
                                                $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                                                $html .= '<div class="px-4 py-2 bg-purple-50 dark:bg-purple-900/20 border-b border-gray-200 dark:border-gray-700">';
                                                $html .= '<h4 class="font-medium text-purple-700 dark:text-purple-400">3. Conversion → Markdown</h4>';
                                                $html .= '</div>';
                                                $html .= '<div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">';
                                                $html .= '<div><span class="text-gray-500">Convertisseur:</span><br><strong class="text-purple-600">' . e($htmlData['converter'] ?? 'League HTML to Markdown') . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Taille Markdown:</span><br><strong>' . number_format($htmlData['markdown_size'] ?? 0) . ' chars</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Temps:</span><br><strong>' . ($htmlData['processing_time_ms'] ?? 0) . 'ms</strong></div>';
                                                $html .= '<div class="flex items-center justify-end">';
                                                if (!empty($record->extracted_text)) {
                                                    $html .= '<button type="button" onclick="document.getElementById(\'html-markdown\').classList.toggle(\'hidden\')" class="inline-flex items-center px-2 py-1 text-xs font-medium text-purple-600 bg-purple-50 dark:bg-purple-900/30 dark:text-purple-400 rounded hover:bg-purple-100" title="Voir Markdown"><svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>MD</button>';
                                                }
                                                $html .= '</div>';
                                                $html .= '</div>';

                                                // Éléments détectés
                                                $elements = $htmlData['elements_detected'] ?? [];
                                                if (!empty($elements)) {
                                                    $html .= '<div class="border-t border-gray-200 dark:border-gray-700 p-4">';
                                                    $html .= '<div class="text-xs font-medium text-gray-500 mb-2">Éléments structurels détectés</div>';
                                                    $html .= '<div class="flex flex-wrap gap-2">';
                                                    foreach ($elements as $type => $count) {
                                                        $label = match ($type) {
                                                            'headings' => 'Titres',
                                                            'lists' => 'Listes',
                                                            'tables' => 'Tableaux',
                                                            'links' => 'Liens',
                                                            'images' => 'Images',
                                                            'paragraphs' => 'Paragraphes',
                                                            default => $type,
                                                        };
                                                        $html .= sprintf(
                                                            '<span class="px-2 py-1 bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 rounded text-xs">%s: <strong>%d</strong></span>',
                                                            $label,
                                                            $count
                                                        );
                                                    }
                                                    $html .= '</div></div>';
                                                }

                                                // Zone expandable pour Markdown
                                                if (!empty($record->extracted_text)) {
                                                    $html .= '<div id="html-markdown" class="hidden border-t border-gray-200 dark:border-gray-700 p-4 bg-gray-50 dark:bg-gray-800">';
                                                    $html .= '<div class="flex items-center justify-between mb-2">';
                                                    $html .= '<span class="text-xs font-medium text-gray-500">Markdown généré</span>';
                                                    $html .= '<button onclick="navigator.clipboard.writeText(document.getElementById(\'html-markdown-content\').innerText)" class="text-xs text-primary-600 hover:underline">Copier</button>';
                                                    $html .= '</div>';
                                                    $html .= '<pre id="html-markdown-content" class="p-3 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded text-xs font-mono whitespace-pre-wrap overflow-x-auto max-h-64 overflow-y-auto">' . e(\Illuminate\Support\Str::limit($record->extracted_text, 5000)) . '</pre>';
                                                    $html .= '</div>';
                                                }
                                                $html .= '</div>';

                                                // 4. Étape Chunking + Indexation
                                                $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                                                $html .= '<div class="px-4 py-2 bg-amber-50 dark:bg-amber-900/20 border-b border-gray-200 dark:border-gray-700">';
                                                $html .= '<h4 class="font-medium text-amber-700 dark:text-amber-400">4. Chunking + Indexation</h4>';
                                                $html .= '</div>';
                                                $html .= '<div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">';

                                                $chunkStrategy = $record->chunk_strategy ?? 'sentence';
                                                $strategyLabel = match ($chunkStrategy) {
                                                    'sentence' => 'Par phrase',
                                                    'paragraph' => 'Par paragraphe',
                                                    'fixed_size' => 'Taille fixe',
                                                    'markdown' => 'Markdown (headers)',
                                                    'llm_assisted' => 'Assisté par LLM',
                                                    default => $chunkStrategy,
                                                };

                                                $html .= '<div><span class="text-gray-500">Stratégie:</span><br><strong class="text-amber-600">' . e($strategyLabel) . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Chunks générés:</span><br><strong>' . ($record->chunk_count ?? 0) . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Vectorisation:</span><br><strong class="text-amber-600">Ollama (nomic-embed-text)</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Base vectorielle:</span><br><strong class="text-amber-600">Qdrant</strong></div>';
                                                $html .= '</div>';

                                                // Zone expandable avec les chunks
                                                $chunks = $record->chunks()->orderBy('chunk_index')->get();
                                                if ($chunks->isNotEmpty()) {
                                                    $html .= '<details class="border-t border-gray-200 dark:border-gray-700">';
                                                    $html .= '<summary class="px-4 py-2 text-sm font-medium text-amber-600 dark:text-amber-400 cursor-pointer hover:bg-amber-50 dark:hover:bg-amber-900/20">Voir les ' . $chunks->count() . ' chunks</summary>';
                                                    $html .= '<div class="p-4 space-y-3 max-h-96 overflow-y-auto">';
                                                    foreach ($chunks as $chunk) {
                                                        $statusIcon = $chunk->is_indexed ? '✓' : '✗';
                                                        $statusColor = $chunk->is_indexed ? 'text-success-600' : 'text-danger-600';
                                                        $html .= '<div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">';
                                                        $html .= '<div class="flex justify-between items-center mb-2">';
                                                        $html .= '<span class="font-medium text-sm">Chunk #' . $chunk->chunk_index . '</span>';
                                                        $html .= '<div class="flex items-center gap-2">';
                                                        $html .= '<span class="text-xs text-gray-500">' . ($chunk->token_count ?? 0) . ' tokens</span>';
                                                        $html .= '<span class="text-xs ' . $statusColor . '">' . $statusIcon . ' ' . ($chunk->is_indexed ? 'Indexé' : 'Non indexé') . '</span>';
                                                        $html .= '</div></div>';
                                                        $html .= '<div class="text-xs text-gray-600 dark:text-gray-400 whitespace-pre-wrap max-h-24 overflow-y-auto">' . e(\Illuminate\Support\Str::limit($chunk->content, 300)) . '</div>';
                                                        $html .= '</div>';
                                                    }
                                                    $html .= '</div></details>';
                                                }
                                                $html .= '</div>';

                                                // Timestamp
                                                if (!empty($htmlData['extracted_at'])) {
                                                    $html .= '<div class="text-xs text-gray-400 text-right">';
                                                    $html .= 'Extrait le ' . \Carbon\Carbon::parse($htmlData['extracted_at'])->format('d/m/Y H:i');
                                                    $html .= '</div>';
                                                }

                                                $html .= '</div>';

                                                return new \Illuminate\Support\HtmlString($html);
                                            })
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
                                                ->action(function ($record) {
                                                    $orchestrator = app(\App\Services\Pipeline\PipelineOrchestratorService::class);
                                                    $orchestrator->startPipeline($record);
                                                    Notification::make()->title('Pipeline relancé')->success()->send();
                                                }),

                                            Forms\Components\Actions\Action::make('continue_pipeline')
                                                ->label('Continuer depuis l\'échec')
                                                ->icon('heroicon-o-play')
                                                ->color('success')
                                                ->visible(fn ($record) => ($record?->pipeline_steps['status'] ?? '') === 'failed')
                                                ->action(function ($record) {
                                                    $pipelineData = $record->pipeline_steps ?? [];
                                                    $failedIndex = null;
                                                    foreach (($pipelineData['steps'] ?? []) as $index => $step) {
                                                        if (($step['status'] ?? '') === 'failed') {
                                                            $failedIndex = $index;
                                                            break;
                                                        }
                                                    }
                                                    if ($failedIndex !== null) {
                                                        $orchestrator = app(\App\Services\Pipeline\PipelineOrchestratorService::class);
                                                        $orchestrator->relaunchStep($record, $failedIndex);
                                                        Notification::make()->title('Étape relancée')->success()->send();
                                                    }
                                                }),
                                        ])->columnSpanFull(),
                                    ]),

                                // Extracted Text (collapsible)
                                Forms\Components\Section::make('Texte extrait (Markdown)')
                                    ])
                                    ->collapsible()
                                    ->visible(fn ($record) => in_array($record?->document_type, ['html', 'htm']) || !empty($record?->extraction_metadata['html_extraction'] ?? null)),

                                // Section Pipeline OCR (uniquement pour extraction OCR/Tesseract)
                                Forms\Components\Section::make('Pipeline d\'extraction OCR')
                                    ->description('Détails du traitement PDF → Images → Texte (Tesseract)')
                                    ->schema([
                                        Forms\Components\Placeholder::make('ocr_pipeline_info')
                                            ->label('')
                                            ->content(function ($record) {
                                                $metadata = $record?->extraction_metadata ?? [];
                                                $ocrData = $metadata['ocr_extraction'] ?? null;

                                                if (!$ocrData) {
                                                    return new \Illuminate\Support\HtmlString(
                                                        '<div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg">
                                                            <div class="flex items-center gap-2 text-yellow-700 dark:text-yellow-400">
                                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                                </svg>
                                                                <strong>Métadonnées de traçage non disponibles</strong>
                                                            </div>
                                                            <p class="mt-2 text-sm text-yellow-600 dark:text-yellow-500">
                                                                Ce document a été extrait avant l\'ajout du système de traçage du pipeline.
                                                                Pour voir le détail complet de chaque étape, utilisez le bouton <strong>Retraiter</strong>.
                                                            </p>
                                                        </div>'
                                                    );
                                                }

                                                // Déterminer si c'est une image ou un PDF
                                                $isImage = ($ocrData['source_type'] ?? null) === 'image';

                                                $html = '<div class="space-y-6">';

                                                if (!$isImage) {
                                                    // 1. Étape PDF → Images (uniquement pour PDF)
                                                    $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                                                    $html .= '<div class="px-4 py-2 bg-blue-50 dark:bg-blue-900/20 border-b border-gray-200 dark:border-gray-700">';
                                                    $html .= '<h4 class="font-medium text-blue-700 dark:text-blue-400">1. PDF → Images</h4>';
                                                    $html .= '</div>';
                                                    $html .= '<div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">';
                                                    $html .= '<div><span class="text-gray-500">Outil:</span><br><strong class="text-blue-600">' . e($ocrData['pdf_converter'] ?? 'pdftoppm (poppler-utils)') . '</strong></div>';
                                                    $html .= '<div><span class="text-gray-500">Pages totales:</span><br><strong>' . ($ocrData['total_pages'] ?? '-') . '</strong></div>';
                                                    $html .= '<div><span class="text-gray-500">DPI:</span><br><strong>' . ($ocrData['dpi'] ?? '300') . '</strong></div>';
                                                    $html .= '<div><span class="text-gray-500">Temps conversion:</span><br><strong>' . ($ocrData['pdf_conversion_time'] ?? '-') . 's</strong></div>';
                                                    $html .= '</div></div>';
                                                }

                                                // 2. Étape Images → Texte (OCR)
                                                $stepNumber = $isImage ? 1 : 2;
                                                $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                                                $html .= '<div class="px-4 py-2 bg-orange-50 dark:bg-orange-900/20 border-b border-gray-200 dark:border-gray-700">';
                                                $html .= '<h4 class="font-medium text-orange-700 dark:text-orange-400">' . $stepNumber . '. ' . ($isImage ? 'Image' : 'Images') . ' → Texte (OCR)</h4>';
                                                $html .= '</div>';
                                                $html .= '<div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">';
                                                $html .= '<div><span class="text-gray-500">Moteur OCR:</span><br><strong class="text-orange-600">' . e($ocrData['ocr_engine'] ?? 'Tesseract OCR') . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Langues:</span><br><strong>' . e($ocrData['ocr_languages'] ?? 'fra+eng') . '</strong></div>';
                                                if (!$isImage) {
                                                    $html .= '<div><span class="text-gray-500">Pages traitées:</span><br><strong>' . ($ocrData['pages_processed'] ?? '-') . '</strong></div>';
                                                } else {
                                                    $html .= '<div><span class="text-gray-500">Taille texte:</span><br><strong>' . number_format($ocrData['text_length'] ?? 0) . ' chars</strong></div>';
                                                }
                                                $html .= '<div><span class="text-gray-500">Durée totale:</span><br><strong>' . ($ocrData['total_processing_time'] ?? $ocrData['processing_time'] ?? '-') . 's</strong></div>';
                                                $html .= '</div></div>';

                                                // 3. Détail par page (uniquement pour PDF multi-pages)
                                                $pages = $ocrData['pages'] ?? [];
                                                $storagePath = $ocrData['storage_path'] ?? '';
                                                $storageDisk = $ocrData['storage_disk'] ?? 'public';

                                                if (!$isImage && !empty($pages)) {
                                                    $stepNumber++;
                                                    $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                                                    $html .= '<div class="px-4 py-2 bg-green-50 dark:bg-green-900/20 border-b border-gray-200 dark:border-gray-700">';
                                                    $html .= '<h4 class="font-medium text-green-700 dark:text-green-400">' . $stepNumber . '. Détail par page</h4>';
                                                    $html .= '</div>';
                                                    $html .= '<div class="p-4">';
                                                    $html .= '<table class="w-full text-sm">';
                                                    $html .= '<thead class="text-gray-500 border-b dark:border-gray-700">';
                                                    $html .= '<tr><th class="text-left py-2">Page</th><th class="text-left py-2">Image</th><th class="text-right py-2">Taille texte</th><th class="text-right py-2">Temps OCR</th><th class="text-center py-2">Actions</th></tr>';
                                                    $html .= '</thead><tbody>';

                                                    foreach ($pages as $pageIndex => $page) {
                                                        $textLength = $page['text_length'] ?? 0;
                                                        $time = $page['processing_time'] ?? 0;
                                                        $pageNum = $page['page'] ?? ($pageIndex + 1);
                                                        $imagePath = $page['image_path'] ?? '';
                                                        $textContent = $page['text_content'] ?? '';

                                                        // Générer l'URL de l'image si disponible
                                                        $imageUrl = '';
                                                        $imageExists = false;
                                                        if ($imagePath) {
                                                            if (\Storage::disk($storageDisk)->exists($imagePath)) {
                                                                $imageUrl = \Storage::disk($storageDisk)->url($imagePath);
                                                                $imageExists = true;
                                                            } elseif ($storageDisk === 'public' && \Storage::disk('local')->exists($imagePath)) {
                                                                $imageExists = true;
                                                            }
                                                        }

                                                        $imageDisplay = $imagePath ? basename($imagePath) : '-';

                                                        // Boutons d'action
                                                        $actions = '<div class="flex gap-1 justify-center">';
                                                        if ($imageUrl) {
                                                            $actions .= '<a href="' . e($imageUrl) . '" target="_blank" class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-600 bg-blue-50 dark:bg-blue-900/30 dark:text-blue-400 rounded hover:bg-blue-100 dark:hover:bg-blue-900/50" title="Voir l\'image"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg></a>';
                                                        } elseif ($imagePath && !$imageExists) {
                                                            $actions .= '<span class="inline-flex items-center px-2 py-1 text-xs font-medium text-danger-500 bg-danger-50 dark:bg-danger-900/30 dark:text-danger-400 rounded" title="Image non trouvée"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></span>';
                                                        }
                                                        if ($textContent) {
                                                            $actions .= '<button type="button" onclick="document.getElementById(\'ocr-text-' . $pageNum . '\').classList.toggle(\'hidden\')" class="inline-flex items-center px-2 py-1 text-xs font-medium text-orange-600 bg-orange-50 dark:bg-orange-900/30 dark:text-orange-400 rounded hover:bg-orange-100 dark:hover:bg-orange-900/50" title="Voir le texte OCR"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg></button>';
                                                        }
                                                        $actions .= '</div>';

                                                        $html .= sprintf(
                                                            '<tr class="border-b dark:border-gray-700">
                                                                <td class="py-2 font-medium">%d</td>
                                                                <td class="py-2 text-gray-500 font-mono text-xs">%s</td>
                                                                <td class="py-2 text-right">%s chars</td>
                                                                <td class="py-2 text-right">%ss</td>
                                                                <td class="py-2 text-center">%s</td>
                                                            </tr>',
                                                            $pageNum,
                                                            e($imageDisplay),
                                                            number_format($textLength),
                                                            $time,
                                                            $actions
                                                        );

                                                        // Ligne expandable pour le texte OCR
                                                        if ($textContent) {
                                                            $html .= '<tr id="ocr-text-' . $pageNum . '" class="hidden">';
                                                            $html .= '<td colspan="5" class="p-0">';
                                                            $html .= '<div class="p-4 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700">';
                                                            $html .= '<div class="flex items-center justify-between mb-2">';
                                                            $html .= '<span class="text-xs font-medium text-gray-500">Texte OCR - Page ' . $pageNum . '</span>';
                                                            $html .= '<button onclick="navigator.clipboard.writeText(document.getElementById(\'ocr-content-' . $pageNum . '\').innerText)" class="text-xs text-primary-600 hover:underline">Copier</button>';
                                                            $html .= '</div>';
                                                            $html .= '<pre id="ocr-content-' . $pageNum . '" class="p-3 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded text-xs font-mono whitespace-pre-wrap overflow-x-auto max-h-64 overflow-y-auto">' . e($textContent) . '</pre>';
                                                            $html .= '</div></td></tr>';
                                                        }
                                                    }
                                                    $html .= '</tbody></table>';
                                                    $html .= '</div></div>';
                                                }

                                                // 4. Étape Chunking + Indexation
                                                $stepNumber = $isImage ? 2 : (empty($pages) ? 3 : 4);
                                                $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                                                $html .= '<div class="px-4 py-2 bg-amber-50 dark:bg-amber-900/20 border-b border-gray-200 dark:border-gray-700">';
                                                $html .= '<h4 class="font-medium text-amber-700 dark:text-amber-400">' . $stepNumber . '. Chunking + Indexation</h4>';
                                                $html .= '</div>';
                                                $html .= '<div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">';

                                                $chunkStrategy = $record->chunk_strategy ?? 'sentence';
                                                $strategyLabel = match ($chunkStrategy) {
                                                    'sentence' => 'Par phrase',
                                                    'paragraph' => 'Par paragraphe',
                                                    'fixed_size' => 'Taille fixe',
                                                    'markdown' => 'Markdown (headers)',
                                                    'llm_assisted' => 'Assisté par LLM',
                                                    default => $chunkStrategy,
                                                };

                                                $html .= '<div><span class="text-gray-500">Stratégie:</span><br><strong class="text-amber-600">' . e($strategyLabel) . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Chunks générés:</span><br><strong>' . ($record->chunk_count ?? 0) . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Vectorisation:</span><br><strong class="text-amber-600">Ollama (nomic-embed-text)</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Base vectorielle:</span><br><strong class="text-amber-600">Qdrant</strong></div>';
                                                $html .= '</div>';

                                                // Zone expandable avec les chunks
                                                $chunks = $record->chunks()->orderBy('chunk_index')->get();
                                                if ($chunks->isNotEmpty()) {
                                                    $html .= '<details class="border-t border-gray-200 dark:border-gray-700">';
                                                    $html .= '<summary class="px-4 py-2 text-sm font-medium text-amber-600 dark:text-amber-400 cursor-pointer hover:bg-amber-50 dark:hover:bg-amber-900/20">Voir les ' . $chunks->count() . ' chunks</summary>';
                                                    $html .= '<div class="p-4 space-y-3 max-h-96 overflow-y-auto">';
                                                    foreach ($chunks as $chunk) {
                                                        $statusIcon = $chunk->is_indexed ? '✓' : '✗';
                                                        $statusColor = $chunk->is_indexed ? 'text-success-600' : 'text-danger-600';
                                                        $html .= '<div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">';
                                                        $html .= '<div class="flex justify-between items-center mb-2">';
                                                        $html .= '<span class="font-medium text-sm">Chunk #' . $chunk->chunk_index . '</span>';
                                                        $html .= '<div class="flex items-center gap-2">';
                                                        $html .= '<span class="text-xs text-gray-500">' . ($chunk->token_count ?? 0) . ' tokens</span>';
                                                        $html .= '<span class="text-xs ' . $statusColor . '">' . $statusIcon . ' ' . ($chunk->is_indexed ? 'Indexé' : 'Non indexé') . '</span>';
                                                        $html .= '</div></div>';
                                                        $html .= '<div class="text-xs text-gray-600 dark:text-gray-400 whitespace-pre-wrap max-h-24 overflow-y-auto">' . e(\Illuminate\Support\Str::limit($chunk->content, 300)) . '</div>';
                                                        $html .= '</div>';
                                                    }
                                                    $html .= '</div></details>';
                                                }
                                                $html .= '</div>';

                                                // 5. Erreurs éventuelles
                                                $errors = $ocrData['errors'] ?? [];
                                                if (!empty($errors)) {
                                                    $html .= '<div class="border border-red-200 dark:border-red-700 rounded-lg overflow-hidden">';
                                                    $html .= '<div class="px-4 py-2 bg-red-50 dark:bg-red-900/20 border-b border-red-200 dark:border-red-700">';
                                                    $html .= '<h4 class="font-medium text-red-700 dark:text-red-400">Erreurs</h4>';
                                                    $html .= '</div>';
                                                    $html .= '<div class="p-4 space-y-2">';
                                                    foreach ($errors as $error) {
                                                        $html .= sprintf(
                                                            '<div class="text-sm text-red-600 dark:text-red-400">Page %d: %s</div>',
                                                            $error['page'] ?? 0,
                                                            e($error['error'] ?? 'Erreur inconnue')
                                                        );
                                                    }
                                                    $html .= '</div></div>';
                                                }

                                                // Timestamp
                                                if (!empty($ocrData['extracted_at'])) {
                                                    $html .= '<div class="text-xs text-gray-400 text-right">';
                                                    $html .= 'Extrait le ' . \Carbon\Carbon::parse($ocrData['extracted_at'])->format('d/m/Y H:i');
                                                    $html .= '</div>';
                                                }

                                                $html .= '</div>';

                                                return new \Illuminate\Support\HtmlString($html);
                                            })
                                            ->columnSpanFull(),
                                    ])
                                    ->collapsible()
                                    ->visible(fn ($record) => $record?->extraction_method === 'ocr' || !empty($record?->extraction_metadata['ocr_extraction'] ?? null)),

                                Forms\Components\Section::make('Texte extrait')
                                    ->description('Vous pouvez modifier le texte avant de le re-chunker')
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
                                                'markdown' => 'Markdown (par headers)',
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

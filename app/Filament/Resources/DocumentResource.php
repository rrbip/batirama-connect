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
                                Forms\Components\Section::make('Fichier')
                                    ->schema([
                                        Forms\Components\Select::make('agent_id')
                                            ->label('Agent')
                                            ->relationship('agent', 'name')
                                            ->required()
                                            ->searchable()
                                            ->preload()
                                            ->live()
                                            ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                                if ($state) {
                                                    $agent = \App\Models\Agent::find($state);
                                                    if ($agent) {
                                                        $set('chunk_strategy', $agent->getDefaultChunkStrategy());
                                                        $set('extraction_method', $agent->getDefaultExtractionMethod());
                                                    }
                                                }
                                            }),

                                        // Upload pour nouveau document
                                        Forms\Components\FileUpload::make('storage_path')
                                            ->label('Document')
                                            ->required()
                                            ->disk('local')
                                            ->directory('documents')
                                            ->acceptedFileTypes([
                                                'application/pdf',
                                                'text/plain',
                                                'text/markdown',
                                                'application/msword',
                                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                                // Images (OCR)
                                                'image/png',
                                                'image/jpeg',
                                                'image/gif',
                                                'image/bmp',
                                                'image/tiff',
                                                'image/webp',
                                            ])
                                            ->maxSize(50 * 1024) // 50MB
                                            ->columnSpanFull()
                                            ->visible(fn ($record) => !$record),

                                        Forms\Components\TextInput::make('title')
                                            ->label('Titre')
                                            ->maxLength(255)
                                            ->columnSpanFull(),

                                        Forms\Components\Textarea::make('description')
                                            ->label('Description')
                                            ->rows(3)
                                            ->columnSpanFull(),

                                        Forms\Components\TextInput::make('source_url')
                                            ->label('URL source')
                                            ->url()
                                            ->maxLength(500),

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
                                    ->columns(2),

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
                                                'application/msword',
                                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                                // Images (OCR)
                                                'image/png',
                                                'image/jpeg',
                                                'image/gif',
                                                'image/bmp',
                                                'image/tiff',
                                                'image/webp',
                                            ])
                                            ->maxSize(50 * 1024) // 50MB
                                            ->helperText('Uploadez un nouveau fichier pour remplacer l\'actuel. Le document sera automatiquement retraité.')
                                            ->columnSpanFull()
                                            ->live()
                                            ->afterStateUpdated(function ($state, $record, Forms\Set $set) {
                                                // Le fichier sera traité lors de la sauvegarde
                                            }),
                                    ])
                                    ->visible(fn ($record) => $record !== null)
                                    ->collapsed(),
                            ]),

                        Forms\Components\Tabs\Tab::make('Extraction')
                            ->icon('heroicon-o-document-magnifying-glass')
                            ->schema([
                                Forms\Components\Section::make('Statut extraction')
                                    ->schema([
                                        Forms\Components\Placeholder::make('extraction_status_display')
                                            ->label('Statut')
                                            ->content(fn ($record) => match ($record?->extraction_status) {
                                                'pending' => 'En attente',
                                                'processing' => 'En cours',
                                                'completed' => 'Terminé',
                                                'failed' => 'Échoué',
                                                default => '-',
                                            }),

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

                                        Forms\Components\Placeholder::make('extracted_at_display')
                                            ->label('Extrait le')
                                            ->content(fn ($record) => $record?->extracted_at?->format('d/m/Y H:i') ?? '-'),

                                        Forms\Components\Placeholder::make('chunk_count_display')
                                            ->label('Nombre de chunks')
                                            ->content(fn ($record) => $record?->chunk_count ?? 0),

                                        Forms\Components\Placeholder::make('file_size_display')
                                            ->label('Taille')
                                            ->content(fn ($record) => $record?->getFileSizeForHumans() ?? '-'),
                                    ])
                                    ->columns(5),

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
                                                    return 'Aucune donnée d\'extraction Vision disponible';
                                                }

                                                // Informations générales
                                                $html = '<div class="space-y-6">';

                                                // 1. Étape PDF → Images
                                                $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                                                $html .= '<div class="px-4 py-2 bg-blue-50 dark:bg-blue-900/20 border-b border-gray-200 dark:border-gray-700">';
                                                $html .= '<h4 class="font-medium text-blue-700 dark:text-blue-400">1. PDF → Images</h4>';
                                                $html .= '</div>';
                                                $html .= '<div class="p-4 grid grid-cols-3 gap-4 text-sm">';
                                                $html .= '<div><span class="text-gray-500">Pages totales:</span> <strong>' . ($visionData['total_pages'] ?? '-') . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">DPI:</span> <strong>' . ($visionData['dpi'] ?? '300') . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Stockage images:</span> <strong>' . ($visionData['storage_path'] ?? '-') . '</strong></div>';
                                                $html .= '</div></div>';

                                                // 2. Étape Images → Markdown (Vision LLM)
                                                $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                                                $html .= '<div class="px-4 py-2 bg-purple-50 dark:bg-purple-900/20 border-b border-gray-200 dark:border-gray-700">';
                                                $html .= '<h4 class="font-medium text-purple-700 dark:text-purple-400">2. Images → Markdown (Vision IA)</h4>';
                                                $html .= '</div>';
                                                $html .= '<div class="p-4 grid grid-cols-3 gap-4 text-sm">';
                                                $html .= '<div><span class="text-gray-500">Modèle:</span> <strong>' . e($visionData['model'] ?? '-') . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Pages traitées:</span> <strong>' . ($visionData['pages_processed'] ?? '-') . '</strong></div>';
                                                $html .= '<div><span class="text-gray-500">Durée totale:</span> <strong>' . ($visionData['duration_seconds'] ?? '-') . 's</strong></div>';
                                                $html .= '</div></div>';

                                                // 3. Détail par page
                                                $pages = $visionData['pages'] ?? [];
                                                if (!empty($pages)) {
                                                    $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                                                    $html .= '<div class="px-4 py-2 bg-green-50 dark:bg-green-900/20 border-b border-gray-200 dark:border-gray-700">';
                                                    $html .= '<h4 class="font-medium text-green-700 dark:text-green-400">3. Détail par page</h4>';
                                                    $html .= '</div>';
                                                    $html .= '<div class="p-4">';
                                                    $html .= '<table class="w-full text-sm">';
                                                    $html .= '<thead class="text-gray-500 border-b dark:border-gray-700">';
                                                    $html .= '<tr><th class="text-left py-2">Page</th><th class="text-left py-2">Image</th><th class="text-left py-2">Markdown</th><th class="text-right py-2">Taille MD</th><th class="text-right py-2">Temps</th></tr>';
                                                    $html .= '</thead><tbody>';

                                                    foreach ($pages as $page) {
                                                        $imagePath = $page['image_path'] ?? '-';
                                                        $mdPath = $page['markdown_path'] ?? '-';
                                                        $mdLength = $page['markdown_length'] ?? 0;
                                                        $time = $page['processing_time'] ?? 0;

                                                        // Raccourcir les chemins pour l'affichage
                                                        if ($imagePath !== '-') {
                                                            $imagePath = basename($imagePath);
                                                        }
                                                        if ($mdPath !== '-') {
                                                            $mdPath = basename($mdPath);
                                                        }

                                                        $html .= sprintf(
                                                            '<tr class="border-b dark:border-gray-700 last:border-0">
                                                                <td class="py-2 font-medium">%d</td>
                                                                <td class="py-2 text-gray-500 font-mono text-xs">%s</td>
                                                                <td class="py-2 text-gray-500 font-mono text-xs">%s</td>
                                                                <td class="py-2 text-right">%s</td>
                                                                <td class="py-2 text-right">%ss</td>
                                                            </tr>',
                                                            $page['page'] ?? 0,
                                                            e($imagePath),
                                                            e($mdPath),
                                                            number_format($mdLength) . ' chars',
                                                            $time
                                                        );
                                                    }
                                                    $html .= '</tbody></table>';
                                                    $html .= '</div></div>';
                                                }

                                                // 4. Erreurs éventuelles
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
                                    ->collapsed()
                                    ->visible(fn ($record) => $record?->extraction_method === 'vision' && !empty($record?->extraction_metadata['vision_extraction'] ?? null)),

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
                                                    return 'Aucune donnée d\'extraction HTML disponible';
                                                }

                                                $html = '<div class="space-y-4">';

                                                // Stats générales
                                                $html .= '<div class="grid grid-cols-4 gap-4 text-sm">';
                                                $html .= '<div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">';
                                                $html .= '<div class="text-gray-500 text-xs">HTML original</div>';
                                                $html .= '<div class="font-bold text-lg">' . number_format($htmlData['html_size'] ?? 0) . '</div>';
                                                $html .= '<div class="text-gray-400 text-xs">caractères</div>';
                                                $html .= '</div>';

                                                $html .= '<div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">';
                                                $html .= '<div class="text-gray-500 text-xs">HTML nettoyé</div>';
                                                $html .= '<div class="font-bold text-lg">' . number_format($htmlData['cleaned_html_size'] ?? 0) . '</div>';
                                                $html .= '<div class="text-gray-400 text-xs">caractères</div>';
                                                $html .= '</div>';

                                                $html .= '<div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">';
                                                $html .= '<div class="text-gray-500 text-xs">Markdown final</div>';
                                                $html .= '<div class="font-bold text-lg">' . number_format($htmlData['markdown_size'] ?? 0) . '</div>';
                                                $html .= '<div class="text-gray-400 text-xs">caractères</div>';
                                                $html .= '</div>';

                                                $html .= '<div class="p-3 bg-green-50 dark:bg-green-900/20 rounded-lg">';
                                                $html .= '<div class="text-gray-500 text-xs">Compression</div>';
                                                $html .= '<div class="font-bold text-lg text-green-600">' . ($htmlData['compression_ratio'] ?? 0) . '%</div>';
                                                $html .= '<div class="text-gray-400 text-xs">réduction</div>';
                                                $html .= '</div>';
                                                $html .= '</div>';

                                                // Éléments détectés
                                                $elements = $htmlData['elements_detected'] ?? [];
                                                if (!empty($elements)) {
                                                    $html .= '<div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">';
                                                    $html .= '<div class="text-sm font-medium mb-3">Éléments structurels détectés</div>';
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
                                                            '<span class="px-2 py-1 bg-gray-100 dark:bg-gray-700 rounded text-xs">%s: <strong>%d</strong></span>',
                                                            $label,
                                                            $count
                                                        );
                                                    }
                                                    $html .= '</div></div>';
                                                }

                                                // Métadonnées
                                                $html .= '<div class="text-xs text-gray-400">';
                                                $html .= 'Convertisseur: ' . e($htmlData['converter'] ?? '-');
                                                $html .= ' | Temps: ' . ($htmlData['processing_time_ms'] ?? 0) . 'ms';
                                                if (!empty($htmlData['extracted_at'])) {
                                                    $html .= ' | ' . \Carbon\Carbon::parse($htmlData['extracted_at'])->format('d/m/Y H:i');
                                                }
                                                $html .= '</div>';

                                                $html .= '</div>';

                                                return new \Illuminate\Support\HtmlString($html);
                                            })
                                            ->columnSpanFull(),
                                    ])
                                    ->collapsed()
                                    ->visible(fn ($record) => !empty($record?->extraction_metadata['html_extraction'] ?? null)),

                                Forms\Components\Section::make('Texte extrait')
                                    ->description('Vous pouvez modifier le texte avant de le re-chunker')
                                    ->schema([
                                        Forms\Components\Textarea::make('extracted_text')
                                            ->label('')
                                            ->rows(15)
                                            ->columnSpanFull()
                                            ->hint('Après modification, utilisez "Re-chunker" pour régénérer les chunks'),
                                    ])
                                    ->collapsed()
                                    ->visible(fn ($record) => $record?->extracted_text),

                                Forms\Components\Section::make('Erreur')
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

                Tables\Filters\SelectFilter::make('category')
                    ->label('Catégorie')
                    ->options([
                        'documentation' => 'Documentation',
                        'faq' => 'FAQ',
                        'product' => 'Produit',
                        'support' => 'Support',
                        'legal' => 'Légal',
                        'other' => 'Autre',
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

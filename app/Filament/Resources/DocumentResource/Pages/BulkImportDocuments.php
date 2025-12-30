<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Jobs\ProcessBulkImportJob;
use App\Models\Agent;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class BulkImportDocuments extends Page
{
    protected static string $resource = DocumentResource::class;

    protected static string $view = 'filament.resources.document-resource.pages.bulk-import-documents';

    protected static ?string $title = 'Import en masse';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Configuration')
                    ->schema([
                        Forms\Components\Select::make('agent_id')
                            ->label('Agent cible')
                            ->options(Agent::pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->helperText('Tous les documents importés seront associés à cet agent et indexés dans sa collection Qdrant.'),

                        Forms\Components\Select::make('max_depth')
                            ->label('Profondeur max des dossiers')
                            ->options([
                                0 => 'Ignorer les dossiers',
                                1 => '1 niveau',
                                2 => '2 niveaux',
                                3 => '3 niveaux',
                                99 => 'Illimité',
                            ])
                            ->default(2)
                            ->helperText('Pour les ZIP : nombre de niveaux de sous-dossiers à utiliser comme contexte parent.'),
                            ->helperText('Pour les ZIP : nombre de niveaux de sous-dossiers à utiliser comme catégorie.'),

                        Forms\Components\Select::make('extraction_method')
                            ->label('Méthode d\'extraction (PDF)')
                            ->options([
                                'auto' => 'Automatique (recommandé)',
                                'text' => 'Texte uniquement',
                                'ocr' => 'OCR (Tesseract)',
                                'vision' => 'Vision IA (tableaux)',
                            ])
                            ->default('auto')
                            ->helperText('Vision: préserve les tableaux. OCR: pour les PDF scannés.'),

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
                            ->default('sentence')
                            ->helperText('Markdown: optimal pour HTML/MD. Automatiquement défini selon l\'agent.'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Pipeline automatique')
                    ->schema([
                        Forms\Components\Placeholder::make('pipeline_info')
                            ->label('')
                            ->content(new \Illuminate\Support\HtmlString(
                                '<div class="text-sm text-gray-600 dark:text-gray-400">
                                    <p class="mb-2">Chaque document sera traité automatiquement selon son type :</p>
                                    <ul class="list-disc pl-4 space-y-1">
                                        <li><strong>PDF</strong> : PDF → Images → Markdown (Vision LLM) → Q/R Atomique → Qdrant</li>
                                        <li><strong>Images</strong> : Image → Markdown (Vision LLM) → Q/R Atomique → Qdrant</li>
                                        <li><strong>HTML</strong> : HTML → Markdown (Turndown) → Q/R Atomique → Qdrant</li>
                                        <li><strong>Markdown</strong> : Markdown → Q/R Atomique → Qdrant</li>
                                    </ul>
                                </div>'
                            ))
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Tabs::make('Import')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Fichiers multiples')
                            ->icon('heroicon-o-document-duplicate')
                            ->schema([
                                Forms\Components\FileUpload::make('files')
                                    ->label('Glissez-déposez vos fichiers ici')
                                    ->multiple()
                                    ->disk('local')
                                    ->directory('temp-imports')
                                    ->acceptedFileTypes([
                                        'application/pdf',
                                        'text/plain',
                                        'text/markdown',
                                        'application/msword',
                                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                        'image/png',
                                        'image/jpeg',
                                        'image/gif',
                                        'image/bmp',
                                        'image/tiff',
                                        'image/webp',
                                    ])
                                    ->maxSize(50 * 1024) // 50MB par fichier
                                    ->maxFiles(100)
                                    ->helperText('Formats acceptés : PDF, DOCX, TXT, MD, images (JPG, PNG, etc.). Max 100 fichiers, 50MB chacun.')
                                    ->columnSpanFull()
                                    ->reorderable()
                                    ->appendFiles(),
                            ]),

                        Forms\Components\Tabs\Tab::make('Archive ZIP')
                            ->icon('heroicon-o-archive-box')
                            ->schema([
                                Forms\Components\FileUpload::make('zip_file')
                                    ->label('Archive ZIP')
                                    ->disk('local')
                                    ->directory('temp-imports')
                                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                                    ->maxSize(500 * 1024) // 500MB
                                    ->helperText('La structure des dossiers sera utilisée comme catégorie. Max 500MB.')
                                    ->columnSpanFull(),

                                Forms\Components\Toggle::make('skip_root_folder')
                                    ->label('Ignorer le dossier racine')
                                    ->default(true)
                                    ->helperText('Si le ZIP contient un seul dossier racine, l\'ignorer pour les catégories.'),
                            ]),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function import(): void
    {
        $data = $this->form->getState();

        if (empty($data['files']) && empty($data['zip_file'])) {
            Notification::make()
                ->title('Aucun fichier')
                ->body('Veuillez sélectionner des fichiers ou une archive ZIP.')
                ->danger()
                ->send();
            return;
        }

        $agentId = $data['agent_id'];
        $maxDepth = (int) ($data['max_depth'] ?? 2);
        $skipRootFolder = $data['skip_root_folder'] ?? true;

        $filesToProcess = [];

        // Traiter les fichiers multiples
        if (!empty($data['files'])) {
            foreach ($data['files'] as $storagePath) {
                // Filament FileUpload retourne le chemin relatif dans le storage
                $fullPath = Storage::disk('local')->path($storagePath);
                $originalName = basename($storagePath);

                $filesToProcess[] = [
                    'path' => $fullPath,
                    'original_name' => $originalName,
                    'parent_context' => null,
                ];
            }
        }

        // Traiter le ZIP
        if (!empty($data['zip_file'])) {
            $zipPath = Storage::disk('local')->path($data['zip_file']);

            $extractedFiles = $this->extractZipFile($zipPath, $maxDepth, $skipRootFolder);
            $filesToProcess = array_merge($filesToProcess, $extractedFiles);

            // Supprimer le fichier ZIP temporaire après extraction
            @unlink($zipPath);
        }

        if (empty($filesToProcess)) {
            Notification::make()
                ->title('Aucun fichier valide')
                ->body('Aucun fichier valide n\'a été trouvé dans votre sélection.')
                ->danger()
                ->send();
            return;
        }

        // Dispatcher le job de traitement
        \Illuminate\Support\Facades\Log::info('Dispatching ProcessBulkImportJob', [
            'agent_id' => $agentId,
            'file_count' => count($filesToProcess),
        ]);

        ProcessBulkImportJob::dispatch($agentId, $filesToProcess);

        \Illuminate\Support\Facades\Log::info('ProcessBulkImportJob dispatched successfully');

        Notification::make()
            ->title('Import lancé')
            ->body(sprintf('%d fichier(s) en cours de traitement. Vous serez notifié une fois terminé.', count($filesToProcess)))
            ->success()
            ->send();

        // Reset le formulaire
        $this->form->fill();
    }

    /**
     * Extrait les fichiers d'une archive ZIP
     */
    private function extractZipFile(string $zipPath, int $maxDepth, bool $skipRootFolder): array
    {
        $files = [];
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            Notification::make()
                ->title('Erreur ZIP')
                ->body('Impossible d\'ouvrir l\'archive ZIP.')
                ->danger()
                ->send();
            return [];
        }

        // Créer un dossier temporaire pour l'extraction
        $extractDir = storage_path('app/temp-imports/zip-' . Str::uuid());
        mkdir($extractDir, 0755, true);

        $zip->extractTo($extractDir);
        $zip->close();

        // Détecter si on doit ignorer un dossier racine unique
        $rootFolder = '';
        if ($skipRootFolder) {
            $items = array_diff(scandir($extractDir), ['.', '..']);
            if (count($items) === 1) {
                $singleItem = reset($items);
                if (is_dir($extractDir . '/' . $singleItem)) {
                    $rootFolder = $singleItem . '/';
                }
            }
        }

        // Parcourir récursivement les fichiers
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $allowedExtensions = ['pdf', 'txt', 'md', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'webp'];

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $allowedExtensions)) {
                continue;
            }

            // Calculer le chemin relatif
            $relativePath = str_replace($extractDir . '/', '', $file->getPathname());

            // Ignorer le dossier racine si demandé
            if ($rootFolder && str_starts_with($relativePath, $rootFolder)) {
                $relativePath = substr($relativePath, strlen($rootFolder));
            }

            // Extraire le contexte parent du chemin (utilisé pour le chunking)
            $parentContext = $this->pathToParentContext($relativePath, $maxDepth);

            // Copier le fichier vers un emplacement permanent temporaire
            $newPath = storage_path('app/temp-imports/' . Str::uuid() . '.' . $extension);
            copy($file->getPathname(), $newPath);

            $files[] = [
                'path' => $newPath,
                'original_name' => $file->getFilename(),
                'parent_context' => $parentContext,
            ];
        }

        // Nettoyer le dossier d'extraction
        $this->deleteDirectory($extractDir);

        return $files;
    }

    /**
     * Convertit un chemin de fichier en contexte parent (breadcrumb)
     */
    private function pathToParentContext(string $relativePath, int $maxDepth): ?string
    {
        $parts = explode('/', dirname($relativePath));
        $parts = array_filter($parts, fn($p) => $p !== '.' && $p !== '');

        if (empty($parts)) {
            return null;
        }

        // Limiter la profondeur
        if ($maxDepth > 0 && $maxDepth < 99) {
            $parts = array_slice($parts, 0, $maxDepth);
        }

        return implode(' > ', $parts);
    }

    /**
     * Supprime un dossier récursivement
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function getBreadcrumb(): string
    {
        return 'Import en masse';
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('back')
                ->label('Retour à la liste')
                ->icon('heroicon-o-arrow-left')
                ->url(DocumentResource::getUrl('index')),
        ];
    }
}

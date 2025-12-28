<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Jobs\RunVisionCalibrationJob;
use App\Models\VisionSetting;
use App\Services\VisionExtractorService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;

class VisionSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;
    use WithFileUploads;

    protected static ?string $navigationIcon = 'heroicon-o-eye';

    protected static string $view = 'filament.pages.vision-settings-page';

    protected static ?string $navigationGroup = 'Intelligence Artificielle';

    protected static ?string $navigationLabel = 'Extraction Vision';

    protected static ?string $title = 'Configuration de l\'extraction Vision';

    protected static ?int $navigationSort = 6;

    public ?array $data = [];

    public array $diagnostics = [];

    // Calibration properties
    public ?string $calibrationImageUrl = null;
    public $calibrationImageUpload = null;
    public string $calibrationJson = '';
    public array $calibrationResults = [];
    public bool $isCalibrating = false;
    public string $calibrationReport = '';
    public ?string $calibrationId = null;
    public int $calibrationProgress = 0;
    public int $calibrationTotal = 0;

    public function mount(): void
    {
        $settings = VisionSetting::getInstance();

        $this->form->fill([
            'model' => $settings->model,
            'ollama_host' => $settings->ollama_host,
            'ollama_port' => $settings->ollama_port,
            'image_dpi' => $settings->image_dpi,
            'output_format' => $settings->output_format,
            'max_pages' => $settings->max_pages,
            'timeout_seconds' => $settings->timeout_seconds,
            'system_prompt' => $settings->system_prompt,
            'store_images' => $settings->store_images,
            'store_markdown' => $settings->store_markdown,
            'storage_disk' => $settings->storage_disk,
            'storage_path' => $settings->storage_path,
        ]);

        $this->refreshDiagnostics();

        // Initialiser le JSON de calibration avec les tests par défaut
        $this->calibrationJson = json_encode($this->getDefaultCalibrationTests(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Tests de calibration par défaut
     */
    private function getDefaultCalibrationTests(): array
    {
        return [
            [
                'id' => 'basic_transcription_headers',
                'category' => 'OCR & Structure Simple',
                'description' => 'Teste la capacité à identifier des titres et des paragraphes simples dans un document texte.',
                'prompt' => 'Convertis le contenu textuel de cette image en format Markdown. Utilise des dièses (#, ##) pour les titres visiblement plus grands et du texte normal pour les paragraphes. Ne rajoute aucun commentaire avant ou après le contenu markdown.',
            ],
            [
                'id' => 'unordered_lists',
                'category' => 'Listes',
                'description' => 'Teste la détection et la conversion de listes à puces.',
                'prompt' => 'Identifie la liste à puces présente dans l\'image et transcris-la en utilisant la syntaxe de liste Markdown (des astérisques * ou des tirets - pour chaque élément). Respecte l\'ordre des éléments.',
            ],
            [
                'id' => 'ordered_lists_instructions',
                'category' => 'Listes',
                'description' => 'Teste la détection de listes numérotées (ex: une recette ou des instructions).',
                'prompt' => 'Cette image contient des étapes numérotées. Transcris-les en une liste ordonnée Markdown (1., 2., 3., etc.). Assure-toi de capturer tout le texte de chaque étape.',
            ],
            [
                'id' => 'simple_table_structure',
                'category' => 'Tableaux (Stress Test)',
                'description' => 'Teste crucial pour Moondream : peut-il générer la syntaxe de tableau Markdown sans casser la mise en page ?',
                'prompt' => 'Analyse l\'image pour y trouver un tableau. Représente les données de ce tableau en utilisant strictement la syntaxe de tableau Markdown (avec les barres verticales | et la ligne de séparation header/contenu |---|). Si la structure est trop complexe, fais de ton mieux pour aligner les colonnes.',
            ],
            [
                'id' => 'key_value_extraction',
                'category' => 'Extraction Structurée (Formulaires)',
                'description' => 'Teste l\'extraction de champs de type formulaire ou facture (ex: \'Total: 50€\') vers une liste structurée.',
                'prompt' => 'L\'image contient des paires clé-valeur (comme des champs de formulaire ou des étiquettes et leurs données). Extrais-les dans une liste Markdown où la clé est en gras, suivie de la valeur. Exemple: \'- **Nom:** Jean Dupont\'.',
            ],
            [
                'id' => 'code_block_ocr',
                'category' => 'Code & Technique',
                'description' => 'Vérifie si le modèle reconnaît du code informatique et le place dans des balises de code appropriées.',
                'prompt' => 'Cette image contient un extrait de code informatique. Transcris ce code exactement comme il est écrit et place-le à l\'intérieur d\'un bloc de code Markdown (en utilisant les triples backticks ``` au début et à la fin).',
            ],
            [
                'id' => 'visual_description_structured',
                'category' => 'Description Visuelle Structurée',
                'description' => 'Teste la capacité à décrire une scène visuelle (sans texte) en utilisant la structure Markdown pour organiser la réponse.',
                'prompt' => 'Décris cette scène visuelle en utilisant une structure Markdown. Utilise un titre principal (#) pour le sujet global de l\'image, puis une liste à puces (*) pour détailler les principaux objets, couleurs ou actions que tu observes.',
            ],
            [
                'id' => 'mixed_layout_article',
                'category' => 'Mise en page complexe',
                'description' => 'Teste la lecture d\'une page de magazine ou d\'un article web avec une image, un titre principal et un corps de texte.',
                'prompt' => 'Analyse cette page d\'article. Extrais le titre principal en H1 (#), le sous-titre éventuel en H2 (##), et le corps du texte en paragraphes normaux. Ignore les publicités ou les éléments de navigation s\'il y en a.',
            ],
            [
                'id' => 'emphasis_and_bold',
                'category' => 'OCR & Formatage Riche',
                'description' => 'Vérifie si le modèle détecte le texte en gras ou en italique dans l\'image source.',
                'prompt' => 'Transcris le texte de l\'image. Si tu détectes des mots qui sont visuellement mis en évidence (en gras dans l\'image), entoure-les de doubles astérisques (**) dans la sortie Markdown pour conserver l\'emphase.',
            ],
            [
                'id' => 'raw_text_fallback',
                'category' => 'Failsafe',
                'description' => 'Un test pour voir comment le modèle se comporte quand la structure est trop floue, doit-il juste cracher le texte ?',
                'prompt' => 'Extrais tout le texte lisible de cette image et présente-le sous forme de paragraphes Markdown simples. Ne t\'inquiète pas pour la mise en forme complexe, concentre-toi sur la récupération maximale du texte.',
            ],
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Modèle Vision')
                    ->description('Sélectionnez le modèle de vision pour l\'extraction de texte')
                    ->schema([
                        Select::make('model')
                            ->label('Modèle')
                            ->options(VisionSetting::getModelOptions())
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(fn ($state) => $this->updateModelInfo($state)),

                        Placeholder::make('model_info')
                            ->label('Informations')
                            ->content(function ($get) {
                                $model = $get('model');
                                $info = VisionSetting::AVAILABLE_MODELS[$model] ?? null;
                                if (!$info) {
                                    return 'Modèle inconnu';
                                }

                                $cpuStatus = $info['cpu_compatible']
                                    ? '✅ Compatible CPU'
                                    : '⚠️ GPU requis (' . $info['vram'] . ' VRAM)';

                                return "{$info['description']}\n\n{$cpuStatus}\n\nVitesse CPU: {$info['speed_cpu']} | GPU: {$info['speed_gpu']}";
                            }),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('ollama_host')
                                    ->label('Host Ollama')
                                    ->required()
                                    ->default('ollama'),

                                TextInput::make('ollama_port')
                                    ->label('Port')
                                    ->numeric()
                                    ->required()
                                    ->default(11434),
                            ]),
                    ]),

                Section::make('Paramètres d\'extraction')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextInput::make('image_dpi')
                                    ->label('Résolution (DPI)')
                                    ->numeric()
                                    ->required()
                                    ->default(300)
                                    ->helperText('300 DPI recommandé. Plus = meilleure qualité mais plus lent.'),

                                TextInput::make('max_pages')
                                    ->label('Max pages')
                                    ->numeric()
                                    ->required()
                                    ->default(50)
                                    ->helperText('Limite de pages par document.'),

                                TextInput::make('timeout_seconds')
                                    ->label('Timeout (secondes)')
                                    ->numeric()
                                    ->required()
                                    ->default(120)
                                    ->suffix('s')
                                    ->helperText('Timeout par page.'),
                            ]),

                        Select::make('output_format')
                            ->label('Format de sortie')
                            ->options([
                                'markdown' => 'Markdown (recommandé)',
                                'text' => 'Texte brut',
                                'json' => 'JSON structuré',
                            ])
                            ->default('markdown'),
                    ]),

                Section::make('Stockage des fichiers intermédiaires')
                    ->description('Conservez les images et le markdown pour debug et réutilisation')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Checkbox::make('store_images')
                                    ->label('Conserver les images des pages')
                                    ->helperText('Permet de visualiser ce qui a été envoyé au modèle'),

                                Checkbox::make('store_markdown')
                                    ->label('Conserver le markdown extrait')
                                    ->helperText('Permet de réutiliser le markdown pour d\'autres usages'),
                            ]),

                        Grid::make(2)
                            ->schema([
                                Select::make('storage_disk')
                                    ->label('Disque de stockage')
                                    ->options([
                                        'local' => 'Local (storage/app)',
                                        'public' => 'Public (accessible web)',
                                        's3' => 'S3 (cloud)',
                                    ])
                                    ->default('local'),

                                TextInput::make('storage_path')
                                    ->label('Chemin de stockage')
                                    ->default('vision-extraction')
                                    ->helperText('Sous-dossier pour les fichiers extraits'),
                            ]),
                    ]),

                Section::make('Prompt d\'extraction')
                    ->description('Instructions envoyées au modèle vision pour extraire le contenu')
                    ->schema([
                        Textarea::make('system_prompt')
                            ->label('')
                            ->required()
                            ->rows(15)
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function updateModelInfo(?string $model): void
    {
        // Refresh form to update placeholder
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $settings = VisionSetting::getInstance();
        $settings->update($data);

        Notification::make()
            ->title('Configuration sauvegardée')
            ->success()
            ->send();
    }

    public function resetPrompt(): void
    {
        $defaultPrompt = VisionSetting::getDefaultPrompt();

        $this->form->fill([
            ...$this->form->getState(),
            'system_prompt' => $defaultPrompt,
        ]);

        Notification::make()
            ->title('Prompt réinitialisé')
            ->body('Le prompt par défaut a été restauré. N\'oubliez pas de sauvegarder.')
            ->info()
            ->send();
    }

    public function testConnection(): void
    {
        try {
            $service = app(VisionExtractorService::class);
            $diagnostics = $service->getDiagnostics();

            if ($diagnostics['ollama']['connected']) {
                if ($diagnostics['ollama']['configured_model_installed'] ?? false) {
                    Notification::make()
                        ->title('Connexion réussie')
                        ->body("Ollama accessible et modèle {$this->data['model']} installé.")
                        ->success()
                        ->send();
                } else {
                    $models = implode(', ', $diagnostics['ollama']['models_available'] ?? []);
                    Notification::make()
                        ->title('Modèle non installé')
                        ->body("Ollama accessible mais le modèle {$this->data['model']} n'est pas installé.\n\nModèles disponibles: {$models}")
                        ->warning()
                        ->send();
                }
            } else {
                Notification::make()
                    ->title('Connexion échouée')
                    ->body($diagnostics['ollama']['error'] ?? 'Impossible de contacter Ollama')
                    ->danger()
                    ->send();
            }

            $this->refreshDiagnostics();

        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function refreshDiagnostics(): void
    {
        try {
            $service = app(VisionExtractorService::class);
            $this->diagnostics = $service->getDiagnostics();
        } catch (\Exception $e) {
            $this->diagnostics = [
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test_connection')
                ->label('Tester la connexion')
                ->icon('heroicon-o-signal')
                ->color('info')
                ->action(fn () => $this->testConnection()),

            Action::make('reset_prompt')
                ->label('Réinitialiser le prompt')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Réinitialiser le prompt ?')
                ->modalDescription('Le prompt sera remplacé par la version par défaut.')
                ->action(fn () => $this->resetPrompt()),

            Action::make('save')
                ->label('Sauvegarder')
                ->icon('heroicon-o-check')
                ->color('success')
                ->action(fn () => $this->save()),
        ];
    }

    /**
     * Réinitialiser le JSON de calibration
     */
    public function resetCalibrationJson(): void
    {
        $this->calibrationJson = json_encode($this->getDefaultCalibrationTests(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->calibrationResults = [];
        $this->calibrationReport = '';

        Notification::make()
            ->title('JSON réinitialisé')
            ->body('Les tests de calibration par défaut ont été restaurés.')
            ->info()
            ->send();
    }

    /**
     * Lancer la calibration pour tous les tests du JSON (via job en arrière-plan)
     */
    public function runCalibration(): void
    {
        // Valider le JSON
        $tests = json_decode($this->calibrationJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Notification::make()
                ->title('Erreur JSON')
                ->body('Le JSON de calibration est invalide : ' . json_last_error_msg())
                ->danger()
                ->send();
            return;
        }

        if (empty($tests)) {
            Notification::make()
                ->title('Erreur')
                ->body('Aucun test défini dans le JSON')
                ->danger()
                ->send();
            return;
        }

        // Récupérer l'image
        $imageContent = $this->getCalibrationImageContent();

        if (!$imageContent) {
            Notification::make()
                ->title('Erreur')
                ->body('Veuillez fournir une image (upload ou URL)')
                ->danger()
                ->send();
            return;
        }

        // Générer un ID unique pour cette calibration
        $this->calibrationId = Str::uuid()->toString();
        $this->isCalibrating = true;
        $this->calibrationResults = [];
        $this->calibrationReport = '';
        $this->calibrationProgress = 0;
        $this->calibrationTotal = count($tests);

        // Dispatcher le job
        RunVisionCalibrationJob::dispatch(
            $this->calibrationId,
            $tests,
            $imageContent,
            $this->calibrationImageUrl
        );

        Notification::make()
            ->title('Calibration lancée')
            ->body("Traitement de {$this->calibrationTotal} tests en arrière-plan...")
            ->info()
            ->send();
    }

    /**
     * Vérifier le statut de la calibration (appelé par polling)
     */
    public function checkCalibrationStatus(): void
    {
        if (!$this->calibrationId || !$this->isCalibrating) {
            return;
        }

        $status = Cache::get("calibration:{$this->calibrationId}");

        if (!$status) {
            return;
        }

        $this->calibrationProgress = $status['progress'] ?? 0;
        $this->calibrationTotal = $status['total'] ?? 0;
        $this->calibrationResults = $status['results'] ?? [];

        if ($status['status'] === 'completed') {
            $this->isCalibrating = false;
            $this->calibrationReport = $status['report'] ?? '';

            Notification::make()
                ->title('Calibration terminée')
                ->body(count($this->calibrationResults) . ' test(s) exécutés. Rapport généré.')
                ->success()
                ->send();

            // Nettoyer le cache
            Cache::forget("calibration:{$this->calibrationId}");
        } elseif ($status['status'] === 'failed') {
            $this->isCalibrating = false;

            Notification::make()
                ->title('Erreur de calibration')
                ->body($status['error'] ?? 'Erreur inconnue')
                ->danger()
                ->send();

            Cache::forget("calibration:{$this->calibrationId}");
        }
    }

    /**
     * Annuler la calibration en cours
     */
    public function cancelCalibration(): void
    {
        if ($this->calibrationId) {
            Cache::forget("calibration:{$this->calibrationId}");
        }
        $this->isCalibrating = false;
        $this->calibrationId = null;
        $this->calibrationProgress = 0;
        $this->calibrationTotal = 0;

        Notification::make()
            ->title('Calibration annulée')
            ->warning()
            ->send();
    }

    /**
     * Récupère le contenu de l'image de calibration
     */
    private function getCalibrationImageContent(): ?string
    {
        // Priorité à l'upload Livewire
        if (!empty($this->calibrationImageUpload)) {
            $file = $this->calibrationImageUpload;

            // Livewire WithFileUploads retourne un TemporaryUploadedFile
            if ($file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
                return file_get_contents($file->getRealPath());
            }

            // Fallback pour les anciens chemins
            if (is_string($file) && Storage::disk('public')->exists($file)) {
                return Storage::disk('public')->get($file);
            }
        }

        // Sinon, utiliser l'URL
        if (!empty($this->calibrationImageUrl)) {
            try {
                $response = Http::timeout(30)->get($this->calibrationImageUrl);
                if ($response->successful()) {
                    return $response->body();
                }
            } catch (\Exception $e) {
                // Log error but don't throw
            }
        }

        return null;
    }

    /**
     * Utiliser un prompt de test comme prompt par défaut
     */
    public function usePromptAsDefault(string $testId): void
    {
        foreach ($this->calibrationResults as $result) {
            if (($result['id'] ?? '') === $testId) {
                $prompt = $result['prompt'] ?? '';
                if (!empty($prompt)) {
                    $this->form->fill([
                        ...$this->form->getState(),
                        'system_prompt' => $prompt,
                    ]);

                    Notification::make()
                        ->title('Prompt appliqué')
                        ->body("Le prompt du test '{$testId}' a été copié. N'oubliez pas de sauvegarder.")
                        ->success()
                        ->send();
                }
                return;
            }
        }
    }
}

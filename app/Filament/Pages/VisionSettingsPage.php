<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\VisionSetting;
use App\Services\VisionExtractorService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class VisionSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-eye';

    protected static string $view = 'filament.pages.vision-settings-page';

    protected static ?string $navigationGroup = 'Intelligence Artificielle';

    protected static ?string $navigationLabel = 'Extraction Vision';

    protected static ?string $title = 'Configuration de l\'extraction Vision';

    protected static ?int $navigationSort = 6;

    public ?array $data = [];

    public array $diagnostics = [];

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
}

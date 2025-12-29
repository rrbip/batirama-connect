<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\LlmChunkingSetting;
use App\Services\AI\OllamaService;
use App\Services\LlmChunkingService;
use Filament\Actions\Action;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class LlmChunkingSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $view = 'filament.pages.llm-chunking-settings-page';

    protected static ?string $navigationGroup = 'Intelligence Artificielle';

    protected static ?string $navigationLabel = 'Chunking LLM';

    protected static ?string $title = 'Configuration du Chunking LLM';

    protected static ?int $navigationSort = 5;

    // Masqué - accessible via Gestion RAG
    protected static bool $shouldRegisterNavigation = false;

    public ?array $data = [];

    public array $queueStats = [];

    public function mount(): void
    {
        $settings = LlmChunkingSetting::getInstance();

        $this->form->fill([
            'model' => $settings->model,
            'ollama_host' => $settings->ollama_host,
            'ollama_port' => $settings->ollama_port,
            'temperature' => $settings->temperature,
            'window_size' => $settings->window_size,
            'overlap_percent' => $settings->overlap_percent,
            'max_retries' => $settings->max_retries,
            'timeout_seconds' => $settings->timeout_seconds,
            'system_prompt' => $settings->system_prompt,
            'enrichment_prompt' => $settings->enrichment_prompt,
        ]);

        $this->refreshQueueStats();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Modèle Ollama')
                    ->description('Configuration du modèle LLM utilisé pour le chunking')
                    ->schema([
                        Select::make('model')
                            ->label('Modèle')
                            ->options(fn () => $this->getAvailableModels())
                            ->placeholder('Utiliser le modèle de l\'agent')
                            ->helperText('Laissez vide pour utiliser le modèle configuré sur chaque agent'),

                        TextInput::make('ollama_host')
                            ->label('Host Ollama')
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
                            ->default(0.3)
                            ->helperText('0 = déterministe, 1 = créatif. Recommandé: 0.3'),
                    ])
                    ->columns(4),

                Section::make('Pré-découpage')
                    ->description('Configuration des fenêtres de texte envoyées au LLM')
                    ->schema([
                        TextInput::make('window_size')
                            ->label('Taille fenêtre (tokens)')
                            ->numeric()
                            ->required()
                            ->default(2000)
                            ->helperText('Nombre de tokens par fenêtre. 2000 est sécuritaire pour la plupart des modèles.'),

                        TextInput::make('overlap_percent')
                            ->label('Chevauchement (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(50)
                            ->required()
                            ->default(10)
                            ->suffix('%')
                            ->helperText('Pourcentage de chevauchement entre fenêtres consécutives.'),
                    ])
                    ->columns(2),

                Section::make('Traitement')
                    ->schema([
                        TextInput::make('max_retries')
                            ->label('Tentatives')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(5)
                            ->required()
                            ->default(1)
                            ->helperText('Nombre de tentatives avant de marquer le document en erreur.'),

                        TextInput::make('timeout_seconds')
                            ->label('Timeout (secondes)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(7200)
                            ->required()
                            ->default(0)
                            ->suffix('s')
                            ->helperText('0 = pas de timeout (illimité). Recommandé pour les modèles locaux.'),
                    ])
                    ->columns(2),

                Section::make('Prompt système (LLM Chunking)')
                    ->description('Instructions envoyées au LLM pour le découpage sémantique complet')
                    ->schema([
                        MarkdownEditor::make('system_prompt')
                            ->label('')
                            ->required()
                            ->columnSpanFull()
                            ->helperText('Utilisez {CATEGORIES} et {INPUT_TEXT} comme placeholders.'),
                    ])
                    ->collapsed(),

                Section::make('Prompt d\'enrichissement (Markdown)')
                    ->description('Instructions pour enrichir les chunks markdown avec catégories, keywords et résumés')
                    ->schema([
                        MarkdownEditor::make('enrichment_prompt')
                            ->label('')
                            ->columnSpanFull()
                            ->helperText('Utilisez {CATEGORIES} et {CHUNKS_JSON} comme placeholders. Laissez vide pour le prompt par défaut.'),
                    ])
                    ->collapsed(),
            ])
            ->statePath('data');
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

    public function save(): void
    {
        $data = $this->form->getState();

        $settings = LlmChunkingSetting::getInstance();
        $settings->update($data);

        Notification::make()
            ->title('Configuration sauvegardée')
            ->success()
            ->send();
    }

    public function resetPrompt(): void
    {
        $defaultPrompt = LlmChunkingSetting::getDefaultPrompt();

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

    public function resetEnrichmentPrompt(): void
    {
        $defaultPrompt = LlmChunkingSetting::getDefaultEnrichmentPrompt();

        $this->form->fill([
            ...$this->form->getState(),
            'enrichment_prompt' => $defaultPrompt,
        ]);

        Notification::make()
            ->title('Prompt d\'enrichissement réinitialisé')
            ->body('Le prompt par défaut a été restauré. N\'oubliez pas de sauvegarder.')
            ->info()
            ->send();
    }

    public function testConnection(): void
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

    public function refreshQueueStats(): void
    {
        try {
            $this->queueStats = [
                'pending' => DB::table('jobs')
                    ->where('queue', 'llm-chunking')
                    ->count(),
                'failed' => DB::table('failed_jobs')
                    ->where('queue', 'llm-chunking')
                    ->count(),
            ];
        } catch (\Exception $e) {
            $this->queueStats = [
                'pending' => 0,
                'failed' => 0,
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
                ->label('Reset prompt chunking')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Réinitialiser le prompt ?')
                ->modalDescription('Le prompt sera remplacé par la version par défaut.')
                ->action(fn () => $this->resetPrompt()),

            Action::make('reset_enrichment_prompt')
                ->label('Reset prompt enrichissement')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Réinitialiser le prompt d\'enrichissement ?')
                ->modalDescription('Le prompt d\'enrichissement sera remplacé par la version par défaut.')
                ->action(fn () => $this->resetEnrichmentPrompt()),

            Action::make('save')
                ->label('Sauvegarder')
                ->icon('heroicon-o-check')
                ->color('success')
                ->action(fn () => $this->save()),
        ];
    }
}

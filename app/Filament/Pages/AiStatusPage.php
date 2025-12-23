<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Services\AI\EmbeddingService;
use App\Services\AI\OllamaService;
use App\Services\AI\QdrantService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class AiStatusPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static string $view = 'filament.pages.ai-status-page';

    protected static ?string $navigationGroup = 'Intelligence Artificielle';

    protected static ?string $navigationLabel = 'État des Services';

    protected static ?string $title = 'État des Services IA';

    protected static ?int $navigationSort = 10;

    public array $services = [];
    public array $queueStats = [];
    public array $documentStats = [];

    public function mount(): void
    {
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        $this->services = $this->checkServices();
        $this->queueStats = $this->getQueueStats();
        $this->documentStats = $this->getDocumentStats();
    }

    protected function checkServices(): array
    {
        $services = [];

        // Ollama
        try {
            $ollama = app(OllamaService::class);
            $models = $ollama->listModels();
            $services['ollama'] = [
                'name' => 'Ollama (LLM)',
                'status' => 'online',
                'details' => count($models) . ' modèle(s) disponible(s)',
                'models' => $models,
            ];
        } catch (\Exception $e) {
            $services['ollama'] = [
                'name' => 'Ollama (LLM)',
                'status' => 'offline',
                'details' => $e->getMessage(),
            ];
        }

        // Qdrant
        try {
            $qdrant = app(QdrantService::class);
            $isHealthy = $qdrant->isHealthy();
            $collections = $isHealthy ? $qdrant->listCollections() : [];
            $services['qdrant'] = [
                'name' => 'Qdrant (Vector DB)',
                'status' => $isHealthy ? 'online' : 'offline',
                'details' => $isHealthy
                    ? count($collections) . ' collection(s): ' . implode(', ', $collections)
                    : 'Service non disponible',
                'collections' => $collections,
            ];
        } catch (\Exception $e) {
            $services['qdrant'] = [
                'name' => 'Qdrant (Vector DB)',
                'status' => 'offline',
                'details' => $e->getMessage(),
            ];
        }

        // Embedding Service
        try {
            $embedding = app(EmbeddingService::class);
            // Test rapide d'embedding
            $testVector = $embedding->embed('test');
            $services['embedding'] = [
                'name' => 'Embedding Service',
                'status' => !empty($testVector) ? 'online' : 'offline',
                'details' => !empty($testVector)
                    ? 'Dimension: ' . count($testVector)
                    : 'Erreur de génération',
            ];
        } catch (\Exception $e) {
            $services['embedding'] = [
                'name' => 'Embedding Service',
                'status' => 'offline',
                'details' => $e->getMessage(),
            ];
        }

        // Queue Worker
        $queueConnection = config('queue.default');
        if ($queueConnection === 'sync') {
            $services['queue'] = [
                'name' => 'Queue Worker',
                'status' => 'online',
                'details' => 'Mode synchrone (traitement immédiat)',
            ];
        } else {
            // Vérifier si des jobs sont en attente depuis longtemps
            $oldestJob = null;
            try {
                $oldestJob = DB::table('jobs')->orderBy('created_at')->first();
            } catch (\Exception $e) {
                // Table n'existe pas
            }

            if ($oldestJob) {
                $age = now()->timestamp - $oldestJob->created_at;
                $isStuck = $age > 300; // Plus de 5 minutes
                $services['queue'] = [
                    'name' => 'Queue Worker',
                    'status' => $isStuck ? 'warning' : 'online',
                    'details' => $isStuck
                        ? "Jobs en attente depuis " . gmdate('H:i:s', $age) . " - Worker probablement arrêté"
                        : "Worker actif (driver: {$queueConnection})",
                ];
            } else {
                $services['queue'] = [
                    'name' => 'Queue Worker',
                    'status' => 'unknown',
                    'details' => "Aucun job en file (driver: {$queueConnection})",
                ];
            }
        }

        return $services;
    }

    protected function getQueueStats(): array
    {
        try {
            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();

            return [
                'pending' => $pending,
                'failed' => $failed,
                'connection' => config('queue.default'),
            ];
        } catch (\Exception $e) {
            return [
                'pending' => 0,
                'failed' => 0,
                'connection' => config('queue.default'),
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function getDocumentStats(): array
    {
        try {
            return [
                'total' => Document::count(),
                'pending' => Document::where('extraction_status', 'pending')->count(),
                'processing' => Document::where('extraction_status', 'processing')->count(),
                'completed' => Document::where('extraction_status', 'completed')->count(),
                'failed' => Document::where('extraction_status', 'failed')->count(),
                'indexed' => Document::where('is_indexed', true)->count(),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Actualiser')
                ->icon('heroicon-o-arrow-path')
                ->action(fn () => $this->refreshStatus()),

            Action::make('process_pending')
                ->label('Traiter documents en attente')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn () => ($this->documentStats['pending'] ?? 0) > 0)
                ->requiresConfirmation()
                ->modalHeading('Traiter les documents en attente')
                ->modalDescription('Cette action va traiter tous les documents en attente de manière synchrone. Cela peut prendre du temps.')
                ->action(function () {
                    $documents = Document::where('extraction_status', 'pending')->get();
                    $count = 0;
                    $errors = 0;

                    foreach ($documents as $document) {
                        try {
                            // Exécution synchrone du job
                            ProcessDocumentJob::dispatchSync($document);
                            $count++;
                        } catch (\Exception $e) {
                            $errors++;
                            \Log::error('Document processing failed', [
                                'document_id' => $document->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    $this->refreshStatus();

                    if ($errors > 0) {
                        Notification::make()
                            ->title('Traitement partiel')
                            ->body("{$count} document(s) traité(s), {$errors} erreur(s)")
                            ->warning()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Traitement terminé')
                            ->body("{$count} document(s) traité(s) avec succès")
                            ->success()
                            ->send();
                    }
                }),

            Action::make('retry_failed')
                ->label('Relancer les échecs')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => ($this->documentStats['failed'] ?? 0) > 0)
                ->requiresConfirmation()
                ->action(function () {
                    $documents = Document::where('extraction_status', 'failed')->get();
                    $count = 0;

                    foreach ($documents as $document) {
                        $document->update([
                            'extraction_status' => 'pending',
                            'extraction_error' => null,
                        ]);
                        ProcessDocumentJob::dispatchSync($document);
                        $count++;
                    }

                    $this->refreshStatus();

                    Notification::make()
                        ->title('Relance terminée')
                        ->body("{$count} document(s) relancé(s)")
                        ->success()
                        ->send();
                }),

            Action::make('clear_failed_jobs')
                ->label('Vider les jobs échoués')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn () => ($this->queueStats['failed'] ?? 0) > 0)
                ->requiresConfirmation()
                ->action(function () {
                    DB::table('failed_jobs')->truncate();
                    $this->refreshStatus();

                    Notification::make()
                        ->title('Jobs échoués supprimés')
                        ->success()
                        ->send();
                }),
        ];
    }
}

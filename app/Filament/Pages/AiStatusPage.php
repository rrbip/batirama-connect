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
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

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
    public array $failedDocuments = [];
    public array $failedJobs = [];

    public function mount(): void
    {
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        $this->services = $this->checkServices();
        $this->queueStats = $this->getQueueStats();
        $this->documentStats = $this->getDocumentStats();
        $this->failedDocuments = $this->getFailedDocuments();
        $this->failedJobs = $this->getFailedJobs();
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
                'restartable' => true,
                'restart_command' => 'docker restart ollama',
            ];
        } catch (\Exception $e) {
            $services['ollama'] = [
                'name' => 'Ollama (LLM)',
                'status' => 'offline',
                'details' => $e->getMessage(),
                'restartable' => true,
                'restart_command' => 'docker restart ollama',
            ];
        }

        // Qdrant
        try {
            $qdrant = app(QdrantService::class);
            $isHealthy = $qdrant->isHealthy();
            $collections = $isHealthy ? $qdrant->listCollections() : [];

            // Récupérer les détails de chaque collection (nombre de points)
            $collectionDetails = [];
            $totalPoints = 0;
            if ($isHealthy) {
                foreach ($collections as $collectionName) {
                    try {
                        $info = $qdrant->getCollectionInfo($collectionName);
                        $pointsCount = $info['points_count'] ?? 0;
                        $vectorsCount = $info['vectors_count'] ?? $pointsCount;
                        $collectionDetails[$collectionName] = [
                            'name' => $collectionName,
                            'points_count' => $pointsCount,
                            'vectors_count' => $vectorsCount,
                            'status' => $info['status'] ?? 'unknown',
                        ];
                        $totalPoints += $pointsCount;
                    } catch (\Exception $e) {
                        $collectionDetails[$collectionName] = [
                            'name' => $collectionName,
                            'points_count' => 0,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }

            $services['qdrant'] = [
                'name' => 'Qdrant (Vector DB)',
                'status' => $isHealthy ? 'online' : 'offline',
                'details' => $isHealthy
                    ? count($collections) . ' collection(s), ' . number_format($totalPoints) . ' point(s) total'
                    : 'Service non disponible',
                'collections' => $collections,
                'collection_details' => $collectionDetails,
                'total_points' => $totalPoints,
                'restartable' => true,
                'restart_command' => 'docker restart qdrant',
            ];
        } catch (\Exception $e) {
            $services['qdrant'] = [
                'name' => 'Qdrant (Vector DB)',
                'status' => 'offline',
                'details' => $e->getMessage(),
                'collections' => [],
                'collection_details' => [],
                'restartable' => true,
                'restart_command' => 'docker restart qdrant',
            ];
        }

        // Embedding Service (dépend d'Ollama)
        try {
            $embedding = app(EmbeddingService::class);
            $testVector = $embedding->embed('test');
            $services['embedding'] = [
                'name' => 'Embedding Service',
                'status' => !empty($testVector) ? 'online' : 'offline',
                'details' => !empty($testVector)
                    ? 'Dimension: ' . count($testVector)
                    : 'Erreur de génération',
                'restartable' => false,
                'depends_on' => 'ollama',
            ];
        } catch (\Exception $e) {
            $services['embedding'] = [
                'name' => 'Embedding Service',
                'status' => 'offline',
                'details' => $e->getMessage(),
                'restartable' => false,
                'depends_on' => 'ollama',
            ];
        }

        // Queue Worker
        $queueConnection = config('queue.default');
        if ($queueConnection === 'sync') {
            $services['queue'] = [
                'name' => 'Queue Worker',
                'status' => 'online',
                'details' => 'Mode synchrone (traitement immédiat)',
                'restartable' => false,
            ];
        } else {
            $oldestJob = null;
            try {
                $oldestJob = DB::table('jobs')->orderBy('created_at')->first();
            } catch (\Exception $e) {
                // Table n'existe pas
            }

            if ($oldestJob) {
                $age = now()->timestamp - $oldestJob->created_at;
                $isStuck = $age > 300;
                $services['queue'] = [
                    'name' => 'Queue Worker',
                    'status' => $isStuck ? 'warning' : 'online',
                    'details' => $isStuck
                        ? "Jobs en attente depuis " . gmdate('H:i:s', $age) . " - Worker probablement arrêté"
                        : "Worker actif (driver: {$queueConnection})",
                    'restartable' => true,
                    'restart_command' => 'supervisorctl restart laravel-worker:*',
                ];
            } else {
                $services['queue'] = [
                    'name' => 'Queue Worker',
                    'status' => 'unknown',
                    'details' => "Aucun job en file (driver: {$queueConnection})",
                    'restartable' => true,
                    'restart_command' => 'supervisorctl restart laravel-worker:*',
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

    protected function getFailedDocuments(): array
    {
        try {
            return Document::where('extraction_status', 'failed')
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get()
                ->map(fn ($doc) => [
                    'id' => $doc->id,
                    'name' => $doc->title ?? $doc->original_name,
                    'error' => $doc->extraction_error ?? 'Erreur inconnue',
                    'updated_at' => $doc->updated_at->format('d/m/Y H:i'),
                ])
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function getFailedJobs(): array
    {
        try {
            return DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(10)
                ->get()
                ->map(function ($job) {
                    $payload = json_decode($job->payload, true);
                    $displayName = $payload['displayName'] ?? 'Job inconnu';

                    // Extraire le message d'erreur principal
                    $exception = $job->exception;
                    $errorMessage = Str::before($exception, "\n");
                    $errorMessage = Str::limit($errorMessage, 200);

                    return [
                        'id' => $job->id,
                        'uuid' => $job->uuid,
                        'name' => class_basename($displayName),
                        'queue' => $job->queue,
                        'error' => $errorMessage,
                        'full_exception' => $exception,
                        'failed_at' => $job->failed_at,
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Relance un service Docker
     */
    public function restartService(string $serviceKey): void
    {
        $service = $this->services[$serviceKey] ?? null;

        if (!$service || !($service['restartable'] ?? false)) {
            Notification::make()
                ->title('Service non redémarrable')
                ->danger()
                ->send();
            return;
        }

        $command = $service['restart_command'] ?? null;
        if (!$command) {
            Notification::make()
                ->title('Commande de redémarrage non configurée')
                ->danger()
                ->send();
            return;
        }

        try {
            $result = Process::timeout(30)->run($command);

            if ($result->successful()) {
                // Attendre un peu que le service redémarre
                sleep(2);
                $this->refreshStatus();

                Notification::make()
                    ->title('Service redémarré')
                    ->body("Le service {$service['name']} a été redémarré.")
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Échec du redémarrage')
                    ->body($result->errorOutput() ?: 'Erreur inconnue')
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Relance un document échoué
     */
    public function retryDocument(int $documentId): void
    {
        $document = Document::find($documentId);
        if (!$document) {
            Notification::make()
                ->title('Document non trouvé')
                ->danger()
                ->send();
            return;
        }

        $document->update([
            'extraction_status' => 'pending',
            'extraction_error' => null,
        ]);

        try {
            ProcessDocumentJob::dispatchSync($document);

            $this->refreshStatus();

            Notification::make()
                ->title('Document retraité')
                ->body("Le document \"{$document->original_name}\" a été retraité.")
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Échec du retraitement')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->refreshStatus();
        }
    }

    /**
     * Relance un job échoué
     */
    public function retryFailedJob(string $uuid): void
    {
        try {
            \Artisan::call('queue:retry', ['id' => [$uuid]]);

            $this->refreshStatus();

            Notification::make()
                ->title('Job relancé')
                ->body("Le job a été remis en file d'attente.")
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Échec de la relance')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Supprime un job échoué
     */
    public function deleteFailedJob(string $uuid): void
    {
        try {
            DB::table('failed_jobs')->where('uuid', $uuid)->delete();

            $this->refreshStatus();

            Notification::make()
                ->title('Job supprimé')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur')
                ->body($e->getMessage())
                ->danger()
                ->send();
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

            Action::make('retry_all_failed')
                ->label('Relancer tous les échecs')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => ($this->documentStats['failed'] ?? 0) > 0)
                ->requiresConfirmation()
                ->action(function () {
                    $documents = Document::where('extraction_status', 'failed')->get();
                    $count = 0;
                    $errors = 0;

                    foreach ($documents as $document) {
                        $document->update([
                            'extraction_status' => 'pending',
                            'extraction_error' => null,
                        ]);

                        try {
                            ProcessDocumentJob::dispatchSync($document);
                            $count++;
                        } catch (\Exception $e) {
                            $errors++;
                        }
                    }

                    $this->refreshStatus();

                    Notification::make()
                        ->title('Relance terminée')
                        ->body("{$count} succès, {$errors} erreur(s)")
                        ->success()
                        ->send();
                }),

            Action::make('clear_failed_jobs')
                ->label('Vider les jobs échoués')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn () => ($this->queueStats['failed'] ?? 0) > 0)
                ->requiresConfirmation()
                ->modalHeading('Supprimer tous les jobs échoués ?')
                ->modalDescription('Cette action est irréversible.')
                ->action(function () {
                    DB::table('failed_jobs')->truncate();
                    $this->refreshStatus();

                    Notification::make()
                        ->title('Jobs échoués supprimés')
                        ->success()
                        ->send();
                }),

            Action::make('diagnose_qdrant')
                ->label('Diagnostic Qdrant')
                ->icon('heroicon-o-bug-ant')
                ->color('gray')
                ->action(function () {
                    try {
                        $qdrant = app(QdrantService::class);
                        $collections = $qdrant->listCollections();
                        $diagnostics = [];

                        foreach ($collections as $collectionName) {
                            $info = $qdrant->getCollectionInfo($collectionName);
                            $count = $qdrant->count($collectionName);

                            $diagnostics[] = sprintf(
                                "%s: info.points_count=%d, count()=%d, status=%s",
                                $collectionName,
                                $info['points_count'] ?? 0,
                                $count,
                                $info['status'] ?? 'unknown'
                            );
                        }

                        $message = empty($diagnostics)
                            ? 'Aucune collection trouvée'
                            : implode("\n", $diagnostics);

                        Notification::make()
                            ->title('Diagnostic Qdrant')
                            ->body($message)
                            ->success()
                            ->persistent()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Erreur diagnostic')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}

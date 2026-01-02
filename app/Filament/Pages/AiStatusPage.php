<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Jobs\ProcessAiMessageJob;
use App\Jobs\ProcessDocumentJob;
use App\Models\Agent;
use App\Models\AiMessage;
use App\Models\Document;
use App\Services\Support\ImapService;
use App\Services\AI\EmbeddingService;
use App\Services\AI\OllamaService;
use App\Services\AI\QdrantService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
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

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRole('super-admin') || $user->hasRole('admin'));
    }

    public array $services = [];
    public array $queueStats = [];
    public array $pendingJobs = [];
    public array $documentStats = [];
    public array $failedDocuments = [];
    public array $failedJobs = [];

    // Nouvelles propriétés pour les messages IA
    public array $aiMessageStats = [];
    public array $aiMessageQueue = [];
    public array $failedAiMessages = [];

    // Modèles Ollama
    public array $ollamaModels = [];
    public array $availableModels = [];
    public array $lastSyncInfo = [];
    public ?string $modelToInstall = null;
    public ?string $customModelName = null;
    public bool $isInstallingModel = false;

    // Support Email (IMAP)
    public array $emailSupportStatus = [];

    public function mount(): void
    {
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        $this->services = $this->checkServices();
        $this->queueStats = $this->getQueueStats();
        $this->pendingJobs = $this->getPendingJobs();
        $this->documentStats = $this->getDocumentStats();
        $this->failedDocuments = $this->getFailedDocuments();
        $this->failedJobs = $this->getFailedJobs();

        // Stats des messages IA
        $this->aiMessageStats = $this->getAiMessageStats();
        $this->aiMessageQueue = $this->getAiMessageQueue();
        $this->failedAiMessages = $this->getFailedAiMessages();

        // Modèles Ollama
        $this->loadOllamaModels();
        $this->availableModels = $this->getAvailableModels();
        $this->lastSyncInfo = $this->getLastSyncInfo();

        // Support Email
        $this->emailSupportStatus = $this->getEmailSupportStatus();
    }

    /**
     * Démarre un worker pour une queue spécifique
     */
    public function startQueueWorker(string $queue): void
    {
        try {
            $basePath = base_path();
            $command = "cd {$basePath} && nohup php artisan queue:work --queue={$queue} --stop-when-empty > /dev/null 2>&1 &";
            exec($command, $output, $returnCode);

            sleep(1);
            $this->refreshStatus();

            if ($returnCode === 0) {
                Notification::make()
                    ->title('Worker démarré')
                    ->body("Worker lancé pour la queue '{$queue}' (--stop-when-empty)")
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Échec du démarrage')
                    ->body("Impossible de démarrer le worker pour '{$queue}'")
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            \Log::error('Failed to start queue worker', [
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Erreur')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Annule un job en cours ou en attente
     */
    public function cancelJob(int $jobId): void
    {
        try {
            $job = DB::table('jobs')->where('id', $jobId)->first();

            if (!$job) {
                Notification::make()
                    ->title('Job non trouvé')
                    ->danger()
                    ->send();
                return;
            }

            // Extraire l'info du document si possible
            $payload = json_decode($job->payload, true);
            $data = $payload['data']['command'] ?? '';

            // Si c'est un job de document, remettre le statut du document
            if (preg_match('/document[";:\s]+[{]?[^}]*?"id";i:(\d+)/', $data, $matches) ||
                preg_match('/document_id[";:\s]+(\d+)/', $data, $matches)) {
                $documentId = (int) $matches[1];
                $document = Document::find($documentId);
                if ($document) {
                    $document->update([
                        'extraction_status' => 'pending',
                        'extraction_error' => 'Job annulé manuellement',
                    ]);
                }
            }

            // Supprimer le job
            DB::table('jobs')->where('id', $jobId)->delete();

            $this->refreshStatus();

            Notification::make()
                ->title('Job annulé')
                ->body('Le job a été supprimé de la file d\'attente.')
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

    /**
     * Supprime tous les jobs en attente d'une queue spécifique
     */
    public function clearQueueJobs(string $queueName): void
    {
        try {
            $count = DB::table('jobs')->where('queue', $queueName)->count();

            if ($count === 0) {
                Notification::make()
                    ->title('Queue vide')
                    ->body("Aucun job à supprimer dans la queue '{$queueName}'.")
                    ->warning()
                    ->send();
                return;
            }

            // Récupérer les jobs pour mettre à jour les documents associés
            $jobs = DB::table('jobs')->where('queue', $queueName)->get();

            foreach ($jobs as $job) {
                $payload = json_decode($job->payload, true);
                $data = $payload['data']['command'] ?? '';

                // Remettre les documents en pending
                if (preg_match('/document[";:\s]+[{]?[^}]*?"id";i:(\d+)/', $data, $matches) ||
                    preg_match('/document_id[";:\s]+(\d+)/', $data, $matches)) {
                    $documentId = (int) $matches[1];
                    $document = Document::find($documentId);
                    if ($document && $document->extraction_status === 'processing') {
                        $document->update([
                            'extraction_status' => 'pending',
                            'extraction_error' => 'Job supprimé manuellement',
                        ]);
                    }
                }
            }

            // Supprimer tous les jobs de la queue
            DB::table('jobs')->where('queue', $queueName)->delete();

            $this->refreshStatus();

            Notification::make()
                ->title('Queue vidée')
                ->body("{$count} job(s) supprimé(s) de la queue '{$queueName}'.")
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

    /**
     * Charge les modèles Ollama installés avec leurs détails
     */
    protected function loadOllamaModels(): void
    {
        try {
            $ollama = app(OllamaService::class);
            $this->ollamaModels = $ollama->listModelsWithDetails();
        } catch (\Exception $e) {
            $this->ollamaModels = [];
        }
    }

    /**
     * Retourne la liste des modèles disponibles à l'installation
     * (en filtrant ceux déjà installés)
     */
    protected function getAvailableModels(): array
    {
        // Récupérer les modèles depuis le cache ou la config
        $cachedModels = Cache::get('ollama_available_models', []);
        $configModels = config('ai.ollama.available_models', []);

        // Fusionner: cache a priorité sur config pour les clés identiques
        $allModels = array_merge($configModels, $cachedModels);

        $installedNames = collect($this->ollamaModels)->pluck('name')->toArray();

        return collect($allModels)
            ->filter(fn ($details, $modelKey) => !in_array($modelKey, $installedNames))
            ->toArray();
    }

    /**
     * Synchronise la liste des modèles disponibles
     */
    public function syncAvailableModels(): void
    {
        $ollama = app(OllamaService::class);
        $syncedModels = [];
        $source = 'config';

        // Option 1: Essayer de récupérer depuis une URL configurée
        $modelsListUrl = config('ai.ollama.models_list_url');
        if ($modelsListUrl) {
            $urlModels = $ollama->fetchModelsFromUrl($modelsListUrl);
            if ($urlModels && !empty($urlModels)) {
                $syncedModels = $urlModels;
                $source = 'url';
            }
        }

        // Option 2: Sinon, récupérer les infos des modèles populaires depuis Ollama
        if (empty($syncedModels)) {
            $popularModels = $ollama->fetchPopularModelsInfo();
            if (!empty($popularModels)) {
                $syncedModels = $popularModels;
                $source = 'ollama';
            }
        }

        // Option 3: Utiliser la liste de config comme fallback
        if (empty($syncedModels)) {
            $syncedModels = config('ai.ollama.available_models', []);
            $source = 'config (fallback)';
        }

        // Sauvegarder en cache pour 24h
        Cache::put('ollama_available_models', $syncedModels, now()->addHours(24));
        Cache::put('ollama_models_last_sync', now()->toDateTimeString(), now()->addHours(24));
        Cache::put('ollama_models_sync_source', $source, now()->addHours(24));

        $this->refreshStatus();

        Notification::make()
            ->title('Liste synchronisée')
            ->body("Modèles disponibles mis à jour depuis: {$source} (" . count($syncedModels) . " modèles)")
            ->success()
            ->send();
    }

    /**
     * Retourne les infos de dernière synchronisation
     */
    public function getLastSyncInfo(): array
    {
        return [
            'last_sync' => Cache::get('ollama_models_last_sync'),
            'source' => Cache::get('ollama_models_sync_source', 'config'),
        ];
    }

    /**
     * Récupère le statut du support email (IMAP)
     */
    protected function getEmailSupportStatus(): array
    {
        try {
            // Agents avec support email configuré
            $agents = Agent::where('human_support_enabled', true)
                ->whereNotNull('support_email')
                ->get();

            if ($agents->isEmpty()) {
                return [
                    'configured' => false,
                    'message' => 'Aucun agent avec support email configuré',
                    'agents' => [],
                ];
            }

            // Lire les dernières lignes du log
            $logFile = storage_path('logs/support-emails.log');
            $lastLog = null;
            $lastRun = null;

            if (file_exists($logFile)) {
                $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $lastLines = array_slice($lines, -10);
                $lastLog = implode("\n", $lastLines);

                // Extraire la date de la dernière exécution
                foreach (array_reverse($lastLines) as $line) {
                    if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                        $lastRun = $matches[1];
                        break;
                    }
                }
            }

            // Vérifier le cache du scheduler
            $schedulerLastRun = Cache::get('support:fetch-emails:last_run');

            $agentsList = $agents->map(fn ($agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'email' => $agent->support_email,
                'has_imap' => !empty($agent->getImapConfig()),
            ])->toArray();

            return [
                'configured' => true,
                'agents_count' => $agents->count(),
                'agents' => $agentsList,
                'last_run' => $schedulerLastRun ?? $lastRun,
                'last_log' => $lastLog,
                'log_file' => $logFile,
            ];
        } catch (\Exception $e) {
            return [
                'configured' => false,
                'error' => $e->getMessage(),
                'agents' => [],
            ];
        }
    }

    /**
     * Force la récupération des emails IMAP
     */
    public function fetchSupportEmails(): void
    {
        try {
            \Artisan::call('support:fetch-emails');
            $output = \Artisan::output();

            // Sauvegarder la date de dernière exécution
            Cache::put('support:fetch-emails:last_run', now()->format('Y-m-d H:i:s'), now()->addHours(24));

            $this->refreshStatus();

            Notification::make()
                ->title('Emails récupérés')
                ->body($output ?: 'Commande exécutée avec succès')
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
                    'restart_command' => 'php artisan queue:restart',
                ];
            } else {
                $services['queue'] = [
                    'name' => 'Queue Worker',
                    'status' => 'unknown',
                    'details' => "Aucun job en file (driver: {$queueConnection})",
                    'restartable' => true,
                    'restart_command' => 'php artisan queue:restart',
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

            // Stats par queue
            $byQueue = DB::table('jobs')
                ->select('queue', DB::raw('count(*) as count'))
                ->groupBy('queue')
                ->pluck('count', 'queue')
                ->toArray();

            return [
                'pending' => $pending,
                'failed' => $failed,
                'connection' => config('queue.default'),
                'by_queue' => $byQueue,
            ];
        } catch (\Exception $e) {
            return [
                'pending' => 0,
                'failed' => 0,
                'connection' => config('queue.default'),
                'by_queue' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Récupère les détails des jobs par queue
     */
    protected function getPendingJobs(): array
    {
        try {
            $jobs = DB::table('jobs')->orderBy('created_at')->get();

            // Grouper par queue
            $queues = [];

            foreach ($jobs as $job) {
                $queue = $job->queue;

                if (!isset($queues[$queue])) {
                    $queues[$queue] = [
                        'name' => $queue,
                        'total' => 0,
                        'processing' => null,
                        'waiting' => [],
                        'status' => 'waiting', // waiting, processing, stuck
                        'status_label' => 'En attente',
                    ];
                }

                $queues[$queue]['total']++;

                $payload = json_decode($job->payload, true);
                $displayName = $payload['displayName'] ?? 'Job inconnu';

                // Extraire info document
                $data = $payload['data']['command'] ?? null;
                $documentInfo = null;
                if ($data && str_contains($data, 'Document')) {
                    if (preg_match('/document_id[";:\s]+(\d+)/', $data, $matches)) {
                        $documentInfo = "Document #{$matches[1]}";
                    }
                }

                $isReserved = !empty($job->reserved_at);
                $reservedTime = $isReserved ? (now()->timestamp - $job->reserved_at) : 0;
                $waitTime = now()->timestamp - $job->created_at;

                $jobData = [
                    'id' => $job->id,
                    'name' => class_basename($displayName),
                    'document' => $documentInfo,
                    'wait_time_human' => $waitTime > 60 ? gmdate('H:i:s', $waitTime) : "{$waitTime}s",
                    'attempts' => $job->attempts,
                ];

                if ($isReserved) {
                    // Job en cours de traitement
                    // Seuils différents par queue (en secondes)
                    $stuckThresholds = [
                        'llm-chunking' => 1800, // 30 minutes - LLM peut être lent
                        'pipeline' => 1800,     // 30 minutes - Pipeline traitement complet
                        'default' => 600,       // 10 minutes
                        'ai-messages' => 300,   // 5 minutes
                    ];
                    $threshold = $stuckThresholds[$queue] ?? 300;
                    $isStuck = $reservedTime > $threshold;

                    $queues[$queue]['processing'] = array_merge($jobData, [
                        'processing_time' => $reservedTime > 60 ? gmdate('H:i:s', $reservedTime) : "{$reservedTime}s",
                        'is_stuck' => $isStuck,
                    ]);

                    if ($isStuck) {
                        $queues[$queue]['status'] = 'stuck';
                        $queues[$queue]['status_label'] = 'Bloqué';
                    } else {
                        $queues[$queue]['status'] = 'processing';
                        $queues[$queue]['status_label'] = 'En cours';
                    }
                } else {
                    // Job en attente - garder seulement les 5 premiers
                    if (count($queues[$queue]['waiting']) < 5) {
                        $queues[$queue]['waiting'][] = $jobData;
                    }
                }
            }

            return array_values($queues);
        } catch (\Exception $e) {
            return [];
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
     * Statistiques des messages IA asynchrones
     */
    protected function getAiMessageStats(): array
    {
        try {
            return [
                'pending' => AiMessage::assistantMessages()->pending()->count(),
                'queued' => AiMessage::assistantMessages()->queued()->count(),
                'processing' => AiMessage::assistantMessages()->processing()->count(),
                'completed_today' => AiMessage::assistantMessages()
                    ->completed()
                    ->whereDate('processing_completed_at', today())
                    ->count(),
                'failed_today' => AiMessage::assistantMessages()
                    ->failed()
                    ->whereDate('processing_completed_at', today())
                    ->count(),
                'failed_total' => AiMessage::assistantMessages()->failed()->count(),
                'avg_generation_time_ms' => (int) AiMessage::assistantMessages()
                    ->completed()
                    ->whereDate('processing_completed_at', today())
                    ->avg('generation_time_ms'),
                'in_queue_total' => AiMessage::assistantMessages()->inQueue()->count(),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Messages IA actuellement en file d'attente
     */
    protected function getAiMessageQueue(): array
    {
        try {
            return AiMessage::assistantMessages()
                ->inQueue()
                ->with(['session.agent'])
                ->orderBy('queued_at')
                ->orderBy('created_at')
                ->limit(20)
                ->get()
                ->map(function ($msg, $index) {
                    return [
                        'position' => $index + 1,
                        'id' => $msg->id,
                        'uuid' => $msg->uuid,
                        'agent' => $msg->session?->agent?->name ?? 'Inconnu',
                        'status' => $msg->processing_status,
                        'queued_at' => $msg->queued_at?->format('H:i:s'),
                        'processing_started_at' => $msg->processing_started_at?->format('H:i:s'),
                        'wait_time' => $msg->queued_at
                            ? $msg->queued_at->diffForHumans(short: true)
                            : ($msg->created_at ? $msg->created_at->diffForHumans(short: true) : null),
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Messages IA en échec
     */
    protected function getFailedAiMessages(): array
    {
        try {
            return AiMessage::assistantMessages()
                ->failed()
                ->with(['session.agent'])
                ->orderByDesc('processing_completed_at')
                ->limit(20)
                ->get()
                ->map(fn ($msg) => [
                    'id' => $msg->id,
                    'uuid' => $msg->uuid,
                    'session_uuid' => $msg->session?->uuid,
                    'agent' => $msg->session?->agent?->name ?? 'Inconnu',
                    'error' => Str::limit($msg->processing_error ?? 'Erreur inconnue', 150),
                    'full_error' => $msg->processing_error,
                    'retry_count' => $msg->retry_count,
                    'failed_at' => $msg->processing_completed_at?->format('d/m/Y H:i'),
                    'queued_at' => $msg->queued_at?->format('d/m/Y H:i'),
                ])
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Relance un message IA en échec
     */
    public function retryAiMessage(int $messageId): void
    {
        $message = AiMessage::find($messageId);

        if (!$message || $message->processing_status !== AiMessage::STATUS_FAILED) {
            Notification::make()
                ->title('Message non trouvé ou pas en échec')
                ->danger()
                ->send();
            return;
        }

        // Récupérer le message utilisateur original
        $userMessage = AiMessage::where('session_id', $message->session_id)
            ->where('role', 'user')
            ->where('created_at', '<', $message->created_at)
            ->orderByDesc('created_at')
            ->first();

        if (!$userMessage) {
            Notification::make()
                ->title('Message utilisateur non trouvé')
                ->danger()
                ->send();
            return;
        }

        // Réinitialiser et relancer
        $message->resetForRetry();
        $message->increment('retry_count');

        dispatch(new ProcessAiMessageJob($message, $userMessage->content));
        $message->markAsQueued();

        $this->refreshStatus();

        Notification::make()
            ->title('Message relancé')
            ->body("Le message a été remis en file d'attente.")
            ->success()
            ->send();
    }

    /**
     * Supprime un message IA en échec
     */
    public function deleteAiMessage(int $messageId): void
    {
        $message = AiMessage::find($messageId);

        if (!$message) {
            Notification::make()
                ->title('Message non trouvé')
                ->danger()
                ->send();
            return;
        }

        $message->delete();

        $this->refreshStatus();

        Notification::make()
            ->title('Message supprimé')
            ->success()
            ->send();
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
     * Supprime un document échoué
     */
    public function deleteFailedDocument(int $documentId): void
    {
        $document = Document::find($documentId);
        if (!$document) {
            Notification::make()
                ->title('Document non trouvé')
                ->danger()
                ->send();
            return;
        }

        $documentName = $document->original_name ?? $document->title ?? "Document #{$documentId}";

        // Supprimer les chunks associés
        $document->chunks()->delete();

        // Supprimer le document
        $document->delete();

        $this->refreshStatus();

        Notification::make()
            ->title('Document supprimé')
            ->body("Le document \"{$documentName}\" a été supprimé.")
            ->success()
            ->send();
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

    /**
     * Supprime un modèle Ollama
     */
    public function deleteOllamaModel(string $modelName): void
    {
        try {
            $ollama = app(OllamaService::class);

            if ($ollama->deleteModel($modelName)) {
                $this->refreshStatus();

                Notification::make()
                    ->title('Modèle supprimé')
                    ->body("Le modèle {$modelName} a été supprimé avec succès.")
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Échec de la suppression')
                    ->body("Impossible de supprimer le modèle {$modelName}.")
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
     * Installe un modèle Ollama
     */
    public function installOllamaModel(?string $modelName = null): void
    {
        // Utiliser le nom personnalisé si fourni, sinon le modèle sélectionné
        $model = $modelName ?? $this->customModelName ?? $this->modelToInstall;

        if (empty($model)) {
            Notification::make()
                ->title('Aucun modèle sélectionné')
                ->body('Veuillez sélectionner un modèle ou entrer un nom de modèle.')
                ->warning()
                ->send();
            return;
        }

        $this->isInstallingModel = true;

        Notification::make()
            ->title('Installation en cours')
            ->body("Téléchargement du modèle {$model}... Cela peut prendre plusieurs minutes.")
            ->info()
            ->persistent()
            ->send();

        try {
            $ollama = app(OllamaService::class);

            if ($ollama->pullModel($model)) {
                $this->isInstallingModel = false;
                $this->modelToInstall = null;
                $this->customModelName = null;

                $this->refreshStatus();

                Notification::make()
                    ->title('Modèle installé')
                    ->body("Le modèle {$model} a été installé avec succès.")
                    ->success()
                    ->send();
            } else {
                $this->isInstallingModel = false;

                Notification::make()
                    ->title('Échec de l\'installation')
                    ->body("Impossible d'installer le modèle {$model}. Vérifiez que le nom est correct.")
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            $this->isInstallingModel = false;

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

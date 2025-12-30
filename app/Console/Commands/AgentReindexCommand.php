<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RebuildAgentIndexJob;
use App\Models\Agent;
use App\Models\Ouvrage;
use App\Services\AI\EmbeddingService;
use App\Services\AI\QdrantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AgentReindexCommand extends Command
{
    protected $signature = 'agent:reindex
                            {slug? : Slug de l\'agent à réindexer (optionnel si --all)}
                            {--all : Réindexe tous les agents}
                            {--force : Supprime et recrée la collection}
                            {--sync : Exécute en synchrone au lieu de dispatcher un job}';

    protected $description = 'Réindexe les données d\'un agent dans Qdrant (format Q/R Atomique)';

    public function __construct(
        private QdrantService $qdrantService,
        private EmbeddingService $embeddingService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $force = $this->option('force');
        $all = $this->option('all');
        $sync = $this->option('sync');

        // Mode --all : réindexer tous les agents
        if ($all) {
            return $this->reindexAllAgents($force, $sync);
        }

        // Mode normal : un agent spécifique
        if (!$slug) {
            $this->error("Veuillez spécifier un slug d'agent ou utiliser --all");
            return Command::FAILURE;
        }

        $agent = Agent::where('slug', $slug)->first();

        if (!$agent) {
            $this->error("Agent '{$slug}' non trouvé");
            return Command::FAILURE;
        }

        return $this->reindexAgent($agent, $force, $sync);
    }

    /**
     * Réindexe tous les agents avec collection Qdrant.
     */
    private function reindexAllAgents(bool $force, bool $sync): int
    {
        $agents = Agent::whereNotNull('qdrant_collection')
            ->where('is_active', true)
            ->get();

        if ($agents->isEmpty()) {
            $this->warn("Aucun agent actif avec collection Qdrant trouvé");
            return Command::SUCCESS;
        }

        $this->info("🔄 Réindexation de {$agents->count()} agents...");
        $this->newLine();

        $success = 0;
        $failed = 0;

        foreach ($agents as $agent) {
            try {
                $this->line("  → {$agent->name} ({$agent->slug})");

                if ($sync) {
                    // Exécution synchrone
                    $this->reindexAgent($agent, $force, true);
                } else {
                    // Dispatcher le job
                    RebuildAgentIndexJob::dispatch($agent);
                    $this->info("    Job dispatché");
                }

                $success++;
            } catch (\Exception $e) {
                $this->error("    Erreur: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();

        if ($sync) {
            $this->info("✅ {$success} agents réindexés" . ($failed > 0 ? ", {$failed} erreurs" : ""));
        } else {
            $this->info("✅ {$success} jobs de réindexation lancés" . ($failed > 0 ? ", {$failed} erreurs" : ""));
            $this->line("   Suivez la progression dans les logs ou le tableau de bord des jobs.");
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Réindexe un agent spécifique.
     */
    private function reindexAgent(Agent $agent, bool $force, bool $sync): int
    {
        $collection = $agent->qdrant_collection;

        if (!$collection) {
            $this->error("L'agent '{$agent->slug}' n'a pas de collection Qdrant configurée");
            return Command::FAILURE;
        }

        $this->info("🔄 Réindexation de l'agent '{$agent->name}'");
        $this->line("   Collection: {$collection}");
        $this->line("   Mode RAG: {$agent->retrieval_mode}");
        $this->line("   Méthode d'indexation: {$agent->getIndexingMethod()->label()}");
        $this->newLine();

        // Supprimer et recréer si --force
        if ($force) {
            if ($this->qdrantService->collectionExists($collection)) {
                $this->qdrantService->deleteCollection($collection);
                $this->info("   🗑️  Collection supprimée");
            }

            $config = config("qdrant.collections.{$collection}", [
                'vector_size' => config('ai.qdrant.vector_size', 768),
                'distance' => config('ai.qdrant.distance', 'Cosine'),
            ]);

            $this->qdrantService->createCollection($collection, $config);
            $this->info("   ✅ Collection recréée");
        }

        // Réindexer selon le type d'agent
        return match ($agent->retrieval_mode) {
            'SQL_HYDRATION' => $this->reindexSqlHydration($agent),
            'TEXT_ONLY' => $this->reindexTextOnly($agent),
            default => $this->reindexGeneric($agent, $sync),
        };
    }

    private function reindexSqlHydration(Agent $agent): int
    {
        $this->info("   📦 Mode SQL_HYDRATION - Indexation des ouvrages...");

        // Réinitialiser le flag d'indexation
        Ouvrage::query()->update([
            'is_indexed' => false,
            'indexed_at' => null,
            'qdrant_point_id' => null,
        ]);

        // Appeler la commande d'indexation des ouvrages
        $this->call('ouvrages:index', [
            '--force' => true,
            '--collection' => $agent->qdrant_collection,
        ]);

        return Command::SUCCESS;
    }

    private function reindexTextOnly(Agent $agent): int
    {
        $this->info("   📚 Mode TEXT_ONLY - Indexation des documents...");

        // Chercher les documents liés à cet agent
        $jsonPath = storage_path('app/seed-data/support-docs.json');

        if (!file_exists($jsonPath)) {
            $this->warn("   Aucun document de seed trouvé");
            return Command::SUCCESS;
        }

        $docs = json_decode(file_get_contents($jsonPath), true);

        if (empty($docs)) {
            $this->warn("   Fichier support-docs.json vide");
            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($docs));
        $bar->start();

        $points = [];
        $indexed = 0;

        foreach ($docs as $doc) {
            try {
                $text = $doc['title'] . "\n\n" . $doc['content'];
                $embedding = $this->embeddingService->embed($text);

                $pointId = 'doc_' . $doc['slug'];

                $points[] = [
                    'id' => $pointId,
                    'vector' => $embedding,
                    'payload' => [
                        'slug' => $doc['slug'],
                        'title' => $doc['title'],
                        'content' => $doc['content'],
                        'category' => $doc['category'],
                        'source' => 'reindex',
                        'indexed_at' => now()->toISOString(),
                    ],
                ];

                $indexed++;

            } catch (\Exception $e) {
                Log::error("Erreur réindexation doc {$doc['slug']}", ['error' => $e->getMessage()]);
            }

            $bar->advance();
        }

        if (!empty($points)) {
            $this->qdrantService->upsert($agent->qdrant_collection, $points);
        }

        $bar->finish();
        $this->newLine();
        $this->info("   ✅ {$indexed} documents réindexés");

        return Command::SUCCESS;
    }

    /**
     * Réindexe un agent en mode générique (Q/R Atomique).
     */
    private function reindexGeneric(Agent $agent, bool $sync = false): int
    {
        $this->info("   📚 Mode générique - Réindexation Q/R Atomique...");

        $documentsCount = $agent->documents()->count();
        $chunksCount = $agent->documents()
            ->withCount('chunks')
            ->get()
            ->sum('chunks_count');

        $this->line("   Documents: {$documentsCount}");
        $this->line("   Chunks: {$chunksCount}");

        if ($documentsCount === 0) {
            $this->warn("   Aucun document à indexer");
            return Command::SUCCESS;
        }

        if ($sync) {
            // Exécution synchrone
            $this->line("   Exécution synchrone...");

            try {
                $job = new RebuildAgentIndexJob($agent);
                $job->handle(
                    app(QdrantService::class),
                    app(EmbeddingService::class)
                );

                $this->info("   ✅ Réindexation terminée");
            } catch (\Exception $e) {
                $this->error("   ❌ Erreur: {$e->getMessage()}");
                Log::error("AgentReindexCommand: Erreur réindexation", [
                    'agent' => $agent->slug,
                    'error' => $e->getMessage(),
                ]);
                return Command::FAILURE;
            }
        } else {
            // Dispatcher le job
            RebuildAgentIndexJob::dispatch($agent);
            $this->info("   ✅ Job de réindexation dispatché");
            $this->line("   Suivez la progression dans les logs.");
        }

        return Command::SUCCESS;
    }
}

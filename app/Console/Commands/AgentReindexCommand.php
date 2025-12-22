<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Ouvrage;
use App\Services\AI\EmbeddingService;
use App\Services\AI\QdrantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AgentReindexCommand extends Command
{
    protected $signature = 'agent:reindex
                            {slug : Slug de l\'agent à réindexer}
                            {--force : Supprime et recrée la collection}';

    protected $description = 'Réindexe les données d\'un agent dans Qdrant';

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

        $agent = Agent::where('slug', $slug)->first();

        if (!$agent) {
            $this->error("Agent '{$slug}' non trouvé");
            return Command::FAILURE;
        }

        $collection = $agent->qdrant_collection;

        if (!$collection) {
            $this->error("L'agent '{$slug}' n'a pas de collection Qdrant configurée");
            return Command::FAILURE;
        }

        $this->info("🔄 Réindexation de l'agent '{$agent->name}'");
        $this->line("   Collection: {$collection}");
        $this->line("   Mode RAG: {$agent->retrieval_mode}");
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
            default => $this->reindexGeneric($agent),
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

    private function reindexGeneric(Agent $agent): int
    {
        $this->warn("   Mode générique - Pas d'action automatique");
        $this->line("   Utilisez une commande spécifique pour cet agent");

        return Command::SUCCESS;
    }
}

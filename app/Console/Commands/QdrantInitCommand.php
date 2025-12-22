<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Ouvrage;
use App\Services\AI\EmbeddingService;
use App\Services\AI\QdrantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class QdrantInitCommand extends Command
{
    protected $signature = 'qdrant:init
                            {--with-test-data : Indexe également les données de test}
                            {--force : Recrée les collections même si elles existent}';

    protected $description = 'Initialise les collections Qdrant et optionnellement les données de test';

    public function __construct(
        private QdrantService $qdrantService,
        private EmbeddingService $embeddingService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $withTestData = $this->option('with-test-data');
        $force = $this->option('force');

        $this->info('🧠 Initialisation de Qdrant...');

        // 1. Vérifier la connexion
        if (!$this->checkConnection()) {
            return Command::FAILURE;
        }

        // 2. Création des collections
        $this->createCollections($force);

        // 3. Indexation des données de test si demandé
        if ($withTestData) {
            $this->newLine();
            $this->info('📊 Indexation des données de test...');

            $this->indexOuvrages();
            $this->indexSupportDocs();
        }

        $this->newLine();
        $this->info('✅ Initialisation Qdrant terminée !');

        return Command::SUCCESS;
    }

    private function checkConnection(): bool
    {
        try {
            $healthy = $this->qdrantService->isHealthy();

            if (!$healthy) {
                $this->error('❌ Qdrant n\'est pas accessible');
                return false;
            }

            $this->info('   ✅ Connexion Qdrant OK');
            return true;

        } catch (\Exception $e) {
            $this->error('❌ Erreur de connexion Qdrant: ' . $e->getMessage());
            return false;
        }
    }

    private function createCollections(bool $force): void
    {
        $collections = config('qdrant.collections', []);

        foreach ($collections as $name => $config) {
            $exists = $this->qdrantService->collectionExists($name);

            if ($exists && !$force) {
                $this->line("   ⏭️  Collection '{$name}' existe déjà");
                continue;
            }

            if ($exists && $force) {
                $this->qdrantService->deleteCollection($name);
                $this->line("   🗑️  Collection '{$name}' supprimée");
            }

            $success = $this->qdrantService->createCollection($name, $config);

            if ($success) {
                $this->info("   ✅ Collection '{$name}' créée");

                // Créer les index de payload si configurés
                if (!empty($config['payload_indexes'])) {
                    $this->createPayloadIndexes($name, $config['payload_indexes']);
                }
            } else {
                $this->error("   ❌ Erreur création '{$name}'");
            }
        }
    }

    private function createPayloadIndexes(string $collection, array $indexes): void
    {
        foreach ($indexes as $field => $type) {
            try {
                $this->qdrantService->createPayloadIndex($collection, $field, $type);
            } catch (\Exception $e) {
                Log::warning("Index '{$field}' non créé pour '{$collection}': {$e->getMessage()}");
            }
        }
    }

    private function indexOuvrages(): void
    {
        $ouvrages = Ouvrage::where('is_indexed', false)->get();

        if ($ouvrages->isEmpty()) {
            $this->line('   ⏭️  Aucun ouvrage à indexer');
            return;
        }

        $this->line("   📦 Indexation de {$ouvrages->count()} ouvrages...");

        $bar = $this->output->createProgressBar($ouvrages->count());
        $bar->start();

        $points = [];
        $indexed = 0;

        foreach ($ouvrages as $ouvrage) {
            try {
                $description = $this->buildOuvrageDescription($ouvrage);
                $embedding = $this->embeddingService->embed($description);

                $pointId = 'ouvrage_' . $ouvrage->id;

                $points[] = [
                    'id' => $pointId,
                    'vector' => $embedding,
                    'payload' => [
                        'db_id' => $ouvrage->id,
                        'code' => $ouvrage->code,
                        'type' => $ouvrage->type,
                        'category' => $ouvrage->category,
                        'subcategory' => $ouvrage->subcategory,
                        'content' => $description,
                        'unit' => $ouvrage->unit,
                        'unit_price' => (float) $ouvrage->unit_price,
                        'tenant_id' => $ouvrage->tenant_id,
                        'indexed_at' => now()->toISOString(),
                    ],
                ];

                $ouvrage->update([
                    'is_indexed' => true,
                    'indexed_at' => now(),
                    'qdrant_point_id' => $pointId,
                ]);

                $indexed++;

            } catch (\Exception $e) {
                Log::error("Erreur indexation ouvrage {$ouvrage->id}", ['error' => $e->getMessage()]);
            }

            $bar->advance();
        }

        // Envoi batch
        if (!empty($points)) {
            $this->qdrantService->upsert('agent_btp_ouvrages', $points);
        }

        $bar->finish();
        $this->newLine();
        $this->info("   ✅ {$indexed} ouvrages indexés dans 'agent_btp_ouvrages'");
    }

    private function indexSupportDocs(): void
    {
        $jsonPath = storage_path('app/seed-data/support-docs.json');

        if (!file_exists($jsonPath)) {
            $this->line('   ⏭️  Aucun document support à indexer');
            return;
        }

        $docs = json_decode(file_get_contents($jsonPath), true);

        if (empty($docs)) {
            $this->line('   ⏭️  Fichier support-docs.json vide');
            return;
        }

        $this->line("   📚 Indexation de " . count($docs) . " documents support...");

        $bar = $this->output->createProgressBar(count($docs));
        $bar->start();

        $points = [];
        $indexed = 0;

        foreach ($docs as $doc) {
            try {
                // Combiner titre et contenu pour l'embedding
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
                        'source' => 'seed',
                        'indexed_at' => now()->toISOString(),
                    ],
                ];

                $indexed++;

            } catch (\Exception $e) {
                Log::error("Erreur indexation doc {$doc['slug']}", ['error' => $e->getMessage()]);
            }

            $bar->advance();
        }

        // Envoi batch
        if (!empty($points)) {
            $this->qdrantService->upsert('agent_support_docs', $points);
        }

        $bar->finish();
        $this->newLine();
        $this->info("   ✅ {$indexed} documents indexés dans 'agent_support_docs'");
    }

    private function buildOuvrageDescription(Ouvrage $ouvrage): string
    {
        $parts = [
            $ouvrage->name . '.',
        ];

        if ($ouvrage->description) {
            $parts[] = $ouvrage->description;
        }

        if ($ouvrage->category) {
            $cat = "Catégorie: {$ouvrage->category}";
            if ($ouvrage->subcategory) {
                $cat .= " / {$ouvrage->subcategory}";
            }
            $parts[] = $cat . '.';
        }

        $parts[] = "Unité: {$ouvrage->unit}. Prix: " .
            number_format((float) $ouvrage->unit_price, 2, ',', ' ') . " €.";

        if (!empty($ouvrage->technical_specs)) {
            $specs = collect($ouvrage->technical_specs)
                ->map(fn($v, $k) => ucfirst(str_replace('_', ' ', $k)) . ": $v")
                ->join(', ');
            $parts[] = "Caractéristiques: {$specs}.";
        }

        return implode(' ', $parts);
    }
}

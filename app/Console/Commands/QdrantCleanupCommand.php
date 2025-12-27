<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DocumentChunk;
use App\Services\AI\QdrantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QdrantCleanupCommand extends Command
{
    protected $signature = 'qdrant:cleanup
                            {collection : Nom de la collection à nettoyer}
                            {--dry-run : Affiche ce qui serait supprimé sans supprimer}
                            {--force : Supprime sans confirmation}';

    protected $description = 'Supprime les points orphelins de Qdrant (points sans chunk correspondant en DB)';

    public function __construct(
        private QdrantService $qdrantService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $collection = $this->argument('collection');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        // Vérifier la connexion
        if (! $this->qdrantService->isHealthy()) {
            $this->error('❌ Qdrant n\'est pas accessible');

            return Command::FAILURE;
        }

        if (! $this->qdrantService->collectionExists($collection)) {
            $this->error("❌ La collection '{$collection}' n'existe pas");

            return Command::FAILURE;
        }

        $this->info("🔍 Analyse de la collection '{$collection}'...");

        // Récupérer les infos de la collection
        $info = $this->qdrantService->getCollectionInfo($collection);
        $totalPoints = $info['points_count'] ?? 0;

        $this->line("   Points dans Qdrant: {$totalPoints}");

        // Récupérer tous les IDs de chunks valides en DB pour cette collection
        // Les chunks ont un qdrant_point_id qui correspond au point_id dans Qdrant
        $validChunkIds = DocumentChunk::whereHas('document.agent', function ($query) use ($collection) {
            $query->where('qdrant_collection', $collection);
        })->whereNotNull('qdrant_point_id')
            ->pluck('qdrant_point_id')
            ->map(fn ($id) => (string) $id)
            ->toArray();

        $this->line('   Chunks valides en DB: ' . count($validChunkIds));

        // Parcourir tous les points de Qdrant pour trouver les orphelins
        $orphanIds = [];
        $offset = null;
        $processedCount = 0;

        $this->info('🔄 Recherche des points orphelins...');

        $progressBar = $this->output->createProgressBar($totalPoints);
        $progressBar->start();

        do {
            $result = $this->qdrantService->scroll(
                collection: $collection,
                limit: 100,
                offset: $offset,
                withFullResult: true
            );

            foreach ($result['points'] ?? [] as $point) {
                $pointId = (string) $point['id'];

                if (! in_array($pointId, $validChunkIds, true)) {
                    $orphanIds[] = $pointId;
                }

                $processedCount++;
                $progressBar->advance();
            }

            $offset = $result['next_page_offset'] ?? null;

        } while ($offset !== null);

        $progressBar->finish();
        $this->newLine(2);

        // Afficher les résultats
        $this->info('📊 Résultats de l\'analyse:');
        $this->line("   Points analysés: {$processedCount}");
        $this->line('   Points orphelins: ' . count($orphanIds));
        $this->line('   Points valides: ' . ($processedCount - count($orphanIds)));

        if (empty($orphanIds)) {
            $this->info('✅ Aucun point orphelin trouvé. La collection est propre.');

            return Command::SUCCESS;
        }

        // Afficher un échantillon des orphelins
        $this->newLine();
        $this->warn('⚠️ ' . count($orphanIds) . ' points orphelins trouvés');

        if ($dryRun) {
            $this->info('🔍 Mode dry-run - Échantillon des orphelins:');
            foreach (array_slice($orphanIds, 0, 5) as $id) {
                $this->line("   - {$id}");
            }
            if (count($orphanIds) > 5) {
                $this->line('   ... et ' . (count($orphanIds) - 5) . ' autres');
            }

            return Command::SUCCESS;
        }

        // Demander confirmation
        if (! $force && ! $this->confirm('Voulez-vous supprimer ces ' . count($orphanIds) . ' points orphelins?')) {
            $this->info('Opération annulée.');

            return Command::SUCCESS;
        }

        // Supprimer les orphelins par batch
        $this->info('🗑️ Suppression des points orphelins...');

        $batches = array_chunk($orphanIds, 100);
        $deletedCount = 0;

        $progressBar = $this->output->createProgressBar(count($batches));
        $progressBar->start();

        foreach ($batches as $batch) {
            if ($this->qdrantService->delete($collection, $batch)) {
                $deletedCount += count($batch);
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("✅ {$deletedCount} points orphelins supprimés");

        // Afficher les nouvelles stats
        $newInfo = $this->qdrantService->getCollectionInfo($collection);
        $this->line('   Nouveau total: ' . ($newInfo['points_count'] ?? 0) . ' points');

        return Command::SUCCESS;
    }
}

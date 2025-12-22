<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AI\QdrantService;
use Illuminate\Console\Command;

class QdrantStatsCommand extends Command
{
    protected $signature = 'qdrant:stats
                            {collection? : Nom de la collection (toutes si non spécifié)}';

    protected $description = 'Affiche les statistiques des collections Qdrant';

    public function __construct(
        private QdrantService $qdrantService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $collectionName = $this->argument('collection');

        // Vérifier la connexion
        if (!$this->qdrantService->isHealthy()) {
            $this->error('❌ Qdrant n\'est pas accessible');
            return Command::FAILURE;
        }

        $this->info('📊 Statistiques Qdrant');
        $this->newLine();

        if ($collectionName) {
            $this->showCollectionStats($collectionName);
        } else {
            $this->showAllCollectionsStats();
        }

        return Command::SUCCESS;
    }

    private function showAllCollectionsStats(): void
    {
        $collections = config('qdrant.collections', []);

        if (empty($collections)) {
            $this->warn('Aucune collection configurée');
            return;
        }

        $rows = [];

        foreach (array_keys($collections) as $name) {
            if (!$this->qdrantService->collectionExists($name)) {
                $rows[] = [$name, '❌ Non créée', '-', '-'];
                continue;
            }

            $info = $this->qdrantService->getCollectionInfo($name);

            if ($info) {
                $rows[] = [
                    $name,
                    '✅ Active',
                    $info['points_count'] ?? 0,
                    $this->formatSize($info['segments_count'] ?? 0),
                ];
            } else {
                $rows[] = [$name, '⚠️ Erreur', '-', '-'];
            }
        }

        $this->table(
            ['Collection', 'Statut', 'Points', 'Segments'],
            $rows
        );
    }

    private function showCollectionStats(string $name): void
    {
        if (!$this->qdrantService->collectionExists($name)) {
            $this->error("La collection '{$name}' n'existe pas");
            return;
        }

        $info = $this->qdrantService->getCollectionInfo($name);

        if (!$info) {
            $this->error("Impossible de récupérer les infos de '{$name}'");
            return;
        }

        $this->info("Collection: {$name}");
        $this->newLine();

        $this->line("   Points:        " . ($info['points_count'] ?? 0));
        $this->line("   Segments:      " . ($info['segments_count'] ?? 0));
        $this->line("   Indexed:       " . ($info['indexed_vectors_count'] ?? 0));
        $this->line("   Statut:        " . ($info['status'] ?? 'unknown'));

        if (isset($info['config'])) {
            $config = $info['config'];

            $this->newLine();
            $this->info("Configuration:");

            if (isset($config['params']['vectors'])) {
                $vectors = $config['params']['vectors'];
                $this->line("   Dimension:     " . ($vectors['size'] ?? '-'));
                $this->line("   Distance:      " . ($vectors['distance'] ?? '-'));
            }

            if (isset($config['optimizer_config'])) {
                $optimizer = $config['optimizer_config'];
                $this->line("   Threshold:     " . ($optimizer['indexing_threshold'] ?? '-'));
            }
        }

        // Afficher un échantillon de payloads
        $this->newLine();
        $this->showSamplePayloads($name);
    }

    private function showSamplePayloads(string $collection): void
    {
        try {
            $sample = $this->qdrantService->scroll($collection, limit: 3);

            if (empty($sample)) {
                $this->line("   Aucun point dans la collection");
                return;
            }

            $this->info("Échantillon de données:");
            $this->newLine();

            foreach ($sample as $point) {
                $payload = $point['payload'] ?? [];
                $id = $point['id'] ?? 'unknown';

                $this->line("   📌 ID: {$id}");

                // Afficher les champs principaux du payload
                foreach (['content', 'title', 'code', 'name'] as $field) {
                    if (isset($payload[$field])) {
                        $value = strlen($payload[$field]) > 60
                            ? substr($payload[$field], 0, 60) . '...'
                            : $payload[$field];
                        $this->line("      {$field}: {$value}");
                    }
                }

                $this->newLine();
            }

        } catch (\Exception $e) {
            $this->warn("   Impossible de récupérer un échantillon: " . $e->getMessage());
        }
    }

    private function formatSize(int $segments): string
    {
        return (string) $segments;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Ouvrage;
use App\Services\AI\EmbeddingService;
use App\Services\AI\QdrantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IndexOuvragesCommand extends Command
{
    protected $signature = 'ouvrages:index
                            {--chunk=100 : Nombre d\'ouvrages par batch}
                            {--force : Réindexe même les ouvrages déjà indexés}
                            {--type= : Filtrer par type (compose, simple, etc.)}
                            {--collection=agent_btp_ouvrages : Collection Qdrant cible}';

    protected $description = 'Indexe les ouvrages BTP dans Qdrant pour la recherche sémantique';

    public function __construct(
        private EmbeddingService $embeddingService,
        private QdrantService $qdrantService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        $force = $this->option('force');
        $type = $this->option('type');
        $collection = $this->option('collection');

        $query = Ouvrage::query();

        if (!$force) {
            $query->where('is_indexed', false);
        }

        if ($type) {
            $query->where('type', $type);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('Aucun ouvrage à indexer.');
            return Command::SUCCESS;
        }

        $this->info("📦 Indexation de {$total} ouvrages...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $indexed = 0;
        $errors = 0;

        $query->chunkById($chunkSize, function ($ouvrages) use (&$indexed, &$errors, $bar, $collection) {
            $points = [];

            foreach ($ouvrages as $ouvrage) {
                try {
                    // Génération de la description textuelle
                    $description = $this->buildDescription($ouvrage);

                    // Génération de l'embedding
                    $embedding = $this->embeddingService->embed($description);

                    // Préparation du point Qdrant
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

                    // Mise à jour de l'ouvrage
                    $ouvrage->update([
                        'is_indexed' => true,
                        'indexed_at' => now(),
                        'qdrant_point_id' => $pointId,
                    ]);

                    $indexed++;

                } catch (\Exception $e) {
                    $this->newLine();
                    $this->error("Erreur pour ouvrage {$ouvrage->id}: " . $e->getMessage());
                    $errors++;
                }

                $bar->advance();
            }

            // Envoi en batch à Qdrant
            if (!empty($points)) {
                $this->qdrantService->upsert($collection, $points);
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Indexation terminée: {$indexed} succès, {$errors} erreurs");

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Construit une description textuelle sémantique de l'ouvrage
     */
    private function buildDescription(Ouvrage $ouvrage): string
    {
        $parts = [];

        // Nom et description de base
        $parts[] = "{$ouvrage->name}.";

        if ($ouvrage->description) {
            $parts[] = $ouvrage->description;
        }

        // Catégorie
        if ($ouvrage->category) {
            $categoryText = "Catégorie: {$ouvrage->category}";
            if ($ouvrage->subcategory) {
                $categoryText .= " / {$ouvrage->subcategory}";
            }
            $parts[] = $categoryText . ".";
        }

        // Unité et prix
        $parts[] = "Unité: {$ouvrage->unit}. Prix unitaire: " .
            number_format((float) $ouvrage->unit_price, 2, ',', ' ') . " €.";

        // Spécifications techniques
        if (!empty($ouvrage->technical_specs)) {
            $specs = collect($ouvrage->technical_specs)
                ->map(fn($v, $k) => ucfirst($k) . ": " . $v)
                ->join(', ');
            $parts[] = "Caractéristiques techniques: {$specs}.";
        }

        // Composants (pour ouvrages composés)
        if ($ouvrage->type === 'compose') {
            $components = $ouvrage->components()->with('component')->get();

            if ($components->isNotEmpty()) {
                $componentsList = $components->map(function ($oc) {
                    $comp = $oc->component;
                    return "{$oc->quantity} {$comp->unit} de {$comp->name}";
                })->join(', ');

                $parts[] = "Cet ouvrage composé inclut: {$componentsList}.";
            }
        }

        // Fournitures liées
        $fournitures = DB::table('fournitures')
            ->where('ouvrage_id', $ouvrage->id)
            ->get();

        if ($fournitures->isNotEmpty()) {
            $fList = $fournitures->pluck('name')->join(', ');
            $parts[] = "Fournitures nécessaires: {$fList}.";
        }

        return implode(' ', $parts);
    }
}

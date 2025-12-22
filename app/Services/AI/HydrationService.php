<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HydrationService
{
    /**
     * Hydrate les résultats RAG avec les données SQL
     *
     * @param array $ragResults Résultats de la recherche vectorielle
     * @param array $hydrationConfig Configuration d'hydratation de l'agent
     * @return array Résultats enrichis avec les données SQL
     */
    public function hydrate(array $ragResults, array $hydrationConfig): array
    {
        if (empty($hydrationConfig) || empty($ragResults)) {
            return $ragResults;
        }

        $table = $hydrationConfig['table'] ?? null;
        $key = $hydrationConfig['key'] ?? 'db_id';
        $fields = $hydrationConfig['fields'] ?? ['*'];
        $relations = $hydrationConfig['relations'] ?? [];

        if (!$table) {
            return $ragResults;
        }

        // Extraire les IDs des résultats RAG
        $ids = collect($ragResults)
            ->map(fn ($result) => $result['payload'][$key] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (empty($ids)) {
            return $ragResults;
        }

        // Récupérer les données SQL
        try {
            $query = DB::table($table)->whereIn('id', $ids);

            if ($fields !== ['*']) {
                $query->select(array_merge(['id'], $fields));
            }

            $sqlData = $query->get()->keyBy('id')->toArray();

            // Enrichir chaque résultat
            foreach ($ragResults as &$result) {
                $dbId = $result['payload'][$key] ?? null;

                if ($dbId && isset($sqlData[$dbId])) {
                    $result['hydrated_data'] = (array) $sqlData[$dbId];

                    // Charger les relations si demandées
                    if (!empty($relations)) {
                        $result['hydrated_data']['relations'] = $this->loadRelations(
                            $table,
                            $dbId,
                            $relations
                        );
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('Hydration failed', [
                'table' => $table,
                'error' => $e->getMessage()
            ]);
        }

        return $ragResults;
    }

    /**
     * Charge les relations d'un enregistrement
     */
    private function loadRelations(string $table, int $id, array $relations): array
    {
        $loadedRelations = [];

        foreach ($relations as $relation) {
            try {
                $loadedRelations[$relation] = $this->loadRelation($table, $id, $relation);
            } catch (\Exception $e) {
                Log::warning("Failed to load relation '$relation'", [
                    'table' => $table,
                    'id' => $id,
                    'error' => $e->getMessage()
                ]);
                $loadedRelations[$relation] = [];
            }
        }

        return $loadedRelations;
    }

    /**
     * Charge une relation spécifique
     */
    private function loadRelation(string $table, int $id, string $relation): array
    {
        // Pour la table ouvrages, gérer les relations spécifiques
        if ($table === 'ouvrages') {
            return match ($relation) {
                'children' => DB::table('ouvrages')
                    ->where('parent_id', $id)
                    ->select(['id', 'code', 'name', 'type', 'unit', 'unit_price'])
                    ->get()
                    ->toArray(),

                'components' => DB::table('ouvrage_components')
                    ->join('ouvrages', 'ouvrage_components.component_id', '=', 'ouvrages.id')
                    ->where('ouvrage_components.parent_id', $id)
                    ->select([
                        'ouvrages.id',
                        'ouvrages.code',
                        'ouvrages.name',
                        'ouvrages.type',
                        'ouvrages.unit',
                        'ouvrages.unit_price',
                        'ouvrage_components.quantity'
                    ])
                    ->orderBy('ouvrage_components.sort_order')
                    ->get()
                    ->toArray(),

                default => []
            };
        }

        return [];
    }

    /**
     * Formate les données hydratées pour inclusion dans le contexte
     */
    public function formatForContext(array $hydratedResult): string
    {
        $lines = [];

        // Données principales
        if (isset($hydratedResult['hydrated_data'])) {
            $data = $hydratedResult['hydrated_data'];

            if (isset($data['code'])) {
                $lines[] = "Code: {$data['code']}";
            }
            if (isset($data['name'])) {
                $lines[] = "Nom: {$data['name']}";
            }
            if (isset($data['description'])) {
                $lines[] = "Description: {$data['description']}";
            }
            if (isset($data['unit']) && isset($data['unit_price'])) {
                $lines[] = "Prix: {$data['unit_price']}€/{$data['unit']}";
            }

            // Spécifications techniques
            if (isset($data['technical_specs']) && is_array($data['technical_specs'])) {
                $specs = [];
                foreach ($data['technical_specs'] as $key => $value) {
                    $specs[] = "{$key}: {$value}";
                }
                if (!empty($specs)) {
                    $lines[] = "Caractéristiques: " . implode(', ', $specs);
                }
            }

            // Composants (pour ouvrages composés)
            if (isset($data['relations']['components']) && !empty($data['relations']['components'])) {
                $lines[] = "Composants:";
                foreach ($data['relations']['components'] as $component) {
                    $component = (array) $component;
                    $qty = $component['quantity'] ?? 1;
                    $lines[] = "  - {$component['name']} (x{$qty})";
                }
            }
        }

        return implode("\n", $lines);
    }
}

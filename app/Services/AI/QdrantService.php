<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\AI\Contracts\VectorStoreInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QdrantService implements VectorStoreInterface
{
    private string $baseUrl;
    private ?string $apiKey;
    private int $vectorSize;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = sprintf(
            'http://%s:%d',
            config('ai.qdrant.host', 'qdrant'),
            config('ai.qdrant.port', 6333)
        );
        $this->apiKey = config('ai.qdrant.api_key');
        $this->vectorSize = config('ai.qdrant.vector_size', 768);
        $this->timeout = config('ai.qdrant.timeout', 30);
    }

    /**
     * Vérifie et crée les collections nécessaires au démarrage
     */
    public function ensureCollectionsExist(array $collections): void
    {
        foreach ($collections as $name => $config) {
            if (!$this->collectionExists($name)) {
                $this->createCollection($name, $config);
                Log::info("Qdrant: Collection '$name' créée");
            }
        }
    }

    /**
     * Vérifie et crée une collection si elle n'existe pas
     */
    public function ensureCollectionExists(string $name, array $config = []): bool
    {
        if ($this->collectionExists($name)) {
            return true;
        }

        $success = $this->createCollection($name, $config);
        if ($success) {
            Log::info("Qdrant: Collection '$name' créée");
        }

        return $success;
    }

    public function collectionExists(string $name): bool
    {
        try {
            $response = $this->request()->get("/collections/{$name}");
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function createCollection(string $name, array $config = []): bool
    {
        $response = $this->request()->put("/collections/{$name}", [
            'vectors' => [
                'size' => $config['vector_size'] ?? $config['size'] ?? $this->vectorSize,
                'distance' => $config['distance'] ?? 'Cosine',
                'on_disk' => $config['on_disk'] ?? $config['on_disk_payload'] ?? false,
            ],
            'optimizers_config' => [
                'memmap_threshold' => 20000,
                'indexing_threshold' => 10000,
            ],
        ]);

        return $response->successful();
    }

    public function deleteCollection(string $name): bool
    {
        $response = $this->request()->delete("/collections/{$name}");
        return $response->successful();
    }

    /**
     * Recherche les vecteurs similaires
     */
    public function search(
        array $vector,
        string $collection,
        int $limit = 5,
        array $filter = [],
        float $scoreThreshold = 0.0
    ): array {
        $payload = [
            'vector' => $vector,
            'limit' => $limit,
            'with_payload' => true,
            'with_vectors' => false,
            'score_threshold' => $scoreThreshold,
        ];

        if (!empty($filter)) {
            $payload['filter'] = $filter;
        }

        $response = $this->request()->post("/collections/{$collection}/points/search", $payload);

        if (!$response->successful()) {
            Log::error('Qdrant search failed', [
                'collection' => $collection,
                'error' => $response->body()
            ]);
            return [];
        }

        return $response->json('result', []);
    }

    /**
     * Insère ou met à jour des points dans la collection
     */
    public function upsert(string $collection, array $points): bool
    {
        $formattedPoints = [];

        foreach ($points as $point) {
            $formattedPoints[] = [
                'id' => $point['id'],
                'vector' => $point['vector'],
                'payload' => $point['payload'] ?? [],
            ];
        }

        $response = $this->request()->put("/collections/{$collection}/points", [
            'points' => $formattedPoints,
        ]);

        if (!$response->successful()) {
            Log::error('Qdrant upsert failed', [
                'collection' => $collection,
                'error' => $response->body()
            ]);
            return false;
        }

        return true;
    }

    /**
     * Supprime des points de la collection
     */
    public function delete(string $collection, array $ids): bool
    {
        $response = $this->request()->post("/collections/{$collection}/points/delete", [
            'points' => $ids,
        ]);

        return $response->successful();
    }

    /**
     * Récupère un point par son ID
     */
    public function getPoint(string $collection, string|int $id): ?array
    {
        $response = $this->request()->get("/collections/{$collection}/points/{$id}");

        if (!$response->successful()) {
            return null;
        }

        return $response->json('result');
    }

    /**
     * Compte le nombre de points dans une collection
     */
    public function count(string $collection, array $filter = []): int
    {
        $payload = ['exact' => true];

        if (!empty($filter)) {
            $payload['filter'] = $filter;
        }

        $response = $this->request()->post("/collections/{$collection}/points/count", $payload);

        if (!$response->successful()) {
            return 0;
        }

        return $response->json('result.count', 0);
    }

    /**
     * Récupère les infos d'une collection
     */
    public function getCollectionInfo(string $collection): ?array
    {
        $response = $this->request()->get("/collections/{$collection}");

        if (!$response->successful()) {
            return null;
        }

        return $response->json('result');
    }

    /**
     * Liste toutes les collections
     */
    public function listCollections(): array
    {
        $response = $this->request()->get('/collections');

        if (!$response->successful()) {
            return [];
        }

        return collect($response->json('result.collections', []))
            ->pluck('name')
            ->toArray();
    }

    /**
     * Vérifie la disponibilité du service
     */
    public function isAvailable(): bool
    {
        try {
            $response = $this->request()->timeout(5)->get('/readyz');
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Alias pour isAvailable
     */
    public function isHealthy(): bool
    {
        return $this->isAvailable();
    }

    /**
     * Crée un index sur un champ du payload
     */
    public function createPayloadIndex(string $collection, string $field, string $type): bool
    {
        $schemaType = match ($type) {
            'integer', 'int' => ['type' => 'integer'],
            'float', 'double' => ['type' => 'float'],
            'keyword', 'string' => ['type' => 'keyword'],
            'bool', 'boolean' => ['type' => 'bool'],
            'geo' => ['type' => 'geo'],
            'text' => ['type' => 'text'],
            default => ['type' => 'keyword'],
        };

        $response = $this->request()->put(
            "/collections/{$collection}/index",
            [
                'field_name' => $field,
                'field_schema' => $schemaType,
            ]
        );

        return $response->successful();
    }

    /**
     * Scroll à travers tous les points d'une collection
     */
    public function scroll(
        string $collection,
        int $limit = 100,
        ?string $offset = null,
        array $filter = [],
        bool $withFullResult = false
    ): array {
        $payload = [
            'limit' => $limit,
            'with_payload' => true,
            'with_vectors' => false,
        ];

        if ($offset !== null) {
            $payload['offset'] = $offset;
        }

        if (!empty($filter)) {
            $payload['filter'] = $filter;
        }

        $response = $this->request()->post("/collections/{$collection}/points/scroll", $payload);

        if (!$response->successful()) {
            return $withFullResult
                ? ['points' => [], 'next_page_offset' => null]
                : [];
        }

        $result = $response->json('result', ['points' => [], 'next_page_offset' => null]);

        return $withFullResult ? $result : ($result['points'] ?? []);
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout($this->timeout)
            ->acceptJson()
            ->asJson()
            ->baseUrl($this->baseUrl);

        if ($this->apiKey) {
            $request->withHeader('api-key', $this->apiKey);
        }

        return $request;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}

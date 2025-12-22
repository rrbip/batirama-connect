<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

interface VectorStoreInterface
{
    /**
     * Recherche les vecteurs similaires
     */
    public function search(
        array $vector,
        string $collection,
        int $limit = 5,
        array $filter = [],
        float $scoreThreshold = 0.0
    ): array;

    /**
     * Insère un point dans la collection
     */
    public function upsert(string $collection, array $points): bool;

    /**
     * Supprime des points de la collection
     */
    public function delete(string $collection, array $ids): bool;

    /**
     * Vérifie si une collection existe
     */
    public function collectionExists(string $name): bool;

    /**
     * Crée une collection
     */
    public function createCollection(string $name, array $config = []): bool;
}

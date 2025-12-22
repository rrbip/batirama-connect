<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class EmbeddingService
{
    private string $baseUrl;
    private string $model;
    private bool $cacheEnabled;
    private int $cacheTtl;

    public function __construct()
    {
        $this->baseUrl = sprintf(
            'http://%s:%d',
            config('ai.ollama.host', 'ollama'),
            config('ai.ollama.port', 11434)
        );
        $this->model = config('ai.ollama.embedding_model', 'nomic-embed-text');
        $this->cacheEnabled = config('ai.embedding_cache.enabled', false);
        $this->cacheTtl = config('ai.embedding_cache.ttl', 3600);
    }

    /**
     * Génère l'embedding d'un texte
     *
     * @return float[] Vecteur de dimension 768
     */
    public function embed(string $text): array
    {
        // Normalisation du texte
        $text = $this->normalizeText($text);

        if (empty($text)) {
            throw new \InvalidArgumentException('Cannot generate embedding for empty text');
        }

        // Cache si activé
        if ($this->cacheEnabled) {
            $cacheKey = 'embedding:' . md5($text);
            return Cache::remember($cacheKey, $this->cacheTtl, fn () => $this->generateEmbedding($text));
        }

        return $this->generateEmbedding($text);
    }

    /**
     * Génère les embeddings de plusieurs textes (batch)
     *
     * @return array<string, float[]> Map texte => vecteur
     */
    public function embedBatch(array $texts): array
    {
        $results = [];

        foreach ($texts as $key => $text) {
            try {
                $results[$key] = $this->embed($text);
            } catch (\Exception $e) {
                // Log l'erreur mais continue avec les autres textes
                $results[$key] = [];
            }
        }

        return $results;
    }

    /**
     * Calcule la similarité cosinus entre deux vecteurs
     */
    public function cosineSimilarity(array $vectorA, array $vectorB): float
    {
        if (count($vectorA) !== count($vectorB)) {
            throw new \InvalidArgumentException('Vectors must have the same dimension');
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($vectorA); $i++) {
            $dotProduct += $vectorA[$i] * $vectorB[$i];
            $normA += $vectorA[$i] * $vectorA[$i];
            $normB += $vectorB[$i] * $vectorB[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA == 0 || $normB == 0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }

    private function generateEmbedding(string $text): array
    {
        $response = Http::timeout(30)
            ->post("{$this->baseUrl}/api/embeddings", [
                'model' => $this->model,
                'prompt' => $text,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Embedding generation failed: " . $response->body()
            );
        }

        $embedding = $response->json('embedding', []);

        if (empty($embedding)) {
            throw new \RuntimeException('Empty embedding returned from Ollama');
        }

        return $embedding;
    }

    private function normalizeText(string $text): string
    {
        // Supprime les espaces multiples
        $text = preg_replace('/\s+/', ' ', $text);

        // Trim
        $text = trim($text);

        // Limite la longueur (modèles ont une limite de tokens)
        if (strlen($text) > 8000) {
            $text = substr($text, 0, 8000);
        }

        return $text;
    }

    public function getModel(): string
    {
        return $this->model;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Agent;
use App\Models\DocumentCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service pour détecter la catégorie d'une question utilisateur
 * Permet de pré-filtrer les résultats RAG pour améliorer la pertinence
 */
class CategoryDetectionService
{
    private EmbeddingService $embeddingService;

    // Seuil de similarité minimum pour considérer une catégorie comme pertinente
    private const MIN_SIMILARITY_THRESHOLD = 0.45;

    // Score bonus pour les correspondances par mot-clé
    private const KEYWORD_MATCH_BONUS = 0.3;

    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
    }

    /**
     * Détecte la/les catégorie(s) pertinente(s) pour une question
     *
     * @return array{categories: Collection, confidence: float, method: string}
     */
    public function detect(string $question, ?Agent $agent = null): array
    {
        // Récupérer les catégories utilisées par l'agent
        $categories = $this->getAgentCategories($agent);

        if ($categories->isEmpty()) {
            return [
                'categories' => collect(),
                'confidence' => 0,
                'method' => 'none',
            ];
        }

        // 1. Essayer d'abord par correspondance de mots-clés (rapide)
        $keywordMatches = $this->detectByKeywords($question, $categories);

        if ($keywordMatches->isNotEmpty()) {
            Log::debug('Category detected by keywords', [
                'question' => Str::limit($question, 50),
                'categories' => $keywordMatches->pluck('name')->toArray(),
            ]);

            return [
                'categories' => $keywordMatches,
                'confidence' => 0.9,
                'method' => 'keyword',
            ];
        }

        // 2. Sinon, utiliser la similarité par embedding
        $embeddingMatches = $this->detectByEmbedding($question, $categories);

        if ($embeddingMatches->isNotEmpty()) {
            Log::debug('Category detected by embedding', [
                'question' => Str::limit($question, 50),
                'categories' => $embeddingMatches->map(fn($m) => [
                    'name' => $m['category']->name,
                    'score' => round($m['score'], 3),
                ])->toArray(),
            ]);

            return [
                'categories' => $embeddingMatches->pluck('category'),
                'confidence' => $embeddingMatches->first()['score'] ?? 0,
                'method' => 'embedding',
            ];
        }

        return [
            'categories' => collect(),
            'confidence' => 0,
            'method' => 'none',
        ];
    }

    /**
     * Détecte les catégories par correspondance de mots-clés
     */
    private function detectByKeywords(string $question, Collection $categories): Collection
    {
        $questionLower = Str::lower($question);
        $questionWords = preg_split('/\s+/', $questionLower);

        $matches = collect();

        foreach ($categories as $category) {
            $categoryName = Str::lower($category->name);
            $categorySlug = $category->slug;

            // Vérifier si le nom de la catégorie apparaît dans la question
            if (Str::contains($questionLower, $categoryName)) {
                $matches->push($category);
                continue;
            }

            // Vérifier si un mot de la question CONTIENT le nom de la catégorie (stemming basique)
            // Ex: "diagnostiquer" contient "diagnostic"
            foreach ($questionWords as $word) {
                if (strlen($categoryName) >= 4 && strlen($word) >= 4) {
                    // Le mot de la question contient la catégorie
                    if (Str::contains($word, $categoryName)) {
                        $matches->push($category);
                        break;
                    }
                    // Ou la catégorie contient le mot (racine commune)
                    if (strlen($word) >= 5 && Str::contains($categoryName, substr($word, 0, -2))) {
                        $matches->push($category);
                        break;
                    }
                }
            }

            if ($matches->contains('id', $category->id)) {
                continue;
            }

            // Vérifier les mots individuels de la catégorie
            $categoryWords = preg_split('/[\s\-_]+/', $categoryName);
            foreach ($categoryWords as $catWord) {
                if (strlen($catWord) < 4) {
                    continue;
                }

                foreach ($questionWords as $qWord) {
                    // Match exact
                    if ($catWord === $qWord) {
                        $matches->push($category);
                        break 2;
                    }
                    // Match par racine (le mot de la question commence par le mot catégorie ou inverse)
                    if (strlen($qWord) >= 4) {
                        $root = substr($catWord, 0, min(strlen($catWord), 6));
                        if (Str::startsWith($qWord, $root) || Str::startsWith($catWord, substr($qWord, 0, 6))) {
                            $matches->push($category);
                            break 2;
                        }
                    }
                }
            }
        }

        return $matches->unique('id');
    }

    /**
     * Détecte les catégories par similarité d'embedding
     */
    private function detectByEmbedding(string $question, Collection $categories): Collection
    {
        try {
            // Générer l'embedding de la question
            $questionVector = $this->embeddingService->embed($question);

            $scores = [];

            foreach ($categories as $category) {
                // Construire un texte représentatif de la catégorie
                $categoryText = $category->name;
                if ($category->description) {
                    $categoryText .= ': ' . $category->description;
                }

                // Générer l'embedding de la catégorie
                $categoryVector = $this->embeddingService->embed($categoryText);

                // Calculer la similarité cosinus
                $similarity = $this->cosineSimilarity($questionVector, $categoryVector);

                if ($similarity >= self::MIN_SIMILARITY_THRESHOLD) {
                    $scores[] = [
                        'category' => $category,
                        'score' => $similarity,
                    ];
                }
            }

            // Trier par score décroissant et prendre les meilleurs
            usort($scores, fn($a, $b) => $b['score'] <=> $a['score']);

            // Prendre uniquement les catégories avec un score proche du meilleur
            if (!empty($scores)) {
                $bestScore = $scores[0]['score'];
                $threshold = $bestScore * 0.85; // 15% de marge

                $scores = array_filter($scores, fn($s) => $s['score'] >= $threshold);
            }

            return collect(array_slice($scores, 0, 3));

        } catch (\Exception $e) {
            Log::warning('Category embedding detection failed', [
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Récupère les catégories utilisées par un agent
     */
    private function getAgentCategories(?Agent $agent): Collection
    {
        if (!$agent) {
            return DocumentCategory::where('usage_count', '>', 0)
                ->orderBy('usage_count', 'desc')
                ->get();
        }

        // Récupérer les catégories des chunks indexés pour cet agent
        return DocumentCategory::whereHas('chunks', function ($query) use ($agent) {
            $query->whereHas('document', function ($q) use ($agent) {
                $q->where('agent_id', $agent->id);
            });
        })
        ->where('usage_count', '>', 0)
        ->orderBy('usage_count', 'desc')
        ->get();
    }

    /**
     * Calcule la similarité cosinus entre deux vecteurs
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA == 0 || $normB == 0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }

    /**
     * Construit un filtre Qdrant pour les catégories détectées.
     * Utilise le champ 'category' du format Q/R Atomique.
     */
    public function buildQdrantFilter(Collection $categories): array
    {
        if ($categories->isEmpty()) {
            return [];
        }

        $categoryNames = $categories->pluck('name')->toArray();

        return [
            'should' => array_map(fn($name) => [
                'key' => 'category',
                'match' => ['value' => $name],
            ], $categoryNames),
        ];
    }
}

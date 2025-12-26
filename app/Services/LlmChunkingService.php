<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Agent;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentChunk;
use App\Models\LlmChunkingSetting;
use App\Services\AI\OllamaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LlmChunkingService
{
    private LlmChunkingSetting $settings;

    public function __construct()
    {
        $this->settings = LlmChunkingSetting::getInstance();
    }

    /**
     * Traite un document complet avec le chunking LLM
     */
    public function processDocument(Document $document): array
    {
        $text = $document->extracted_text;

        if (empty($text)) {
            throw new \RuntimeException('Le document n\'a pas de texte extrait');
        }

        Log::info('LLM Chunking started', [
            'document_id' => $document->id,
            'text_length' => mb_strlen($text),
        ]);

        // Créer les fenêtres de texte
        $windows = $this->createWindows($text);

        Log::info('Windows created', [
            'document_id' => $document->id,
            'window_count' => count($windows),
        ]);

        // Supprimer les anciens chunks
        $document->chunks()->delete();

        $allChunks = [];
        $chunkIndex = 0;
        $errors = [];

        foreach ($windows as $windowIndex => $window) {
            try {
                $result = $this->processWindow($window['text'], $document->agent);

                // Créer les nouvelles catégories suggérées
                foreach ($result['new_categories'] as $newCat) {
                    DocumentCategory::findOrCreateByName(
                        $newCat['name'],
                        $newCat['description'] ?? null,
                        true // is_ai_generated
                    );
                }

                // Créer les chunks
                foreach ($result['chunks'] as $chunkData) {
                    $category = null;
                    if (!empty($chunkData['category'])) {
                        $category = DocumentCategory::findOrCreateByName(
                            $chunkData['category'],
                            null,
                            true
                        );
                        $category->incrementUsage();
                    }

                    $chunk = DocumentChunk::create([
                        'document_id' => $document->id,
                        'chunk_index' => $chunkIndex++,
                        'start_offset' => $window['start_position'],
                        'end_offset' => $window['end_position'],
                        'content' => $chunkData['content'],
                        'original_content' => $window['text'],
                        'content_hash' => md5($chunkData['content']),
                        'token_count' => $this->estimateTokens($chunkData['content']),
                        'summary' => $chunkData['summary'] ?? null,
                        'keywords' => $chunkData['keywords'] ?? [],
                        'category_id' => $category?->id,
                        'metadata' => [
                            'strategy' => 'llm_assisted',
                            'document_title' => $document->title ?? $document->original_name,
                            'window_index' => $windowIndex,
                        ],
                        'is_indexed' => false,
                        'created_at' => now(),
                    ]);

                    $allChunks[] = $chunk;
                }

                Log::info('Window processed', [
                    'document_id' => $document->id,
                    'window_index' => $windowIndex,
                    'chunks_created' => count($result['chunks']),
                ]);

            } catch (\Exception $e) {
                Log::error('Window processing failed', [
                    'document_id' => $document->id,
                    'window_index' => $windowIndex,
                    'error' => $e->getMessage(),
                ]);

                $errors[] = [
                    'window_index' => $windowIndex,
                    'error' => $e->getMessage(),
                ];

                // Continuer avec les autres fenêtres en cas d'erreur
            }
        }

        // Mettre à jour le document
        $document->update([
            'chunk_count' => count($allChunks),
            'chunk_strategy' => 'llm_assisted',
        ]);

        Log::info('LLM Chunking completed', [
            'document_id' => $document->id,
            'total_chunks' => count($allChunks),
            'errors' => count($errors),
        ]);

        return [
            'chunks' => $allChunks,
            'chunk_count' => count($allChunks),
            'window_count' => count($windows),
            'errors' => $errors,
        ];
    }

    /**
     * Crée des fenêtres de texte avec overlap
     */
    public function createWindows(string $text): array
    {
        $windowSize = $this->settings->window_size;
        $overlapPercent = $this->settings->overlap_percent;
        $overlapTokens = (int) ($windowSize * $overlapPercent / 100);
        $step = $windowSize - $overlapTokens;

        // Tokenisation approximative (mots)
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $totalWords = count($words);

        $windows = [];
        $position = 0;

        while ($position < $totalWords) {
            $windowWords = array_slice($words, $position, $windowSize);
            $windowText = implode(' ', $windowWords);

            // Calculer les offsets en caractères
            $startOffset = $this->calculateCharOffset($words, $position);
            $endOffset = $this->calculateCharOffset($words, min($position + count($windowWords), $totalWords));

            $windows[] = [
                'text' => $windowText,
                'start_position' => $startOffset,
                'end_position' => $endOffset,
                'word_start' => $position,
                'word_end' => $position + count($windowWords),
            ];

            $position += $step;

            // Éviter les fenêtres trop petites à la fin
            if ($position + ($windowSize / 2) >= $totalWords && $position < $totalWords) {
                // La dernière fenêtre est déjà créée, on arrête
                break;
            }
        }

        return $windows;
    }

    /**
     * Traite une fenêtre de texte via Ollama
     */
    public function processWindow(string $windowText, ?Agent $agent = null): array
    {
        $model = $this->settings->getModelFor($agent);
        $prompt = $this->settings->buildPrompt($windowText);

        $response = Http::timeout($this->settings->timeout_seconds)
            ->post($this->settings->getOllamaUrl() . '/api/generate', [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
                'format' => 'json',
                'options' => [
                    'temperature' => $this->settings->temperature,
                    'num_predict' => 4096,
                ],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Ollama error: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['response'] ?? '';

        // Parser le JSON de la réponse
        return $this->parseResponse($content);
    }

    /**
     * Parse la réponse JSON de l'IA
     */
    private function parseResponse(string $response): array
    {
        // Nettoyer la réponse (enlever les markdown code blocks si présents)
        $response = trim($response);
        $response = preg_replace('/^```json?\s*/i', '', $response);
        $response = preg_replace('/\s*```$/i', '', $response);

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'JSON invalide retourné par l\'IA: ' . json_last_error_msg() .
                "\nRéponse brute: " . mb_substr($response, 0, 500)
            );
        }

        // Valider la structure
        if (!isset($data['chunks']) || !is_array($data['chunks'])) {
            throw new \RuntimeException(
                'Structure JSON invalide: le champ "chunks" est manquant ou invalide'
            );
        }

        // Normaliser les chunks
        $chunks = [];
        foreach ($data['chunks'] as $chunk) {
            if (!isset($chunk['content']) || empty(trim($chunk['content']))) {
                continue;
            }

            $chunks[] = [
                'content' => trim($chunk['content']),
                'keywords' => $chunk['keywords'] ?? [],
                'summary' => $chunk['summary'] ?? null,
                'category' => $chunk['category'] ?? null,
            ];
        }

        // Normaliser les nouvelles catégories
        $newCategories = [];
        if (isset($data['new_categories']) && is_array($data['new_categories'])) {
            foreach ($data['new_categories'] as $cat) {
                if (isset($cat['name']) && !empty(trim($cat['name']))) {
                    $newCategories[] = [
                        'name' => trim($cat['name']),
                        'description' => $cat['description'] ?? null,
                    ];
                }
            }
        }

        return [
            'chunks' => $chunks,
            'new_categories' => $newCategories,
        ];
    }

    /**
     * Calcule l'offset en caractères à partir d'un index de mot
     */
    private function calculateCharOffset(array $words, int $wordIndex): int
    {
        $offset = 0;
        for ($i = 0; $i < $wordIndex && $i < count($words); $i++) {
            $offset += mb_strlen($words[$i]) + 1; // +1 pour l'espace
        }
        return $offset;
    }

    /**
     * Estime le nombre de tokens (approximation)
     */
    private function estimateTokens(string $text): int
    {
        // Approximation: 1 token ≈ 4 caractères ou 0.75 mots
        $wordCount = str_word_count($text);
        return (int) ceil($wordCount * 1.3);
    }

    /**
     * Vérifie si le service est disponible
     */
    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get($this->settings->getOllamaUrl() . '/api/tags');
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Retourne les settings actuels
     */
    public function getSettings(): LlmChunkingSetting
    {
        return $this->settings;
    }
}

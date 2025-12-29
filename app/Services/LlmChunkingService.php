<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentDeployment;
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
    private ?DocumentChunkerService $fallbackChunker = null;

    /**
     * Override config from Agent or Deployment
     * @var array{host?: string, port?: int, model?: string}|null
     */
    private ?array $configOverride = null;

    public function __construct(?array $configOverride = null)
    {
        $this->settings = LlmChunkingSetting::getInstance();
        $this->configOverride = $configOverride;
    }

    /**
     * Create a service instance configured for a specific Agent
     */
    public static function forAgent(Agent $agent): self
    {
        $config = $agent->getChunkingConfig();

        // Only set override if different from global
        $globalSettings = LlmChunkingSetting::getInstance();
        $hasOverride = $config['host'] !== $globalSettings->ollama_host
            || $config['port'] !== $globalSettings->ollama_port
            || $config['model'] !== $globalSettings->model;

        return new self($hasOverride ? $config : null);
    }

    /**
     * Create a service instance configured for a specific Deployment
     */
    public static function forDeployment(AgentDeployment $deployment): self
    {
        $config = $deployment->getChunkingConfig();

        return new self($config);
    }

    /**
     * Get the effective Ollama URL (override > global)
     */
    private function getOllamaUrl(): string
    {
        if ($this->configOverride) {
            $host = $this->configOverride['host'] ?? $this->settings->ollama_host;
            $port = $this->configOverride['port'] ?? $this->settings->ollama_port;
            return "http://{$host}:{$port}";
        }

        return $this->settings->getOllamaUrl();
    }

    /**
     * Get the effective model (override > global)
     */
    private function getModel(?Agent $agent = null): string
    {
        if ($this->configOverride && !empty($this->configOverride['model'])) {
            return $this->configOverride['model'];
        }

        // Use settings method which handles Agent fallback
        return $this->settings->getModelFor($agent);
    }

    /**
     * Get the fallback chunker for when LLM fails
     */
    private function getFallbackChunker(): DocumentChunkerService
    {
        if ($this->fallbackChunker === null) {
            $this->fallbackChunker = app(DocumentChunkerService::class);
        }
        return $this->fallbackChunker;
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

        // Nettoyer le texte OCR avant traitement
        $originalLength = mb_strlen($text);
        $text = $this->cleanOcrText($text);

        Log::info('LLM Chunking started', [
            'document_id' => $document->id,
            'text_length' => mb_strlen($text),
            'original_length' => $originalLength,
            'contains_tables' => $this->containsTables($text),
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
        $llmResponses = [];

        foreach ($windows as $windowIndex => $window) {
            try {
                $rawResult = $this->processWindowWithRaw($window['text'], $document->agent);
                $result = $rawResult['parsed'];
                $llmResponses[] = [
                    'window_index' => $windowIndex,
                    'raw_response' => $rawResult['raw'],
                    'parsed_chunks' => count($result['chunks']),
                ];

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

        // Si toutes les fenêtres ont échoué ou aucun chunk n'a été créé, fallback vers chunking simple
        if (empty($allChunks) && count($errors) > 0) {
            Log::warning('LLM Chunking failed completely, falling back to simple chunking', [
                'document_id' => $document->id,
                'errors_count' => count($errors),
            ]);

            return $this->fallbackToSimpleChunking($document, $windows, $errors);
        }

        // Mettre à jour le document avec les métadonnées LLM
        $existingMetadata = $document->extraction_metadata ?? [];
        $document->update([
            'chunk_count' => count($allChunks),
            'chunk_strategy' => 'llm_assisted',
            'extraction_metadata' => array_merge($existingMetadata, [
                'llm_chunking' => [
                    'processed_at' => now()->toIso8601String(),
                    'window_count' => count($windows),
                    'model' => $this->getModel($document->agent),
                    'responses' => $llmResponses,
                ],
            ]),
        ]);

        // Note: On ne fusionne plus automatiquement les chunks car cela fait perdre les summaries
        // La fusion reste disponible manuellement via ManageChunks si nécessaire

        Log::info('LLM Chunking completed', [
            'document_id' => $document->id,
            'total_chunks' => count($allChunks),
            'errors' => count($errors),
        ]);

        return [
            'chunks' => $document->chunks()->orderBy('chunk_index')->get()->all(),
            'chunk_count' => $document->fresh()->chunk_count,
            'window_count' => count($windows),
            'errors' => $errors,
        ];
    }

    /**
     * Enrichit des chunks existants (issus du chunking markdown) avec le LLM
     *
     * Ajoute : catégorie, keywords, summary
     * Optionnellement : corrige le formatage markdown
     *
     * @param Document $document Le document dont les chunks sont à enrichir
     * @param int $batchSize Nombre de chunks à traiter par appel LLM (défaut: 10)
     * @return array Résultat avec chunks enrichis et stats
     */
    public function enrichMarkdownChunks(Document $document, int $batchSize = 10): array
    {
        $chunks = $document->chunks()->orderBy('chunk_index')->get();

        if ($chunks->isEmpty()) {
            throw new \RuntimeException('Le document n\'a pas de chunks à enrichir');
        }

        Log::info('LLM Enrichment started', [
            'document_id' => $document->id,
            'chunk_count' => $chunks->count(),
            'batch_size' => $batchSize,
        ]);

        $enrichedCount = 0;
        $errors = [];
        $llmResponses = [];
        $newCategoriesCreated = [];

        // Traiter les chunks par batch pour éviter les prompts trop longs
        $batches = $chunks->chunk($batchSize);

        foreach ($batches as $batchIndex => $batch) {
            try {
                // Préparer le JSON des chunks pour le LLM
                $chunksJson = $batch->map(function ($chunk) {
                    $metadata = $chunk->metadata ?? [];
                    return [
                        'chunk_index' => $chunk->chunk_index,
                        'content' => $chunk->content,
                        'header_title' => $metadata['header_title'] ?? null,
                        'header_level' => $metadata['header_level'] ?? null,
                        'section_type' => $metadata['section_type'] ?? null,
                    ];
                })->values()->toArray();

                // Appeler le LLM pour enrichir
                $result = $this->callLlmForEnrichment($chunksJson, $document->agent);

                $llmResponses[] = [
                    'batch_index' => $batchIndex,
                    'chunks_sent' => count($chunksJson),
                    'chunks_received' => count($result['chunks']),
                ];

                // Créer les nouvelles catégories suggérées
                foreach ($result['new_categories'] as $newCat) {
                    $category = DocumentCategory::findOrCreateByName(
                        $newCat['name'],
                        $newCat['description'] ?? null,
                        true // is_ai_generated
                    );
                    $newCategoriesCreated[] = $category->name;
                }

                // Mettre à jour les chunks avec les enrichissements
                foreach ($result['chunks'] as $enrichedData) {
                    $chunkIndex = $enrichedData['chunk_index'] ?? null;
                    if ($chunkIndex === null) {
                        continue;
                    }

                    $chunk = $batch->firstWhere('chunk_index', $chunkIndex);
                    if (!$chunk) {
                        Log::warning('Chunk not found for enrichment', [
                            'document_id' => $document->id,
                            'chunk_index' => $chunkIndex,
                        ]);
                        continue;
                    }

                    // Trouver ou créer la catégorie
                    $category = null;
                    if (!empty($enrichedData['category'])) {
                        $category = DocumentCategory::findOrCreateByName(
                            $enrichedData['category'],
                            null,
                            true
                        );
                        $category->incrementUsage();
                    }

                    // Préparer les données à mettre à jour
                    $updateData = [
                        'keywords' => $enrichedData['keywords'] ?? [],
                        'summary' => $enrichedData['summary'] ?? null,
                        'category_id' => $category?->id,
                        'is_indexed' => false, // Re-indexation nécessaire
                        'indexed_at' => null,
                    ];

                    // Si le contenu a été corrigé, le mettre à jour
                    if (!empty($enrichedData['content']) && $enrichedData['content'] !== $chunk->content) {
                        $updateData['original_content'] = $chunk->content;
                        $updateData['content'] = $enrichedData['content'];
                        $updateData['content_hash'] = md5($enrichedData['content']);
                        $updateData['token_count'] = $this->estimateTokens($enrichedData['content']);
                    }

                    // Mettre à jour les métadonnées
                    $metadata = $chunk->metadata ?? [];
                    $metadata['enriched_at'] = now()->toIso8601String();
                    $metadata['enriched_by_llm'] = true;
                    if ($category) {
                        $metadata['chunk_category'] = $category->name;
                    }
                    $updateData['metadata'] = $metadata;

                    $chunk->update($updateData);
                    $enrichedCount++;
                }

                Log::info('Batch enrichment completed', [
                    'document_id' => $document->id,
                    'batch_index' => $batchIndex,
                    'enriched_count' => count($result['chunks']),
                ]);

            } catch (\Exception $e) {
                Log::error('Batch enrichment failed', [
                    'document_id' => $document->id,
                    'batch_index' => $batchIndex,
                    'error' => $e->getMessage(),
                ]);

                $errors[] = [
                    'batch_index' => $batchIndex,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Mettre à jour les métadonnées du document
        $existingMetadata = $document->extraction_metadata ?? [];
        $document->update([
            'extraction_metadata' => array_merge($existingMetadata, [
                'llm_enrichment' => [
                    'enriched_at' => now()->toIso8601String(),
                    'model' => $this->getModel($document->agent),
                    'chunks_enriched' => $enrichedCount,
                    'batches_processed' => count($batches),
                    'new_categories' => array_unique($newCategoriesCreated),
                    'errors_count' => count($errors),
                ],
            ]),
        ]);

        Log::info('LLM Enrichment completed', [
            'document_id' => $document->id,
            'enriched_count' => $enrichedCount,
            'errors' => count($errors),
        ]);

        return [
            'enriched_count' => $enrichedCount,
            'total_chunks' => $chunks->count(),
            'batches_processed' => count($batches),
            'new_categories' => array_unique($newCategoriesCreated),
            'errors' => $errors,
        ];
    }

    /**
     * Appelle le LLM pour enrichir un batch de chunks
     */
    private function callLlmForEnrichment(array $chunksJson, ?Agent $agent = null): array
    {
        $model = $this->getModel($agent);
        $prompt = $this->settings->buildEnrichmentPrompt($chunksJson);

        $timeout = $this->settings->timeout_seconds;

        $request = Http::withOptions([
            'timeout' => $timeout > 0 ? $timeout : 0,
            'connect_timeout' => 30,
        ]);

        $response = $request->post($this->getOllamaUrl() . '/api/generate', [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'format' => 'json',
            'options' => [
                'temperature' => $this->settings->temperature,
                'num_predict' => 8192,
            ],
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Ollama error: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['response'] ?? '';

        return $this->parseEnrichmentResponse($content);
    }

    /**
     * Parse la réponse JSON d'enrichissement
     */
    private function parseEnrichmentResponse(string $response): array
    {
        // Nettoyer la réponse
        $response = trim($response);
        $response = preg_replace('/^```json?\s*/i', '', $response);
        $response = preg_replace('/\s*```$/i', '', $response);

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'JSON invalide retourné par le LLM: ' . json_last_error_msg() .
                "\nRéponse brute: " . mb_substr($response, 0, 500)
            );
        }

        if (!isset($data['chunks']) || !is_array($data['chunks'])) {
            throw new \RuntimeException(
                'Structure JSON invalide: le champ "chunks" est manquant'
            );
        }

        // Normaliser les chunks enrichis
        $chunks = [];
        foreach ($data['chunks'] as $chunk) {
            $chunks[] = [
                'chunk_index' => $chunk['chunk_index'] ?? null,
                'content' => $chunk['content'] ?? null,
                'keywords' => $chunk['keywords'] ?? $chunk['tags'] ?? [],
                'summary' => $chunk['summary'] ?? $chunk['resume'] ?? null,
                'category' => $chunk['category'] ?? $chunk['categorie'] ?? null,
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
     * Fallback vers le chunking simple quand LLM échoue
     */
    private function fallbackToSimpleChunking(Document $document, array $windows, array $errors): array
    {
        // Utiliser le DocumentChunkerService avec stratégie 'recursive' (la plus intelligente)
        $document->chunk_strategy = 'recursive';
        $chunks = $this->getFallbackChunker()->chunk($document);

        // Mettre à jour les métadonnées pour indiquer le fallback
        $existingMetadata = $document->extraction_metadata ?? [];
        $document->update([
            'chunk_strategy' => 'simple_fallback',
            'extraction_metadata' => array_merge($existingMetadata, [
                'llm_chunking' => [
                    'processed_at' => now()->toIso8601String(),
                    'window_count' => count($windows),
                    'fallback_reason' => 'LLM processing failed',
                    'original_errors' => array_slice($errors, 0, 5), // Garder les 5 premières erreurs
                ],
            ]),
        ]);

        Log::info('Fallback chunking completed', [
            'document_id' => $document->id,
            'chunks_created' => count($chunks),
        ]);

        return [
            'chunks' => $chunks,
            'chunk_count' => count($chunks),
            'window_count' => count($windows),
            'errors' => $errors,
            'fallback_used' => true,
        ];
    }

    /**
     * Fusionne les chunks consécutifs qui ont la même catégorie
     * Améliore le contexte RAG en évitant la fragmentation
     */
    public function mergeConsecutiveChunks(Document $document): int
    {
        $chunks = $document->chunks()->orderBy('chunk_index')->get();

        if ($chunks->count() < 2) {
            return 0;
        }

        $mergedCount = 0;
        $chunksToDelete = [];
        $previousChunk = null;

        foreach ($chunks as $chunk) {
            // Si c'est le premier chunk ou si la catégorie est différente
            if ($previousChunk === null ||
                $previousChunk->category_id !== $chunk->category_id ||
                $chunk->category_id === null) {
                $previousChunk = $chunk;
                continue;
            }

            // Même catégorie que le précédent - fusionner
            $newContent = $previousChunk->content . "\n\n" . $chunk->content;

            // Fusionner les keywords s'ils existent
            $mergedKeywords = array_unique(array_merge(
                $previousChunk->keywords ?? [],
                $chunk->keywords ?? []
            ));

            // Mettre à jour le chunk précédent avec le contenu fusionné
            $previousChunk->update([
                'content' => $newContent,
                'content_hash' => md5($newContent),
                'token_count' => $this->estimateTokens($newContent),
                'keywords' => array_values($mergedKeywords),
                'end_offset' => $chunk->end_offset,
                'is_indexed' => false,
                'indexed_at' => null,
            ]);

            // Marquer ce chunk pour suppression
            $chunksToDelete[] = $chunk->id;
            $mergedCount++;

            Log::debug('Chunks merged', [
                'document_id' => $document->id,
                'kept_chunk' => $previousChunk->chunk_index,
                'deleted_chunk' => $chunk->chunk_index,
                'category' => $previousChunk->category?->name,
            ]);
        }

        // Supprimer les chunks fusionnés
        if (!empty($chunksToDelete)) {
            DocumentChunk::whereIn('id', $chunksToDelete)->delete();

            // Renuméroter les chunks restants
            $this->renumberChunks($document);

            // Mettre à jour le compteur de chunks
            $document->update([
                'chunk_count' => $document->chunks()->count(),
            ]);
        }

        return $mergedCount;
    }

    /**
     * Renumérote les chunks séquentiellement après fusion
     */
    private function renumberChunks(Document $document): void
    {
        $chunks = $document->chunks()->orderBy('chunk_index')->get();

        foreach ($chunks as $index => $chunk) {
            if ($chunk->chunk_index !== $index) {
                $chunk->update(['chunk_index' => $index]);
            }
        }
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
        return $this->processWindowWithRaw($windowText, $agent)['parsed'];
    }

    /**
     * Traite une fenêtre de texte via Ollama et retourne aussi la réponse brute
     */
    public function processWindowWithRaw(string $windowText, ?Agent $agent = null): array
    {
        $model = $this->getModel($agent);
        $prompt = $this->settings->buildPrompt($windowText);

        // Timeout: 0 = infini, sinon utiliser la valeur configurée
        $timeout = $this->settings->timeout_seconds;

        $request = Http::withOptions([
            'timeout' => $timeout > 0 ? $timeout : 0,
            'connect_timeout' => 30, // 30s pour la connexion uniquement
        ]);

        $response = $request->post($this->getOllamaUrl() . '/api/generate', [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'format' => 'json',
            'options' => [
                'temperature' => $this->settings->temperature,
                'num_predict' => 8192, // Increased to avoid truncation on long documents
            ],
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Ollama error: ' . $response->body());
        }

        $data = $response->json();
        $content = $data['response'] ?? '';

        // Parser le JSON de la réponse
        return [
            'raw' => $content,
            'parsed' => $this->parseResponse($content),
        ];
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
                // Accepter 'keywords' ou 'tags' (mistral utilise parfois 'tags')
                'keywords' => $chunk['keywords'] ?? $chunk['tags'] ?? [],
                'summary' => $chunk['summary'] ?? $chunk['resume'] ?? null,
                'category' => $chunk['category'] ?? $chunk['categorie'] ?? null,
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
     * Nettoie le texte OCR de ses artefacts courants
     * - Caractères parasites (ligatures mal décodées, symboles incorrects)
     * - Espaces multiples et sauts de ligne excessifs
     * - Caractères de contrôle
     */
    public function cleanOcrText(string $text): string
    {
        // Remplacer les ligatures typographiques par leurs équivalents simples
        // Note: œ et æ sont conservés car ce sont des caractères valides en français
        $ligatures = [
            'ﬁ' => 'fi',
            'ﬂ' => 'fl',
            'ﬀ' => 'ff',
            'ﬃ' => 'ffi',
            'ﬄ' => 'ffl',
            'ﬅ' => 'st',
            'ﬆ' => 'st',
        ];
        $text = strtr($text, $ligatures);

        // Nettoyer les artefacts OCR courants (caractères parasites)
        // Pattern: chiffre suivi de symboles non pertinents (ex: "1%", "2è°°")
        $text = preg_replace('/(\d)[%°]{2,}/', '$1', $text);
        $text = preg_replace('/(\d)[è°ê]{1,3}/', '$1', $text);

        // Supprimer les caractères de contrôle (sauf newlines et tabs)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Normaliser les espaces (remplacer multiples par un seul)
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Normaliser les sauts de ligne (max 2 consécutifs)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Nettoyer les lignes qui ne contiennent que des espaces
        $text = preg_replace('/\n +\n/', "\n\n", $text);

        // Supprimer les espaces en fin de ligne
        $text = preg_replace('/ +\n/', "\n", $text);

        // Supprimer les tirets de césure en fin de ligne (reconstituer les mots)
        $text = preg_replace('/(\w)-\n(\w)/', '$1$2', $text);

        return trim($text);
    }

    /**
     * Détecte si le texte contient des tableaux (patterns de colonnes alignées)
     */
    public function containsTables(string $text): bool
    {
        // Détecter les patterns de tableaux :
        // - Lignes avec | comme séparateurs
        // - Lignes avec au moins 2 colonnes de chiffres alignés
        // - Lignes avec tabulations multiples

        // Pattern 1: Tableaux avec pipes
        if (preg_match('/\|[^|]+\|[^|]+\|/', $text)) {
            return true;
        }

        // Pattern 2: Multiples tabulations
        if (preg_match('/\t.*\t.*\t/', $text)) {
            return true;
        }

        // Pattern 3: Colonnes de chiffres (au moins 3 nombres sur la même ligne)
        if (preg_match('/\d+[\s,]+\d+[\s,]+\d+/', $text)) {
            return true;
        }

        // Pattern 4: Lignes avec structure répétitive (ex: "Produit   100   50   25")
        $lines = explode("\n", $text);
        $structuredLines = 0;
        foreach ($lines as $line) {
            // Ligne avec au moins 2 groupes d'espaces multiples séparant des valeurs
            if (preg_match('/\S+\s{2,}\S+\s{2,}\S+/', $line)) {
                $structuredLines++;
            }
        }
        // Si plus de 3 lignes structurées, c'est probablement un tableau
        return $structuredLines >= 3;
    }

    /**
     * Vérifie si le service est disponible
     */
    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get($this->getOllamaUrl() . '/api/tags');
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Retourne les informations de diagnostic
     */
    public function getDiagnostics(): array
    {
        $ollamaCheck = $this->checkOllamaConnection();

        return [
            'ollama' => $ollamaCheck,
            'model' => $this->getModel(),
            'has_override' => $this->configOverride !== null,
            'settings' => [
                'window_size' => $this->settings->window_size,
                'overlap_percent' => $this->settings->overlap_percent,
                'temperature' => $this->settings->temperature,
                'timeout_seconds' => $this->settings->timeout_seconds,
            ],
        ];
    }

    /**
     * Vérifie la connexion Ollama (avec override si configuré)
     */
    private function checkOllamaConnection(): array
    {
        try {
            $url = $this->getOllamaUrl();
            $model = $this->getModel();

            $response = Http::timeout(5)->get($url . '/api/tags');

            if ($response->successful()) {
                $models = collect($response->json('models', []))
                    ->pluck('name')
                    ->toArray();

                $hasConfiguredModel = in_array($model, $models) ||
                    in_array($model . ':latest', $models);

                return [
                    'connected' => true,
                    'url' => $url,
                    'models_available' => $models,
                    'configured_model_installed' => $hasConfiguredModel,
                ];
            }

            return [
                'connected' => false,
                'url' => $url,
                'error' => 'Ollama responded with status ' . $response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'url' => $this->getOllamaUrl(),
                'error' => $e->getMessage(),
            ];
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

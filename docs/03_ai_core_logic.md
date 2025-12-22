# Logique IA, RAG et Apprentissage Continu

> **Référence** : [00_index.md](./00_index.md)
> **Statut** : Spécifications validées

---

## Vue d'Ensemble

Le système IA repose sur trois composants principaux :

1. **Dispatcher** : Routage des requêtes vers l'agent approprié
2. **Moteur RAG** : Récupération et enrichissement du contexte
3. **Boucle d'Apprentissage** : Amélioration continue via feedback humain

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           FLUX DE TRAITEMENT IA                              │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────┐     ┌──────────────┐     ┌──────────────┐     ┌──────────┐   │
│  │ Question │────▶│  Dispatcher  │────▶│  RAG Engine  │────▶│  Ollama  │   │
│  │Utilisateur│     │              │     │              │     │          │   │
│  └──────────┘     └──────────────┘     └──────────────┘     └────┬─────┘   │
│                          │                    │                   │         │
│                          ▼                    ▼                   ▼         │
│                   ┌──────────────┐     ┌──────────────┐    ┌──────────┐    │
│                   │ Agent Config │     │   Qdrant     │    │ Réponse  │    │
│                   │  (Postgres)  │     │  + Postgres  │    │   IA     │    │
│                   └──────────────┘     └──────────────┘    └────┬─────┘    │
│                                                                  │          │
│                                              ┌───────────────────┘          │
│                                              ▼                              │
│                                       ┌──────────────┐                      │
│                                       │   Feedback   │                      │
│                                       │    Humain    │                      │
│                                       └──────┬───────┘                      │
│                                              │                              │
│                                              ▼                              │
│                                       ┌──────────────┐                      │
│                                       │Apprentissage │                      │
│                                       │  (Qdrant)    │                      │
│                                       └──────────────┘                      │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Architecture des Services

### Structure des Classes

```
app/Services/AI/
├── Contracts/
│   ├── EmbeddingServiceInterface.php
│   ├── LLMServiceInterface.php
│   ├── VectorStoreInterface.php
│   └── RagServiceInterface.php
├── OllamaService.php          # Client HTTP pour Ollama
├── QdrantService.php          # Client HTTP pour Qdrant
├── EmbeddingService.php       # Génération d'embeddings
├── PromptBuilder.php          # Construction des prompts
├── RagService.php             # Orchestration RAG complète
├── DispatcherService.php      # Routage vers les agents
├── LearningService.php        # Boucle d'apprentissage
└── HydrationService.php       # Enrichissement SQL
```

---

## 1. Service Ollama

### Interface

```php
<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

interface LLMServiceInterface
{
    /**
     * Génère une réponse à partir d'un prompt
     */
    public function generate(string $prompt, array $options = []): LLMResponse;

    /**
     * Génère une réponse en streaming
     */
    public function generateStream(string $prompt, array $options = []): \Generator;

    /**
     * Vérifie la disponibilité du service
     */
    public function isAvailable(): bool;

    /**
     * Liste les modèles disponibles
     */
    public function listModels(): array;
}
```

### Implémentation

```php
<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\AI\Contracts\LLMServiceInterface;
use App\DTOs\AI\LLMResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;

class OllamaService implements LLMServiceInterface
{
    private string $baseUrl;
    private string $defaultModel;
    private int $timeout;

    public function __construct(
        ?string $host = null,
        ?int $port = null,
        ?string $model = null
    ) {
        $this->baseUrl = sprintf(
            'http://%s:%d',
            $host ?? config('ai.ollama.host', 'ollama'),
            $port ?? config('ai.ollama.port', 11434)
        );
        $this->defaultModel = $model ?? config('ai.ollama.default_model', 'mistral:7b');
        $this->timeout = config('ai.ollama.timeout', 120);
    }

    /**
     * Crée une instance avec configuration personnalisée (pour agents spécifiques)
     */
    public static function forAgent(Agent $agent): self
    {
        return new self(
            host: $agent->ollama_host,
            port: $agent->ollama_port,
            model: $agent->model
        );
    }

    public function generate(string $prompt, array $options = []): LLMResponse
    {
        $startTime = microtime(true);

        $model = $options['model'] ?? $this->defaultModel;

        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/api/generate", [
                    'model' => $model,
                    'prompt' => $prompt,
                    'stream' => false,
                    'options' => [
                        'temperature' => $options['temperature'] ?? 0.7,
                        'num_predict' => $options['max_tokens'] ?? 2048,
                        'top_p' => $options['top_p'] ?? 0.9,
                        'stop' => $options['stop'] ?? [],
                    ],
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException(
                    "Ollama error: " . $response->body()
                );
            }

            $data = $response->json();
            $generationTime = (int) ((microtime(true) - $startTime) * 1000);

            return new LLMResponse(
                content: $data['response'] ?? '',
                model: $model,
                tokensPrompt: $data['prompt_eval_count'] ?? null,
                tokensCompletion: $data['eval_count'] ?? null,
                generationTimeMs: $generationTime,
                raw: $data
            );

        } catch (ConnectionException $e) {
            Log::error('Ollama connection failed', [
                'url' => $this->baseUrl,
                'error' => $e->getMessage()
            ]);

            // Tentative avec le modèle fallback si configuré
            if (isset($options['fallback_model']) && $options['fallback_model'] !== $model) {
                Log::info('Trying fallback model', ['model' => $options['fallback_model']]);
                return $this->generate($prompt, [
                    ...$options,
                    'model' => $options['fallback_model'],
                    'fallback_model' => null // Évite la récursion infinie
                ]);
            }

            throw $e;
        }
    }

    public function generateStream(string $prompt, array $options = []): \Generator
    {
        $model = $options['model'] ?? $this->defaultModel;

        $response = Http::timeout($this->timeout)
            ->withOptions(['stream' => true])
            ->post("{$this->baseUrl}/api/generate", [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => true,
                'options' => [
                    'temperature' => $options['temperature'] ?? 0.7,
                    'num_predict' => $options['max_tokens'] ?? 2048,
                ],
            ]);

        $body = $response->getBody();

        while (!$body->eof()) {
            $line = $body->read(1024);
            if (empty(trim($line))) continue;

            $data = json_decode($line, true);
            if (isset($data['response'])) {
                yield $data['response'];
            }

            if ($data['done'] ?? false) {
                break;
            }
        }
    }

    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/api/tags");
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function listModels(): array
    {
        $response = Http::timeout(10)->get("{$this->baseUrl}/api/tags");

        if (!$response->successful()) {
            return [];
        }

        return collect($response->json('models', []))
            ->pluck('name')
            ->toArray();
    }
}
```

### DTO de Réponse

```php
<?php

declare(strict_types=1);

namespace App\DTOs\AI;

readonly class LLMResponse
{
    public function __construct(
        public string $content,
        public string $model,
        public ?int $tokensPrompt = null,
        public ?int $tokensCompletion = null,
        public ?int $generationTimeMs = null,
        public array $raw = []
    ) {}

    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'model' => $this->model,
            'tokens_prompt' => $this->tokensPrompt,
            'tokens_completion' => $this->tokensCompletion,
            'generation_time_ms' => $this->generationTimeMs,
        ];
    }
}
```

---

## 2. Service Embeddings

### Implémentation

```php
<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

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

        // Cache si activé
        if ($this->cacheEnabled) {
            $cacheKey = 'embedding:' . md5($text);
            return Cache::remember($cacheKey, $this->cacheTtl, fn() => $this->generateEmbedding($text));
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
            $results[$key] = $this->embed($text);
        }

        return $results;
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

        return $response->json('embedding', []);
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
}
```

---

## 3. Service Qdrant

### Implémentation

```php
<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QdrantService
{
    private string $baseUrl;
    private ?string $apiKey;
    private int $vectorSize;

    public function __construct()
    {
        $this->baseUrl = sprintf(
            'http://%s:%d',
            config('qdrant.host', 'qdrant'),
            config('qdrant.port', 6333)
        );
        $this->apiKey = config('qdrant.api_key');
        $this->vectorSize = config('qdrant.vector_size', 768);
    }

    /**
     * Vérifie et crée les collections nécessaires au démarrage
     */
    public function ensureCollectionsExist(): void
    {
        $collections = config('qdrant.collections', []);

        foreach ($collections as $name => $config) {
            if (!$this->collectionExists($name)) {
                $this->createCollection($name, $config);
                Log::info("Qdrant: Collection '$name' créée");
            }
        }
    }

    public function collectionExists(string $name): bool
    {
        $response = $this->request('GET', "/collections/{$name}");
        return $response->successful();
    }

    public function createCollection(string $name, array $config = []): bool
    {
        $response = $this->request('PUT', "/collections/{$name}", [
            'vectors' => [
                'size' => $config['size'] ?? $this->vectorSize,
                'distance' => $config['distance'] ?? 'Cosine',
                'on_disk' => $config['on_disk'] ?? false,
            ],
            'optimizers_config' => [
                'memmap_threshold' => 20000,
                'indexing_threshold' => 10000,
            ],
        ]);

        return $response->successful();
    }

    /**
     * Recherche les vecteurs similaires
     *
     * @param float[] $vector Vecteur de requête
     * @param string $collection Nom de la collection
     * @param int $limit Nombre de résultats
     * @param array $filter Filtre Qdrant optionnel
     * @return array Points trouvés avec scores
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

        $response = $this->request(
            'POST',
            "/collections/{$collection}/points/search",
            $payload
        );

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
     * Recherche dans la collection learned_responses
     * avec filtre sur l'agent
     */
    public function searchLearnedResponses(
        array $vector,
        int $agentId,
        int $limit = 3
    ): array {
        return $this->search(
            vector: $vector,
            collection: 'learned_responses',
            limit: $limit,
            filter: [
                'must' => [
                    ['key' => 'agent_id', 'match' => ['value' => $agentId]]
                ]
            ],
            scoreThreshold: 0.85 // Seuil élevé pour les réponses apprises
        );
    }

    /**
     * Insère ou met à jour un point
     */
    public function upsert(
        string $collection,
        string $id,
        array $vector,
        array $payload = []
    ): bool {
        $response = $this->request(
            'PUT',
            "/collections/{$collection}/points",
            [
                'points' => [
                    [
                        'id' => $id,
                        'vector' => $vector,
                        'payload' => $payload,
                    ]
                ]
            ]
        );

        return $response->successful();
    }

    /**
     * Insère plusieurs points en batch
     */
    public function upsertBatch(string $collection, array $points): bool
    {
        $response = $this->request(
            'PUT',
            "/collections/{$collection}/points",
            ['points' => $points]
        );

        return $response->successful();
    }

    /**
     * Supprime un point
     */
    public function delete(string $collection, string $id): bool
    {
        $response = $this->request(
            'POST',
            "/collections/{$collection}/points/delete",
            ['points' => [$id]]
        );

        return $response->successful();
    }

    /**
     * Obtient les statistiques d'une collection
     */
    public function getCollectionInfo(string $collection): ?array
    {
        $response = $this->request('GET', "/collections/{$collection}");

        if (!$response->successful()) {
            return null;
        }

        return $response->json('result');
    }

    private function request(string $method, string $path, array $data = [])
    {
        $request = Http::timeout(30);

        if ($this->apiKey) {
            $request->withHeaders(['api-key' => $this->apiKey]);
        }

        return match ($method) {
            'GET' => $request->get("{$this->baseUrl}{$path}"),
            'POST' => $request->post("{$this->baseUrl}{$path}", $data),
            'PUT' => $request->put("{$this->baseUrl}{$path}", $data),
            'DELETE' => $request->delete("{$this->baseUrl}{$path}", $data),
        };
    }
}
```

---

## 4. Service d'Hydratation SQL

### Implémentation

```php
<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HydrationService
{
    /**
     * Hydrate les résultats Qdrant avec les données SQL
     *
     * @param array $qdrantResults Résultats de la recherche Qdrant
     * @param array $hydrationConfig Configuration d'hydratation de l'agent
     * @return array Données enrichies
     */
    public function hydrate(array $qdrantResults, array $hydrationConfig): array
    {
        if (empty($qdrantResults) || empty($hydrationConfig)) {
            return $qdrantResults;
        }

        $table = $hydrationConfig['table'] ?? null;
        $key = $hydrationConfig['key'] ?? 'db_id';
        $fields = $hydrationConfig['fields'] ?? ['*'];
        $relations = $hydrationConfig['relations'] ?? [];

        if (!$table) {
            return $qdrantResults;
        }

        // Extraction des IDs depuis les payloads Qdrant
        $ids = collect($qdrantResults)
            ->pluck("payload.{$key}")
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (empty($ids)) {
            return $qdrantResults;
        }

        // Requête SQL
        $query = DB::table($table);

        if ($fields !== ['*']) {
            $query->select($fields);
        }

        $dbRecords = $query->whereIn('id', $ids)->get()->keyBy('id');

        // Enrichissement des résultats
        return collect($qdrantResults)->map(function ($result) use ($dbRecords, $key, $relations, $table) {
            $dbId = $result['payload'][$key] ?? null;

            if ($dbId && $dbRecords->has($dbId)) {
                $record = $dbRecords[$dbId];

                // Chargement des relations si configurées
                if (!empty($relations)) {
                    $record = $this->loadRelations($table, $dbId, $record, $relations);
                }

                $result['hydrated_data'] = (array) $record;
                $result['payload']['hydrated'] = true;
            }

            return $result;
        })->toArray();
    }

    /**
     * Charge les relations d'un enregistrement
     */
    private function loadRelations(string $table, int $id, object $record, array $relations): object
    {
        foreach ($relations as $relation) {
            $relationData = $this->loadRelation($table, $id, $relation);
            $record->{$relation} = $relationData;
        }

        return $record;
    }

    /**
     * Charge une relation spécifique
     */
    private function loadRelation(string $parentTable, int $parentId, string $relation): array
    {
        // Mapping des relations connues
        $relationMappings = [
            'ouvrages' => [
                'fournitures' => ['table' => 'fournitures', 'foreign_key' => 'ouvrage_id'],
                'main_oeuvres' => ['table' => 'main_oeuvres', 'foreign_key' => 'ouvrage_id'],
                'children' => ['table' => 'ouvrages', 'foreign_key' => 'parent_id'],
                'components' => [
                    'table' => 'ouvrage_components',
                    'foreign_key' => 'parent_id',
                    'with' => 'ouvrages'
                ],
            ],
        ];

        $mapping = $relationMappings[$parentTable][$relation] ?? null;

        if (!$mapping) {
            return [];
        }

        $query = DB::table($mapping['table'])
            ->where($mapping['foreign_key'], $parentId);

        // Si relation with (many-to-many via pivot)
        if (isset($mapping['with'])) {
            $query->join(
                $mapping['with'],
                "{$mapping['table']}.component_id",
                '=',
                "{$mapping['with']}.id"
            );
        }

        return $query->get()->toArray();
    }

    /**
     * Convertit les données hydratées en texte descriptif pour le prompt
     */
    public function toContextText(array $hydratedData, string $template = null): string
    {
        if ($template) {
            return $this->renderTemplate($template, $hydratedData);
        }

        // Format par défaut
        return $this->formatAsText($hydratedData);
    }

    private function renderTemplate(string $template, array $data): string
    {
        // Simple template engine avec {{ variable }}
        return preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            fn($matches) => $data[$matches[1]] ?? '',
            $template
        );
    }

    private function formatAsText(array $data): string
    {
        $lines = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $lines[] = ucfirst($key) . ": " . json_encode($value, JSON_UNESCAPED_UNICODE);
            } else {
                $lines[] = ucfirst($key) . ": " . $value;
            }
        }

        return implode("\n", $lines);
    }
}
```

---

## 5. Constructeur de Prompts

### Implémentation

```php
<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Agent;
use App\Models\AiSession;
use App\Models\AiMessage;
use Illuminate\Support\Collection;

class PromptBuilder
{
    private HydrationService $hydrationService;

    public function __construct(HydrationService $hydrationService)
    {
        $this->hydrationService = $hydrationService;
    }

    /**
     * Construit le prompt complet pour l'inférence
     *
     * Structure du prompt:
     * [SYSTEM] Instructions de l'agent
     * [CONTEXT] Données RAG (documents ou données SQL)
     * [LEARNED] Réponses similaires déjà validées
     * [HISTORY] Historique de la conversation
     * [USER] Question actuelle
     */
    public function build(
        Agent $agent,
        AiSession $session,
        string $userQuestion,
        array $ragResults = [],
        array $learnedResponses = []
    ): string {
        $parts = [];

        // 1. System Prompt
        $parts[] = $this->buildSystemSection($agent);

        // 2. Context RAG
        if (!empty($ragResults)) {
            $parts[] = $this->buildContextSection($ragResults, $agent);
        }

        // 3. Réponses apprises similaires
        if (!empty($learnedResponses)) {
            $parts[] = $this->buildLearnedSection($learnedResponses);
        }

        // 4. Historique de conversation
        $history = $this->getSessionHistory($session, $agent->context_window_size);
        if ($history->isNotEmpty()) {
            $parts[] = $this->buildHistorySection($history);
        }

        // 5. Question utilisateur
        $parts[] = $this->buildUserSection($userQuestion);

        return implode("\n\n", array_filter($parts));
    }

    private function buildSystemSection(Agent $agent): string
    {
        $systemPrompt = $agent->system_prompt;

        // Ajout de métadonnées contextuelles
        $meta = [
            "Date actuelle: " . now()->format('d/m/Y'),
            "Agent: {$agent->name}",
        ];

        return "### INSTRUCTIONS SYSTÈME ###\n\n{$systemPrompt}\n\n" . implode("\n", $meta);
    }

    private function buildContextSection(array $ragResults, Agent $agent): string
    {
        $contexts = [];

        foreach ($ragResults as $index => $result) {
            $score = round($result['score'] * 100, 1);
            $content = '';

            // Données hydratées (SQL_HYDRATION) ou contenu texte (TEXT_ONLY)
            if ($agent->retrieval_mode === 'SQL_HYDRATION' && isset($result['hydrated_data'])) {
                $content = $this->hydrationService->toContextText($result['hydrated_data']);
            } else {
                $content = $result['payload']['content'] ?? '';
            }

            $contexts[] = "--- Document " . ($index + 1) . " (pertinence: {$score}%) ---\n{$content}";
        }

        return "### CONTEXTE DOCUMENTAIRE ###\n\n" .
            "Les informations suivantes sont issues de la base de connaissances et peuvent aider à répondre:\n\n" .
            implode("\n\n", $contexts);
    }

    private function buildLearnedSection(array $learnedResponses): string
    {
        $examples = [];

        foreach ($learnedResponses as $index => $learned) {
            $question = $learned['payload']['question'] ?? '';
            $answer = $learned['payload']['answer'] ?? '';
            $score = round($learned['score'] * 100, 1);

            $examples[] = "--- Exemple " . ($index + 1) . " (similarité: {$score}%) ---\n" .
                "Question: {$question}\n" .
                "Réponse validée: {$answer}";
        }

        return "### EXEMPLES DE RÉPONSES VALIDÉES ###\n\n" .
            "Voici des réponses similaires qui ont été validées par un expert humain:\n\n" .
            implode("\n\n", $examples);
    }

    private function buildHistorySection(Collection $history): string
    {
        $messages = [];

        foreach ($history as $message) {
            $role = $message->role === 'user' ? 'Utilisateur' : 'Assistant';
            $messages[] = "{$role}: {$message->content}";
        }

        return "### HISTORIQUE DE LA CONVERSATION ###\n\n" . implode("\n\n", $messages);
    }

    private function buildUserSection(string $question): string
    {
        return "### QUESTION ACTUELLE ###\n\nUtilisateur: {$question}\n\n### VOTRE RÉPONSE ###\n\nAssistant:";
    }

    /**
     * Récupère l'historique de la session avec fenêtre glissante
     */
    private function getSessionHistory(AiSession $session, int $windowSize): Collection
    {
        return AiMessage::where('session_id', $session->id)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at', 'desc')
            ->limit($windowSize * 2) // user + assistant = 2 messages par échange
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Estime le nombre de tokens du prompt
     * (Approximation: 1 token ≈ 4 caractères en français)
     */
    public function estimateTokens(string $prompt): int
    {
        return (int) ceil(strlen($prompt) / 4);
    }
}
```

---

## 6. Service RAG Principal

### Implémentation

```php
<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Agent;
use App\Models\AiSession;
use App\Models\AiMessage;
use App\DTOs\AI\RagResponse;
use Illuminate\Support\Facades\Log;

class RagService
{
    public function __construct(
        private EmbeddingService $embeddingService,
        private QdrantService $qdrantService,
        private HydrationService $hydrationService,
        private PromptBuilder $promptBuilder,
        private OllamaService $ollamaService
    ) {}

    /**
     * Processus RAG complet
     */
    public function process(
        Agent $agent,
        AiSession $session,
        string $question
    ): RagResponse {
        $startTime = microtime(true);

        // 1. Génération de l'embedding de la question
        Log::debug('RAG: Generating question embedding');
        $questionEmbedding = $this->embeddingService->embed($question);

        // 2. Recherche dans la collection de l'agent
        Log::debug('RAG: Searching agent collection', ['collection' => $agent->qdrant_collection]);
        $ragResults = $this->qdrantService->search(
            vector: $questionEmbedding,
            collection: $agent->qdrant_collection,
            limit: 5,
            scoreThreshold: 0.5
        );

        // 3. Hydratation SQL si nécessaire
        if ($agent->retrieval_mode === 'SQL_HYDRATION' && $agent->hydration_config) {
            Log::debug('RAG: Hydrating results from SQL');
            $ragResults = $this->hydrationService->hydrate(
                $ragResults,
                $agent->hydration_config
            );
        }

        // 4. Recherche des réponses apprises similaires
        Log::debug('RAG: Searching learned responses');
        $learnedResponses = $this->qdrantService->searchLearnedResponses(
            vector: $questionEmbedding,
            agentId: $agent->id,
            limit: 3
        );

        // 5. Construction du prompt
        Log::debug('RAG: Building prompt');
        $prompt = $this->promptBuilder->build(
            agent: $agent,
            session: $session,
            userQuestion: $question,
            ragResults: $ragResults,
            learnedResponses: $learnedResponses
        );

        // 6. Génération de la réponse
        Log::debug('RAG: Calling LLM', ['model' => $agent->model ?? 'default']);

        // Utiliser un service Ollama spécifique si l'agent a une config custom
        $ollama = $agent->ollama_host
            ? OllamaService::forAgent($agent)
            : $this->ollamaService;

        $llmResponse = $ollama->generate($prompt, [
            'model' => $agent->model,
            'fallback_model' => $agent->fallback_model,
            'temperature' => (float) $agent->temperature,
            'max_tokens' => $agent->max_tokens,
        ]);

        $processingTime = (int) ((microtime(true) - $startTime) * 1000);

        // 7. Construction de la réponse structurée
        return new RagResponse(
            content: $llmResponse->content,
            model: $llmResponse->model,
            tokensPrompt: $llmResponse->tokensPrompt,
            tokensCompletion: $llmResponse->tokensCompletion,
            generationTimeMs: $llmResponse->generationTimeMs,
            totalProcessingTimeMs: $processingTime,
            ragContext: [
                'sources' => collect($ragResults)->map(fn($r) => [
                    'id' => $r['id'],
                    'score' => $r['score'],
                    'content' => $r['payload']['content'] ?? null,
                    'hydrated' => $r['payload']['hydrated'] ?? false,
                ])->toArray(),
                'learned_matches' => collect($learnedResponses)->map(fn($r) => [
                    'score' => $r['score'],
                    'question' => $r['payload']['question'] ?? null,
                ])->toArray(),
                'retrieval_mode' => $agent->retrieval_mode,
            ]
        );
    }
}
```

### DTO RagResponse

```php
<?php

declare(strict_types=1);

namespace App\DTOs\AI;

readonly class RagResponse
{
    public function __construct(
        public string $content,
        public string $model,
        public ?int $tokensPrompt,
        public ?int $tokensCompletion,
        public ?int $generationTimeMs,
        public int $totalProcessingTimeMs,
        public array $ragContext = []
    ) {}

    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'model' => $this->model,
            'tokens_prompt' => $this->tokensPrompt,
            'tokens_completion' => $this->tokensCompletion,
            'generation_time_ms' => $this->generationTimeMs,
            'total_processing_time_ms' => $this->totalProcessingTimeMs,
            'rag_context' => $this->ragContext,
        ];
    }
}
```

---

## 7. Dispatcher Service

### Implémentation

```php
<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Agent;
use App\Models\AiSession;
use App\Models\AiMessage;
use App\DTOs\AI\RagResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatcherService
{
    public function __construct(
        private RagService $ragService
    ) {}

    /**
     * Traite une question utilisateur
     */
    public function dispatch(
        string $agentSlug,
        string $question,
        ?string $sessionUuid = null,
        ?int $userId = null,
        array $externalContext = []
    ): array {
        // 1. Récupération de l'agent
        $agent = Agent::where('slug', $agentSlug)
            ->where('is_active', true)
            ->firstOrFail();

        // 2. Récupération ou création de la session
        $session = $this->getOrCreateSession(
            agent: $agent,
            sessionUuid: $sessionUuid,
            userId: $userId,
            externalContext: $externalContext
        );

        // 3. Enregistrement du message utilisateur
        $userMessage = $this->saveMessage($session, 'user', $question);

        // 4. Traitement RAG
        $ragResponse = $this->ragService->process($agent, $session, $question);

        // 5. Enregistrement de la réponse
        $assistantMessage = $this->saveMessage(
            session: $session,
            role: 'assistant',
            content: $ragResponse->content,
            ragContext: $ragResponse->ragContext,
            model: $ragResponse->model,
            tokensPrompt: $ragResponse->tokensPrompt,
            tokensCompletion: $ragResponse->tokensCompletion,
            generationTimeMs: $ragResponse->generationTimeMs
        );

        // 6. Mise à jour des statistiques de session
        $session->increment('message_count', 2);

        return [
            'session_uuid' => $session->uuid,
            'message_uuid' => $assistantMessage->uuid,
            'response' => $ragResponse->content,
            'agent' => [
                'name' => $agent->name,
                'slug' => $agent->slug,
            ],
            'metadata' => [
                'model' => $ragResponse->model,
                'processing_time_ms' => $ragResponse->totalProcessingTimeMs,
                'sources_count' => count($ragResponse->ragContext['sources'] ?? []),
            ],
        ];
    }

    private function getOrCreateSession(
        Agent $agent,
        ?string $sessionUuid,
        ?int $userId,
        array $externalContext
    ): AiSession {
        if ($sessionUuid) {
            $session = AiSession::where('uuid', $sessionUuid)
                ->where('agent_id', $agent->id)
                ->first();

            if ($session) {
                return $session;
            }
        }

        return AiSession::create([
            'agent_id' => $agent->id,
            'user_id' => $userId,
            'tenant_id' => $agent->tenant_id,
            'external_session_id' => $externalContext['session_id'] ?? null,
            'external_context' => !empty($externalContext) ? $externalContext : null,
        ]);
    }

    private function saveMessage(
        AiSession $session,
        string $role,
        string $content,
        ?array $ragContext = null,
        ?string $model = null,
        ?int $tokensPrompt = null,
        ?int $tokensCompletion = null,
        ?int $generationTimeMs = null
    ): AiMessage {
        return AiMessage::create([
            'session_id' => $session->id,
            'role' => $role,
            'content' => $content,
            'rag_context' => $ragContext,
            'model_used' => $model,
            'tokens_prompt' => $tokensPrompt,
            'tokens_completion' => $tokensCompletion,
            'generation_time_ms' => $generationTimeMs,
        ]);
    }
}
```

---

## 8. Boucle d'Apprentissage

### Service d'Apprentissage

```php
<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiMessage;
use App\Models\Agent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LearningService
{
    public function __construct(
        private EmbeddingService $embeddingService,
        private QdrantService $qdrantService
    ) {}

    /**
     * Enregistre une réponse corrigée et l'indexe dans Qdrant
     */
    public function learn(
        AiMessage $message,
        string $correctedContent,
        int $validatorId
    ): bool {
        // 1. Récupérer le contexte
        $session = $message->session;
        $agent = $session->agent;

        // Trouver la question associée (message précédent de type user)
        $question = AiMessage::where('session_id', $session->id)
            ->where('role', 'user')
            ->where('id', '<', $message->id)
            ->orderBy('id', 'desc')
            ->first();

        if (!$question) {
            Log::warning('Learning: No question found for message', ['message_id' => $message->id]);
            return false;
        }

        return DB::transaction(function () use ($message, $correctedContent, $validatorId, $question, $agent) {
            // 2. Mettre à jour le message
            $message->update([
                'corrected_content' => $correctedContent,
                'validation_status' => 'learned',
                'validated_by' => $validatorId,
                'validated_at' => now(),
            ]);

            // 3. Générer l'embedding de la question
            $questionEmbedding = $this->embeddingService->embed($question->content);

            // 4. Créer le point dans Qdrant
            $pointId = 'learned_msg_' . $message->id;

            $success = $this->qdrantService->upsert(
                collection: 'learned_responses',
                id: $pointId,
                vector: $questionEmbedding,
                payload: [
                    'agent_id' => $agent->id,
                    'agent_slug' => $agent->slug,
                    'message_id' => $message->id,
                    'question' => $question->content,
                    'answer' => $correctedContent,
                    'validated_by' => $validatorId,
                    'validated_at' => now()->toISOString(),
                    'tenant_id' => $agent->tenant_id,
                ]
            );

            if ($success) {
                Log::info('Learning: Response indexed successfully', [
                    'message_id' => $message->id,
                    'agent' => $agent->slug,
                    'point_id' => $pointId,
                ]);
            }

            return $success;
        });
    }

    /**
     * Valide une réponse sans correction
     */
    public function validate(AiMessage $message, int $validatorId): bool
    {
        return $message->update([
            'validation_status' => 'validated',
            'validated_by' => $validatorId,
            'validated_at' => now(),
        ]);
    }

    /**
     * Rejette une réponse
     */
    public function reject(AiMessage $message, int $validatorId, ?string $reason = null): bool
    {
        return $message->update([
            'validation_status' => 'rejected',
            'validated_by' => $validatorId,
            'validated_at' => now(),
            'corrected_content' => $reason, // Utilise ce champ pour stocker la raison
        ]);
    }

    /**
     * Récupère les messages en attente de validation
     */
    public function getPendingMessages(
        ?int $agentId = null,
        int $perPage = 20
    ) {
        $query = AiMessage::with(['session.agent', 'session.user'])
            ->where('role', 'assistant')
            ->where('validation_status', 'pending')
            ->orderBy('created_at', 'desc');

        if ($agentId) {
            $query->whereHas('session', fn($q) => $q->where('agent_id', $agentId));
        }

        return $query->paginate($perPage);
    }

    /**
     * Statistiques d'apprentissage
     */
    public function getStats(?int $agentId = null): array
    {
        $query = AiMessage::where('role', 'assistant');

        if ($agentId) {
            $query->whereHas('session', fn($q) => $q->where('agent_id', $agentId));
        }

        return [
            'total' => $query->count(),
            'pending' => (clone $query)->where('validation_status', 'pending')->count(),
            'validated' => (clone $query)->where('validation_status', 'validated')->count(),
            'learned' => (clone $query)->where('validation_status', 'learned')->count(),
            'rejected' => (clone $query)->where('validation_status', 'rejected')->count(),
        ];
    }
}
```

---

## 9. Services d'Extraction de Documents

Le système permet d'ingérer différents types de documents (PDF, audio, vidéo, etc.) pour enrichir les bases de connaissances des agents IA.

### Architecture des Extracteurs

```
app/Services/Document/
├── Contracts/
│   └── ExtractorInterface.php
├── DocumentService.php           # Orchestrateur principal
├── ChunkingService.php           # Découpage en chunks
├── Extractors/
│   ├── TextExtractor.php         # TXT, MD
│   ├── HtmlExtractor.php         # HTML
│   ├── PdfExtractor.php          # PDF (pdftotext)
│   ├── OfficeExtractor.php       # DOC, DOCX, ODT
│   ├── SpreadsheetExtractor.php  # CSV, XLS, XLSX
│   ├── WhisperExtractor.php      # Audio/Vidéo (transcription)
│   └── OcrExtractor.php          # Images (OCR)
└── Jobs/
    ├── ProcessDocumentJob.php
    └── IndexDocumentChunksJob.php
```

### Interface Extracteur

```php
<?php

declare(strict_types=1);

namespace App\Services\Document\Contracts;

use App\Models\Document;

interface ExtractorInterface
{
    /**
     * Extrait le texte d'un document
     *
     * @param Document $document Le document à traiter
     * @return ExtractionResult Résultat contenant le texte et les métadonnées
     */
    public function extract(Document $document): ExtractionResult;

    /**
     * Types MIME supportés par cet extracteur
     */
    public function supportedMimeTypes(): array;

    /**
     * Vérifie si les dépendances sont disponibles
     */
    public function isAvailable(): bool;
}
```

### DTO ExtractionResult

```php
<?php

declare(strict_types=1);

namespace App\Services\Document;

readonly class ExtractionResult
{
    public function __construct(
        public bool $success,
        public ?string $text = null,
        public array $metadata = [],
        public ?string $error = null,
    ) {}

    public static function success(string $text, array $metadata = []): self
    {
        return new self(
            success: true,
            text: $text,
            metadata: $metadata,
        );
    }

    public static function failure(string $error): self
    {
        return new self(
            success: false,
            error: $error,
        );
    }
}
```

### Extracteur PDF

```php
<?php

declare(strict_types=1);

namespace App\Services\Document\Extractors;

use App\Models\Document;
use App\Services\Document\Contracts\ExtractorInterface;
use App\Services\Document\ExtractionResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class PdfExtractor implements ExtractorInterface
{
    public function extract(Document $document): ExtractionResult
    {
        $filePath = storage_path('app/' . $document->storage_path);

        if (!file_exists($filePath)) {
            return ExtractionResult::failure("Fichier non trouvé: {$filePath}");
        }

        try {
            // Utiliser pdftotext (poppler-utils)
            $result = Process::run([
                'pdftotext',
                '-layout',      // Préserve la mise en page
                '-enc', 'UTF-8',
                $filePath,
                '-'             // Output vers stdout
            ]);

            if (!$result->successful()) {
                return ExtractionResult::failure(
                    "Erreur pdftotext: " . $result->errorOutput()
                );
            }

            $text = $result->output();

            // Récupérer les métadonnées PDF
            $metadata = $this->extractMetadata($filePath);

            return ExtractionResult::success($text, [
                'extractor' => 'pdftotext',
                'pages' => $metadata['pages'] ?? null,
                'author' => $metadata['author'] ?? null,
                'title' => $metadata['title'] ?? null,
                'creation_date' => $metadata['creation_date'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('PDF extraction failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);

            return ExtractionResult::failure($e->getMessage());
        }
    }

    private function extractMetadata(string $filePath): array
    {
        $result = Process::run(['pdfinfo', $filePath]);

        if (!$result->successful()) {
            return [];
        }

        $metadata = [];
        foreach (explode("\n", $result->output()) as $line) {
            if (preg_match('/^([^:]+):\s*(.+)$/', $line, $matches)) {
                $key = strtolower(str_replace(' ', '_', trim($matches[1])));
                $metadata[$key] = trim($matches[2]);
            }
        }

        return $metadata;
    }

    public function supportedMimeTypes(): array
    {
        return ['application/pdf'];
    }

    public function isAvailable(): bool
    {
        $result = Process::run(['which', 'pdftotext']);
        return $result->successful();
    }
}
```

### Extracteur Audio/Vidéo (Whisper)

```php
<?php

declare(strict_types=1);

namespace App\Services\Document\Extractors;

use App\Models\Document;
use App\Services\Document\Contracts\ExtractorInterface;
use App\Services\Document\ExtractionResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class WhisperExtractor implements ExtractorInterface
{
    private string $ollamaHost;
    private int $ollamaPort;
    private string $whisperModel;

    public function __construct()
    {
        $this->ollamaHost = config('ai.ollama.host', 'ollama');
        $this->ollamaPort = config('ai.ollama.port', 11434);
        $this->whisperModel = config('documents.whisper_model', 'whisper');
    }

    public function extract(Document $document): ExtractionResult
    {
        $filePath = storage_path('app/' . $document->storage_path);

        if (!file_exists($filePath)) {
            return ExtractionResult::failure("Fichier non trouvé: {$filePath}");
        }

        try {
            // Pour les vidéos, extraire d'abord l'audio
            $audioPath = $filePath;
            $isVideo = str_starts_with($document->mime_type, 'video/');

            if ($isVideo) {
                $audioPath = $this->extractAudioFromVideo($filePath);
            }

            // Transcription via Whisper (via Ollama ou service dédié)
            $transcription = $this->transcribe($audioPath);

            // Nettoyer le fichier audio temporaire si vidéo
            if ($isVideo && $audioPath !== $filePath) {
                @unlink($audioPath);
            }

            // Calculer la durée
            $duration = $this->getMediaDuration($filePath);

            return ExtractionResult::success($transcription['text'], [
                'extractor' => 'whisper',
                'model' => $this->whisperModel,
                'language' => $transcription['language'] ?? 'fr',
                'duration_seconds' => $duration,
                'segments' => $transcription['segments'] ?? [],
                'is_video' => $isVideo,
            ]);

        } catch (\Exception $e) {
            Log::error('Whisper extraction failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);

            return ExtractionResult::failure($e->getMessage());
        }
    }

    private function extractAudioFromVideo(string $videoPath): string
    {
        $audioPath = sys_get_temp_dir() . '/' . uniqid('audio_') . '.wav';

        $result = Process::run([
            'ffmpeg',
            '-i', $videoPath,
            '-vn',                    // Pas de vidéo
            '-acodec', 'pcm_s16le',   // Format WAV
            '-ar', '16000',           // 16kHz (optimal pour Whisper)
            '-ac', '1',               // Mono
            '-y',                     // Overwrite
            $audioPath
        ]);

        if (!$result->successful()) {
            throw new \RuntimeException(
                "Erreur extraction audio: " . $result->errorOutput()
            );
        }

        return $audioPath;
    }

    private function transcribe(string $audioPath): array
    {
        // Option 1: Utiliser l'API Ollama avec un modèle Whisper
        // (nécessite que Whisper soit disponible via Ollama)

        // Option 2: Utiliser whisper-cpp ou whisper.cpp en local
        // Cette implémentation utilise whisper-cpp

        $result = Process::timeout(600)->run([
            'whisper-cpp',
            '--model', '/models/whisper/ggml-medium.bin',
            '--language', 'fr',
            '--output-json',
            '--file', $audioPath
        ]);

        if (!$result->successful()) {
            // Fallback: essayer avec le modèle small
            $result = Process::timeout(300)->run([
                'whisper-cpp',
                '--model', '/models/whisper/ggml-small.bin',
                '--language', 'fr',
                '--output-json',
                '--file', $audioPath
            ]);
        }

        if (!$result->successful()) {
            throw new \RuntimeException(
                "Erreur transcription: " . $result->errorOutput()
            );
        }

        $jsonOutput = json_decode($result->output(), true);

        return [
            'text' => $jsonOutput['transcription'] ?? $result->output(),
            'language' => $jsonOutput['language'] ?? 'fr',
            'segments' => $jsonOutput['segments'] ?? [],
        ];
    }

    private function getMediaDuration(string $filePath): ?float
    {
        $result = Process::run([
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $filePath
        ]);

        if ($result->successful()) {
            return (float) trim($result->output());
        }

        return null;
    }

    public function supportedMimeTypes(): array
    {
        return [
            // Audio
            'audio/mpeg',
            'audio/wav',
            'audio/ogg',
            'audio/webm',
            'audio/flac',
            'audio/x-m4a',
            // Vidéo
            'video/mp4',
            'video/webm',
            'video/x-msvideo',
            'video/quicktime',
        ];
    }

    public function isAvailable(): bool
    {
        // Vérifier whisper-cpp ou ffmpeg
        $whisperCheck = Process::run(['which', 'whisper-cpp']);
        $ffmpegCheck = Process::run(['which', 'ffmpeg']);

        return $whisperCheck->successful() || $ffmpegCheck->successful();
    }
}
```

### Service de Découpage (Chunking)

```php
<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Str;

class ChunkingService
{
    private int $maxChunkSize;
    private int $chunkOverlap;

    public function __construct()
    {
        $this->maxChunkSize = config('documents.chunk_settings.max_chunk_size', 1000);
        $this->chunkOverlap = config('documents.chunk_settings.chunk_overlap', 100);
    }

    /**
     * Découpe un document en chunks et les sauvegarde
     */
    public function chunkDocument(Document $document, string $strategy = 'paragraph'): int
    {
        $text = $document->extracted_text;

        if (empty($text)) {
            return 0;
        }

        // Supprimer les anciens chunks
        DocumentChunk::where('document_id', $document->id)->delete();

        $chunks = match ($strategy) {
            'paragraph' => $this->chunkByParagraph($text),
            'sentence' => $this->chunkBySentence($text),
            'fixed_size' => $this->chunkByFixedSize($text),
            'semantic' => $this->chunkBySemantic($text),
            default => $this->chunkByParagraph($text),
        };

        $created = 0;
        foreach ($chunks as $index => $chunkData) {
            DocumentChunk::create([
                'document_id' => $document->id,
                'chunk_index' => $index,
                'content' => $chunkData['content'],
                'content_hash' => hash('sha256', $chunkData['content']),
                'token_count' => $this->estimateTokens($chunkData['content']),
                'start_offset' => $chunkData['start_offset'] ?? null,
                'end_offset' => $chunkData['end_offset'] ?? null,
                'page_number' => $chunkData['page_number'] ?? null,
                'context_before' => $chunkData['context_before'] ?? null,
                'context_after' => $chunkData['context_after'] ?? null,
                'metadata' => $chunkData['metadata'] ?? null,
            ]);
            $created++;
        }

        // Mettre à jour le compteur du document
        $document->update([
            'chunk_count' => $created,
            'chunk_strategy' => $strategy,
        ]);

        return $created;
    }

    /**
     * Découpe par paragraphes (double saut de ligne)
     */
    private function chunkByParagraph(string $text): array
    {
        $paragraphs = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $chunks = [];
        $currentChunk = '';
        $currentOffset = 0;

        foreach ($paragraphs as $para) {
            $para = trim($para);

            if (empty($para)) continue;

            // Si ajouter ce paragraphe dépasse la taille max
            if ($this->estimateTokens($currentChunk . "\n\n" . $para) > $this->maxChunkSize) {
                // Sauvegarder le chunk actuel
                if (!empty($currentChunk)) {
                    $chunks[] = [
                        'content' => trim($currentChunk),
                        'start_offset' => $currentOffset,
                    ];
                }
                $currentChunk = $para;
                $currentOffset = strpos($text, $para);
            } else {
                $currentChunk .= "\n\n" . $para;
            }
        }

        // Dernier chunk
        if (!empty($currentChunk)) {
            $chunks[] = [
                'content' => trim($currentChunk),
                'start_offset' => $currentOffset,
            ];
        }

        // Ajouter le contexte (chevauchement)
        return $this->addContextOverlap($chunks);
    }

    /**
     * Découpe par phrases
     */
    private function chunkBySentence(string $text): array
    {
        // Regex pour découper en phrases (gère . ? ! et abréviations courantes)
        $sentences = preg_split(
            '/(?<=[.!?])\s+(?=[A-ZÀÂÄÉÈÊËÏÎÔÙÛÜÇ])/',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        $chunks = [];
        $currentChunk = '';

        foreach ($sentences as $sentence) {
            if ($this->estimateTokens($currentChunk . ' ' . $sentence) > $this->maxChunkSize) {
                if (!empty($currentChunk)) {
                    $chunks[] = ['content' => trim($currentChunk)];
                }
                $currentChunk = $sentence;
            } else {
                $currentChunk .= ' ' . $sentence;
            }
        }

        if (!empty($currentChunk)) {
            $chunks[] = ['content' => trim($currentChunk)];
        }

        return $this->addContextOverlap($chunks);
    }

    /**
     * Découpe par taille fixe (avec chevauchement)
     */
    private function chunkByFixedSize(string $text): array
    {
        $words = preg_split('/\s+/', $text);
        $chunks = [];
        $chunkWords = [];
        $wordsPerChunk = (int) ($this->maxChunkSize * 0.75); // Approximation tokens->mots

        foreach ($words as $word) {
            $chunkWords[] = $word;

            if (count($chunkWords) >= $wordsPerChunk) {
                $chunks[] = ['content' => implode(' ', $chunkWords)];

                // Conserver les derniers mots pour le chevauchement
                $overlapWords = (int) ($this->chunkOverlap * 0.75);
                $chunkWords = array_slice($chunkWords, -$overlapWords);
            }
        }

        if (!empty($chunkWords)) {
            $chunks[] = ['content' => implode(' ', $chunkWords)];
        }

        return $chunks;
    }

    /**
     * Découpe sémantique (utilise les embeddings pour trouver les coupures)
     */
    private function chunkBySemantic(string $text): array
    {
        // Pour l'instant, fallback sur paragraph
        // TODO: Implémenter avec détection de changement de thème via embeddings
        return $this->chunkByParagraph($text);
    }

    /**
     * Ajoute le contexte (chevauchement) entre chunks adjacents
     */
    private function addContextOverlap(array $chunks): array
    {
        $result = [];

        foreach ($chunks as $i => $chunk) {
            $chunk['context_before'] = $i > 0
                ? Str::limit($chunks[$i - 1]['content'], 200, '...')
                : null;

            $chunk['context_after'] = isset($chunks[$i + 1])
                ? Str::limit($chunks[$i + 1]['content'], 200, '...')
                : null;

            $result[] = $chunk;
        }

        return $result;
    }

    /**
     * Estime le nombre de tokens (approximation)
     */
    private function estimateTokens(string $text): int
    {
        // Approximation: 1 token ≈ 4 caractères en français
        return (int) ceil(strlen($text) / 4);
    }
}
```

### Service Principal DocumentService

```php
<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\Document;
use App\Models\Agent;
use App\Services\Document\Contracts\ExtractorInterface;
use App\Services\Document\Extractors\PdfExtractor;
use App\Services\Document\Extractors\WhisperExtractor;
use App\Services\Document\Extractors\TextExtractor;
use App\Services\AI\EmbeddingService;
use App\Services\AI\QdrantService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DocumentService
{
    private array $extractors = [];

    public function __construct(
        private ChunkingService $chunkingService,
        private EmbeddingService $embeddingService,
        private QdrantService $qdrantService
    ) {
        // Enregistrement des extracteurs
        $this->registerExtractor(new TextExtractor());
        $this->registerExtractor(new PdfExtractor());
        $this->registerExtractor(new WhisperExtractor());
        // Ajouter d'autres extracteurs ici
    }

    public function registerExtractor(ExtractorInterface $extractor): void
    {
        foreach ($extractor->supportedMimeTypes() as $mimeType) {
            $this->extractors[$mimeType] = $extractor;
        }
    }

    /**
     * Upload et traitement d'un document
     */
    public function upload(
        UploadedFile $file,
        ?Agent $agent = null,
        array $metadata = []
    ): Document {
        // Validation
        $this->validateFile($file);

        // Stockage du fichier
        $storagePath = $this->storeFile($file);

        // Création du document
        $document = Document::create([
            'tenant_id' => $agent?->tenant_id ?? auth()->user()?->tenant_id,
            'agent_id' => $agent?->id,
            'original_name' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'document_type' => $this->determineDocumentType($file->getMimeType()),
            'title' => $metadata['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'description' => $metadata['description'] ?? null,
            'category' => $metadata['category'] ?? null,
            'tags' => $metadata['tags'] ?? [],
            'source_url' => $metadata['source_url'] ?? null,
            'uploaded_by' => auth()->id(),
            'extraction_status' => 'pending',
        ]);

        return $document;
    }

    /**
     * Extrait le texte d'un document
     */
    public function extract(Document $document): bool
    {
        $document->update(['extraction_status' => 'processing']);

        $extractor = $this->extractors[$document->mime_type] ?? null;

        if (!$extractor) {
            $document->update([
                'extraction_status' => 'failed',
                'extraction_error' => "Aucun extracteur pour le type: {$document->mime_type}",
            ]);
            return false;
        }

        if (!$extractor->isAvailable()) {
            $document->update([
                'extraction_status' => 'failed',
                'extraction_error' => "Extracteur non disponible (dépendances manquantes)",
            ]);
            return false;
        }

        $result = $extractor->extract($document);

        if (!$result->success) {
            $document->update([
                'extraction_status' => 'failed',
                'extraction_error' => $result->error,
            ]);
            return false;
        }

        $document->update([
            'extraction_status' => 'completed',
            'extracted_text' => $result->text,
            'extraction_metadata' => $result->metadata,
            'extracted_at' => now(),
        ]);

        Log::info('Document extracted successfully', [
            'document_id' => $document->id,
            'text_length' => strlen($result->text),
        ]);

        return true;
    }

    /**
     * Découpe et indexe un document dans Qdrant
     */
    public function index(Document $document, string $chunkStrategy = 'paragraph'): bool
    {
        if (empty($document->extracted_text)) {
            Log::warning('Cannot index document without extracted text', [
                'document_id' => $document->id
            ]);
            return false;
        }

        // Découpage en chunks
        $chunkCount = $this->chunkingService->chunkDocument($document, $chunkStrategy);

        if ($chunkCount === 0) {
            return false;
        }

        // Indexation des chunks
        $chunks = $document->chunks()->where('is_indexed', false)->get();
        $collection = $document->agent?->qdrant_collection ?? 'agent_support_docs';
        $points = [];

        foreach ($chunks as $chunk) {
            try {
                $embedding = $this->embeddingService->embed($chunk->content);

                $pointId = 'doc_' . $document->id . '_chunk_' . $chunk->chunk_index;

                $points[] = [
                    'id' => $pointId,
                    'vector' => $embedding,
                    'payload' => [
                        'document_id' => $document->id,
                        'chunk_index' => $chunk->chunk_index,
                        'content' => $chunk->content,
                        'title' => $document->title,
                        'category' => $document->category,
                        'source' => 'document',
                        'document_type' => $document->document_type,
                        'page_number' => $chunk->page_number,
                        'tenant_id' => $document->tenant_id,
                        'indexed_at' => now()->toISOString(),
                    ],
                ];

                $chunk->update([
                    'qdrant_point_id' => $pointId,
                    'is_indexed' => true,
                    'indexed_at' => now(),
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to embed chunk', [
                    'chunk_id' => $chunk->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Envoi batch à Qdrant
        if (!empty($points)) {
            $this->qdrantService->upsertBatch($collection, $points);
        }

        $document->update([
            'is_indexed' => true,
            'indexed_at' => now(),
        ]);

        Log::info('Document indexed', [
            'document_id' => $document->id,
            'chunks' => count($points),
            'collection' => $collection,
        ]);

        return true;
    }

    /**
     * Traitement complet : upload → extraction → indexation
     */
    public function processComplete(
        UploadedFile $file,
        ?Agent $agent = null,
        array $metadata = []
    ): Document {
        // 1. Upload
        $document = $this->upload($file, $agent, $metadata);

        // 2. Extraction
        $this->extract($document);

        // 3. Indexation (si extraction réussie)
        if ($document->extraction_status === 'completed') {
            $this->index($document);
        }

        return $document->fresh();
    }

    private function validateFile(UploadedFile $file): void
    {
        $maxSize = config('documents.max_file_size', 104857600);
        $allowedTypes = array_keys(config('documents.allowed_types', []));

        if ($file->getSize() > $maxSize) {
            throw new \InvalidArgumentException(
                "Fichier trop volumineux. Maximum: " . ($maxSize / 1024 / 1024) . " MB"
            );
        }

        if (!in_array($file->getMimeType(), $allowedTypes)) {
            throw new \InvalidArgumentException(
                "Type de fichier non supporté: " . $file->getMimeType()
            );
        }
    }

    private function storeFile(UploadedFile $file): string
    {
        $directory = 'documents/' . date('Y/m');
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();

        return $file->storeAs($directory, $filename);
    }

    private function determineDocumentType(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'text/') => 'text',
            $mimeType === 'application/pdf' => 'pdf',
            str_contains($mimeType, 'word') || str_contains($mimeType, 'opendocument.text') => 'document',
            str_contains($mimeType, 'spreadsheet') || str_contains($mimeType, 'excel') || $mimeType === 'text/csv' => 'spreadsheet',
            str_starts_with($mimeType, 'audio/') => 'audio',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'image/') => 'image',
            default => 'other',
        };
    }
}
```

### Job de Traitement Asynchrone

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
use App\Services\Document\DocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600; // 10 minutes max (pour vidéos longues)

    public function __construct(
        public Document $document,
        public bool $autoIndex = true
    ) {}

    public function handle(DocumentService $documentService): void
    {
        // Extraction
        $success = $documentService->extract($this->document);

        // Indexation automatique si demandé
        if ($success && $this->autoIndex) {
            $documentService->index($this->document);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->document->update([
            'extraction_status' => 'failed',
            'extraction_error' => $exception->getMessage(),
        ]);
    }
}
```

### Dépendances Docker

Pour supporter l'extraction multi-format, ajouter au Dockerfile :

```dockerfile
# Outils d'extraction de texte
RUN apk add --no-cache \
    poppler-utils \    # pdftotext, pdfinfo
    ffmpeg \           # Extraction audio/vidéo
    tesseract-ocr \    # OCR images
    tesseract-ocr-data-fra \  # Données OCR français
    libreoffice \      # Conversion documents Office (optionnel)
    && rm -rf /var/cache/apk/*

# Whisper.cpp pour la transcription (optionnel, peut utiliser Ollama)
# Voir: https://github.com/ggerganov/whisper.cpp
```

### Configuration `config/documents.php`

```php
<?php

return [
    'allowed_types' => [
        // ... (voir section schéma BDD)
    ],

    'max_file_size' => env('DOCUMENT_MAX_SIZE', 104857600), // 100 MB

    'chunk_settings' => [
        'default_strategy' => 'paragraph',
        'max_chunk_size' => 1000,
        'chunk_overlap' => 100,
    ],

    'whisper_model' => env('WHISPER_MODEL', 'medium'),

    'storage' => [
        'disk' => env('DOCUMENT_STORAGE_DISK', 'local'),
        'directory' => 'documents',
    ],

    'processing' => [
        'auto_extract' => true,
        'auto_index' => true,
        'queue' => env('DOCUMENT_QUEUE', 'default'),
    ],
];
```

---

## 10. Indexation des Ouvrages BTP

### Commande Artisan

```php
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
                            {--type= : Filtrer par type (compose, simple, etc.)}';

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

        $this->info("Indexation de {$total} ouvrages...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $indexed = 0;
        $errors = 0;

        $query->chunkById($chunkSize, function ($ouvrages) use (&$indexed, &$errors, $bar) {
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
                    $this->error("\nErreur pour ouvrage {$ouvrage->id}: " . $e->getMessage());
                    $errors++;
                }

                $bar->advance();
            }

            // Envoi en batch à Qdrant
            if (!empty($points)) {
                $this->qdrantService->upsertBatch('agent_btp_ouvrages', $points);
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Indexation terminée: {$indexed} succès, {$errors} erreurs");

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
```

---

## 10. Initialisation Qdrant avec Données de Test

### Commande : `qdrant:init`

Cette commande est appelée automatiquement par l'entrypoint Docker au premier démarrage.
Elle crée les collections et indexe les données de test.

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Ouvrage;
use App\Services\AI\EmbeddingService;
use App\Services\AI\QdrantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class QdrantInitCommand extends Command
{
    protected $signature = 'qdrant:init
                            {--with-test-data : Indexe également les données de test}
                            {--force : Recrée les collections même si elles existent}';

    protected $description = 'Initialise les collections Qdrant et optionnellement les données de test';

    public function __construct(
        private QdrantService $qdrantService,
        private EmbeddingService $embeddingService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $withTestData = $this->option('with-test-data');
        $force = $this->option('force');

        $this->info('🧠 Initialisation de Qdrant...');

        // 1. Création des collections
        $this->createCollections($force);

        // 2. Indexation des données de test si demandé
        if ($withTestData) {
            $this->info('');
            $this->info('📊 Indexation des données de test...');

            $this->indexOuvrages();
            $this->indexSupportDocs();
        }

        $this->info('');
        $this->info('✅ Initialisation Qdrant terminée !');

        return Command::SUCCESS;
    }

    private function createCollections(bool $force): void
    {
        $collections = config('qdrant.collections', []);

        foreach ($collections as $name => $config) {
            $exists = $this->qdrantService->collectionExists($name);

            if ($exists && !$force) {
                $this->line("   ⏭️  Collection '{$name}' existe déjà");
                continue;
            }

            if ($exists && $force) {
                $this->qdrantService->deleteCollection($name);
                $this->line("   🗑️  Collection '{$name}' supprimée");
            }

            $success = $this->qdrantService->createCollection($name, $config);

            if ($success) {
                $this->info("   ✅ Collection '{$name}' créée");
            } else {
                $this->error("   ❌ Erreur création '{$name}'");
            }
        }
    }

    private function indexOuvrages(): void
    {
        $ouvrages = Ouvrage::where('is_indexed', false)->get();

        if ($ouvrages->isEmpty()) {
            $this->line('   ⏭️  Aucun ouvrage à indexer');
            return;
        }

        $this->line("   📦 Indexation de {$ouvrages->count()} ouvrages...");

        $bar = $this->output->createProgressBar($ouvrages->count());
        $bar->start();

        $points = [];
        foreach ($ouvrages as $ouvrage) {
            try {
                $description = $this->buildOuvrageDescription($ouvrage);
                $embedding = $this->embeddingService->embed($description);

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

                $ouvrage->update([
                    'is_indexed' => true,
                    'indexed_at' => now(),
                    'qdrant_point_id' => $pointId,
                ]);

            } catch (\Exception $e) {
                Log::error("Erreur indexation ouvrage {$ouvrage->id}", ['error' => $e->getMessage()]);
            }

            $bar->advance();
        }

        // Envoi batch
        if (!empty($points)) {
            $this->qdrantService->upsertBatch('agent_btp_ouvrages', $points);
        }

        $bar->finish();
        $this->newLine();
        $this->info("   ✅ {$ouvrages->count()} ouvrages indexés dans 'agent_btp_ouvrages'");
    }

    private function indexSupportDocs(): void
    {
        $jsonPath = storage_path('app/seed-data/support-docs.json');

        if (!file_exists($jsonPath)) {
            $this->line('   ⏭️  Aucun document support à indexer');
            return;
        }

        $docs = json_decode(file_get_contents($jsonPath), true);

        if (empty($docs)) {
            $this->line('   ⏭️  Fichier support-docs.json vide');
            return;
        }

        $this->line("   📚 Indexation de " . count($docs) . " documents support...");

        $bar = $this->output->createProgressBar(count($docs));
        $bar->start();

        $points = [];
        foreach ($docs as $doc) {
            try {
                // Combiner titre et contenu pour l'embedding
                $text = $doc['title'] . "\n\n" . $doc['content'];
                $embedding = $this->embeddingService->embed($text);

                $pointId = 'doc_' . $doc['slug'];

                $points[] = [
                    'id' => $pointId,
                    'vector' => $embedding,
                    'payload' => [
                        'slug' => $doc['slug'],
                        'title' => $doc['title'],
                        'content' => $doc['content'],
                        'category' => $doc['category'],
                        'source' => 'seed',
                        'indexed_at' => now()->toISOString(),
                    ],
                ];

            } catch (\Exception $e) {
                Log::error("Erreur indexation doc {$doc['slug']}", ['error' => $e->getMessage()]);
            }

            $bar->advance();
        }

        // Envoi batch
        if (!empty($points)) {
            $this->qdrantService->upsertBatch('agent_support_docs', $points);
        }

        $bar->finish();
        $this->newLine();
        $this->info("   ✅ " . count($docs) . " documents indexés dans 'agent_support_docs'");
    }

    private function buildOuvrageDescription(Ouvrage $ouvrage): string
    {
        $parts = [
            $ouvrage->name . '.',
        ];

        if ($ouvrage->description) {
            $parts[] = $ouvrage->description;
        }

        if ($ouvrage->category) {
            $cat = "Catégorie: {$ouvrage->category}";
            if ($ouvrage->subcategory) {
                $cat .= " / {$ouvrage->subcategory}";
            }
            $parts[] = $cat . '.';
        }

        $parts[] = "Unité: {$ouvrage->unit}. Prix: " .
            number_format((float) $ouvrage->unit_price, 2, ',', ' ') . " €.";

        if (!empty($ouvrage->technical_specs)) {
            $specs = collect($ouvrage->technical_specs)
                ->map(fn($v, $k) => ucfirst(str_replace('_', ' ', $k)) . ": $v")
                ->join(', ');
            $parts[] = "Caractéristiques: {$specs}.";
        }

        return implode(' ', $parts);
    }
}
```

### Comportement au Démarrage

```
🚀 AI-Manager CMS - Initialisation...
📌 Premier démarrage détecté
⏳ Attente de PostgreSQL...
✅ PostgreSQL est prêt
⏳ Attente de Qdrant...
✅ Qdrant est prêt
🔧 Configuration de l'application...
🔑 Génération de la clé d'application...
📦 Exécution des migrations...
🌱 Exécution des seeders...
👤 Utilisateurs créés:
   - admin@ai-manager.local / password (Super Admin)
   - validateur@ai-manager.local / password (Validateur)
🤖 Agents IA créés:
   - expert-btp (SQL_HYDRATION) → Ouvrages BTP
   - support-client (TEXT_ONLY) → FAQ Support
🏗️ 10 ouvrages BTP créés
📚 10 documents support préparés pour indexation
🧠 Initialisation de Qdrant...
   ✅ Collection 'agent_btp_ouvrages' créée
   ✅ Collection 'agent_support_docs' créée
   ✅ Collection 'learned_responses' créée

📊 Indexation des données de test...
   📦 Indexation de 10 ouvrages...
   ✅ 10 ouvrages indexés dans 'agent_btp_ouvrages'
   📚 Indexation de 10 documents support...
   ✅ 10 documents indexés dans 'agent_support_docs'

✅ Initialisation Qdrant terminée !
🧹 Nettoyage des caches...
✅ Initialisation terminée !
🎉 AI-Manager CMS prêt !

📊 Informations de connexion :
   - Admin: admin@ai-manager.local / password
   - URL: http://localhost:8080
```

---

## 11. Configuration

### Fichier : `config/ai.php`

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ollama Configuration
    |--------------------------------------------------------------------------
    */

    'ollama' => [
        'host' => env('OLLAMA_HOST', 'ollama'),
        'port' => env('OLLAMA_PORT', 11434),
        'timeout' => env('OLLAMA_TIMEOUT', 120),

        // Modèle par défaut pour la génération
        'default_model' => env('OLLAMA_DEFAULT_MODEL', 'mistral:7b'),

        // Modèle pour les embeddings (doit générer des vecteurs de dimension 768)
        'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Qdrant Configuration
    |--------------------------------------------------------------------------
    */

    'qdrant' => [
        'host' => env('QDRANT_HOST', 'qdrant'),
        'port' => env('QDRANT_PORT', 6333),
        'api_key' => env('QDRANT_API_KEY'),

        'vector_size' => 768,

        // Collections à créer automatiquement
        'collections' => [
            'agent_btp_ouvrages' => [
                'size' => 768,
                'distance' => 'Cosine',
            ],
            'agent_support_docs' => [
                'size' => 768,
                'distance' => 'Cosine',
            ],
            'learned_responses' => [
                'size' => 768,
                'distance' => 'Cosine',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding Cache (désactivé par défaut en dev)
    |--------------------------------------------------------------------------
    */

    'embedding_cache' => [
        'enabled' => env('EMBEDDING_CACHE_ENABLED', false),
        'ttl' => env('EMBEDDING_CACHE_TTL', 3600), // 1 heure
    ],

    /*
    |--------------------------------------------------------------------------
    | RAG Configuration
    |--------------------------------------------------------------------------
    */

    'rag' => [
        // Nombre de documents à récupérer
        'max_documents' => 5,

        // Seuil de score minimum pour les documents
        'score_threshold' => 0.5,

        // Seuil pour les réponses apprises (plus strict)
        'learned_score_threshold' => 0.85,

        // Nombre max de réponses apprises à inclure
        'max_learned_responses' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Agent Parameters
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'context_window_size' => 10,
        'max_tokens' => 2048,
        'temperature' => 0.7,
    ],

];
```

### Fichier : `config/qdrant.php`

```php
<?php

return [
    'host' => env('QDRANT_HOST', 'qdrant'),
    'port' => env('QDRANT_PORT', 6333),
    'api_key' => env('QDRANT_API_KEY'),
    'vector_size' => 768,

    'collections' => [
        'agent_btp_ouvrages' => [
            'size' => 768,
            'distance' => 'Cosine',
            'on_disk' => false,
        ],
        'agent_support_docs' => [
            'size' => 768,
            'distance' => 'Cosine',
        ],
        'learned_responses' => [
            'size' => 768,
            'distance' => 'Cosine',
        ],
    ],
];
```

---

## 11. Diagramme de Flux Complet

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        FLUX DE TRAITEMENT DÉTAILLÉ                           │
└─────────────────────────────────────────────────────────────────────────────┘

Utilisateur                 Dispatcher              RAG Service
    │                           │                        │
    │  1. Question              │                        │
    │ ─────────────────────────>│                        │
    │   + agent_slug            │                        │
    │   + session_uuid (opt)    │                        │
    │                           │                        │
    │                           │  2. Load Agent         │
    │                           │ ───────────>           │
    │                           │ <─ Agent Config ──     │
    │                           │                        │
    │                           │  3. Get/Create Session │
    │                           │ ────────────>          │
    │                           │ <─ Session ───         │
    │                           │                        │
    │                           │  4. Save User Message  │
    │                           │ ──────────────>        │
    │                           │                        │
    │                           │  5. Process            │
    │                           │ ─────────────────────> │
    │                           │                        │
    │                           │                        │──┐ 5a. Generate
    │                           │                        │  │     Embedding
    │                           │                        │<─┘
    │                           │                        │
    │                           │                        │──┐ 5b. Search
    │                           │                        │  │     Qdrant
    │                           │                        │<─┘
    │                           │                        │
    │                           │                        │──┐ 5c. Hydrate
    │                           │                        │  │     (if SQL mode)
    │                           │                        │<─┘
    │                           │                        │
    │                           │                        │──┐ 5d. Search
    │                           │                        │  │     Learned
    │                           │                        │<─┘
    │                           │                        │
    │                           │                        │──┐ 5e. Build
    │                           │                        │  │     Prompt
    │                           │                        │<─┘
    │                           │                        │
    │                           │                        │──┐ 5f. Call
    │                           │                        │  │     Ollama
    │                           │                        │<─┘
    │                           │                        │
    │                           │  6. RagResponse        │
    │                           │ <───────────────────── │
    │                           │                        │
    │                           │  7. Save AI Message    │
    │                           │ ──────────────>        │
    │                           │                        │
    │  8. Response              │                        │
    │ <─────────────────────────│                        │
    │   + content               │                        │
    │   + session_uuid          │                        │
    │   + metadata              │                        │
    │                           │                        │
    ▼                           ▼                        ▼

                        BOUCLE D'APPRENTISSAGE (ASYNC)

Admin                    Learning Service              Qdrant
    │                           │                        │
    │  1. Review Message        │                        │
    │ ─────────────────────────>│                        │
    │                           │                        │
    │  2. Edit & Submit         │                        │
    │ ─────────────────────────>│                        │
    │   + corrected_content     │                        │
    │                           │                        │
    │                           │  3. Update Message     │
    │                           │ ────────────>          │
    │                           │                        │
    │                           │  4. Generate Embedding │
    │                           │ ──────────────>        │
    │                           │                        │
    │                           │  5. Upsert to          │
    │                           │     learned_responses  │
    │                           │ ─────────────────────> │
    │                           │                        │
    │  6. Success               │                        │
    │ <─────────────────────────│                        │
    │                           │                        │
    ▼                           ▼                        ▼
```

---

## 12. Tests

### Test du Service RAG

```php
<?php

namespace Tests\Unit\Services\AI;

use App\Models\Agent;
use App\Models\AiSession;
use App\Services\AI\RagService;
use App\Services\AI\EmbeddingService;
use App\Services\AI\QdrantService;
use App\Services\AI\HydrationService;
use App\Services\AI\PromptBuilder;
use App\Services\AI\OllamaService;
use Tests\TestCase;
use Mockery;

class RagServiceTest extends TestCase
{
    private RagService $ragService;

    protected function setUp(): void
    {
        parent::setUp();

        // Mocks des dépendances
        $embeddingService = Mockery::mock(EmbeddingService::class);
        $embeddingService->shouldReceive('embed')
            ->andReturn(array_fill(0, 768, 0.1));

        $qdrantService = Mockery::mock(QdrantService::class);
        $qdrantService->shouldReceive('search')
            ->andReturn([
                [
                    'id' => 'doc_1',
                    'score' => 0.85,
                    'payload' => ['content' => 'Test content']
                ]
            ]);
        $qdrantService->shouldReceive('searchLearnedResponses')
            ->andReturn([]);

        $hydrationService = Mockery::mock(HydrationService::class);
        $hydrationService->shouldReceive('hydrate')
            ->andReturnUsing(fn($results, $config) => $results);

        $ollamaService = Mockery::mock(OllamaService::class);
        $ollamaService->shouldReceive('generate')
            ->andReturn(new \App\DTOs\AI\LLMResponse(
                content: 'Test response',
                model: 'mistral:7b',
                tokensPrompt: 100,
                tokensCompletion: 50,
                generationTimeMs: 1000
            ));

        $promptBuilder = new PromptBuilder($hydrationService);

        $this->ragService = new RagService(
            $embeddingService,
            $qdrantService,
            $hydrationService,
            $promptBuilder,
            $ollamaService
        );
    }

    public function test_process_returns_rag_response(): void
    {
        $agent = Agent::factory()->create([
            'retrieval_mode' => 'TEXT_ONLY',
        ]);
        $session = AiSession::factory()->create(['agent_id' => $agent->id]);

        $response = $this->ragService->process(
            $agent,
            $session,
            'Test question'
        );

        $this->assertInstanceOf(\App\DTOs\AI\RagResponse::class, $response);
        $this->assertEquals('Test response', $response->content);
        $this->assertArrayHasKey('sources', $response->ragContext);
    }
}
```

---

## 13. Service Accès Public

### PublicAccessService

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Agent;
use App\Models\PublicAccessToken;
use App\Models\AiSession;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PublicAccessService
{
    /**
     * Génère un token d'accès public pour un agent
     */
    public function generateToken(
        Agent $agent,
        int $createdBy,
        array $options = []
    ): PublicAccessToken {
        // Vérifier que l'agent autorise l'accès public
        if (!$agent->allow_public_access) {
            throw new \InvalidArgumentException(
                "L'agent '{$agent->name}' n'autorise pas l'accès public"
            );
        }

        // Calculer l'expiration
        $expiresInHours = $options['expires_in_hours']
            ?? $agent->default_token_expiry_hours
            ?? 168; // 7 jours par défaut

        return PublicAccessToken::create([
            'token' => Str::random(64),
            'agent_id' => $agent->id,
            'created_by' => $createdBy,
            'tenant_id' => $agent->tenant_id,
            'external_app' => $options['external_app'] ?? null,
            'external_ref' => $options['external_ref'] ?? null,
            'external_meta' => $options['external_meta'] ?? null,
            'client_info' => $options['client_info'] ?? null,
            'expires_at' => Carbon::now()->addHours($expiresInHours),
            'max_uses' => $options['max_uses'] ?? 1,
            'status' => 'active',
        ]);
    }

    /**
     * Valide un token et retourne la session associée
     */
    public function validateAndUse(string $token): ?AiSession
    {
        $publicToken = PublicAccessToken::where('token', $token)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first();

        if (!$publicToken) {
            return null;
        }

        // Vérifier le nombre d'utilisations
        if ($publicToken->max_uses && $publicToken->use_count >= $publicToken->max_uses) {
            $publicToken->update(['status' => 'used']);
            return null;
        }

        // Créer ou récupérer la session
        $session = $publicToken->session;

        if (!$session) {
            $session = AiSession::create([
                'agent_id' => $publicToken->agent_id,
                'tenant_id' => $publicToken->tenant_id,
                'public_token_id' => $publicToken->id,
                'external_session_id' => $publicToken->external_ref,
                'external_context' => [
                    'app' => $publicToken->external_app,
                    'ref' => $publicToken->external_ref,
                    'meta' => $publicToken->external_meta,
                ],
            ]);

            $publicToken->update(['session_id' => $session->id]);
        }

        // Mettre à jour les statistiques
        $publicToken->increment('use_count');
        $publicToken->update([
            'first_used_at' => $publicToken->first_used_at ?? now(),
            'last_used_at' => now(),
            'last_ip' => request()->ip(),
            'last_user_agent' => request()->userAgent(),
        ]);

        return $session;
    }

    /**
     * Révoque un token
     */
    public function revoke(PublicAccessToken $token): bool
    {
        return $token->update(['status' => 'revoked']);
    }

    /**
     * Récupère les sessions d'un dossier externe
     */
    public function getSessionsByExternalRef(
        string $externalApp,
        string $externalRef
    ): \Illuminate\Database\Eloquent\Collection {
        return AiSession::whereHas('publicToken', function ($query) use ($externalApp, $externalRef) {
            $query->where('external_app', $externalApp)
                  ->where('external_ref', $externalRef);
        })->with(['messages', 'agent'])->get();
    }

    /**
     * Génère l'URL publique
     */
    public function getPublicUrl(PublicAccessToken $token): string
    {
        return url("/c/{$token->token}");
    }
}
```

---

## 14. Service RAG Itératif

Ce service étend le RAG standard pour permettre des recherches multiples
quand l'agent a `allow_iterative_search: true`.

### IterativeRagService

```php
<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Agent;
use App\Models\AiSession;
use App\DTOs\AI\RagResponse;
use Illuminate\Support\Facades\Log;

class IterativeRagService
{
    public function __construct(
        private RagService $ragService,
        private EmbeddingService $embeddingService,
        private QdrantService $qdrantService,
        private HydrationService $hydrationService,
        private OllamaService $ollamaService
    ) {}

    /**
     * Processus RAG avec support de recherches itératives
     */
    public function process(
        Agent $agent,
        AiSession $session,
        string $question,
        array $attachments = []
    ): RagResponse {
        // Si l'agent n'autorise pas la recherche itérative, utiliser le RAG standard
        if (!$agent->allow_iterative_search) {
            return $this->ragService->process($agent, $session, $question);
        }

        // Première passe : RAG standard avec plus de résultats
        $initialResponse = $this->processWithConfig($agent, $session, $question, [
            'max_results' => $agent->max_rag_results,
        ]);

        // Analyser si l'IA demande des recherches supplémentaires
        $parsedResponse = $this->parseResponse($initialResponse->content);

        if (isset($parsedResponse['action']) && $parsedResponse['action'] === 'search_additional') {
            // L'IA demande des recherches supplémentaires
            $additionalQueries = $parsedResponse['queries'] ?? [];

            if (!empty($additionalQueries)) {
                Log::info('Iterative RAG: Additional searches requested', [
                    'queries' => $additionalQueries
                ]);

                // Effectuer les recherches supplémentaires
                $additionalResults = $this->performAdditionalSearches(
                    $agent,
                    $additionalQueries
                );

                // Relancer la génération avec tous les résultats
                return $this->processWithAdditionalContext(
                    $agent,
                    $session,
                    $question,
                    $initialResponse,
                    $additionalResults
                );
            }
        }

        return $initialResponse;
    }

    /**
     * Effectue des recherches supplémentaires
     */
    private function performAdditionalSearches(Agent $agent, array $queries): array
    {
        $allResults = [];

        foreach ($queries as $query) {
            $embedding = $this->embeddingService->embed($query);

            $results = $this->qdrantService->search(
                vector: $embedding,
                collection: $agent->qdrant_collection,
                limit: 5,
                scoreThreshold: 0.5
            );

            // Hydrater si nécessaire
            if ($agent->retrieval_mode === 'SQL_HYDRATION' && $agent->hydration_config) {
                $results = $this->hydrationService->hydrate(
                    $results,
                    $agent->hydration_config
                );
            }

            $allResults[$query] = $results;
        }

        return $allResults;
    }

    /**
     * Parse la réponse pour détecter les demandes de recherche
     */
    private function parseResponse(string $content): array
    {
        // Chercher un bloc JSON dans la réponse
        if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
            $json = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }

        // Essayer de parser directement si c'est du JSON
        $json = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }

        return [];
    }

    private function processWithConfig(
        Agent $agent,
        AiSession $session,
        string $question,
        array $config
    ): RagResponse {
        // Utiliser le RagService avec configuration personnalisée
        return $this->ragService->process($agent, $session, $question);
    }

    private function processWithAdditionalContext(
        Agent $agent,
        AiSession $session,
        string $question,
        RagResponse $initialResponse,
        array $additionalResults
    ): RagResponse {
        // Construire un contexte enrichi avec les résultats supplémentaires
        // et relancer la génération
        // ...implementation...

        return $initialResponse; // Placeholder
    }
}
```

---

## 15. Service Génération PDF

### PdfGeneratorService

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiSession;
use App\Models\AiMessage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class PdfGeneratorService
{
    /**
     * Génère un PDF récapitulatif de la conversation
     */
    public function generateSessionRecap(AiSession $session): string
    {
        $session->load(['agent', 'messages.attachments', 'publicToken']);

        $data = [
            'session' => $session,
            'agent' => $session->agent,
            'messages' => $session->messages()->orderBy('created_at')->get(),
            'client_info' => $session->publicToken?->client_info,
            'external_ref' => $session->publicToken?->external_ref,
            'generated_at' => now(),
        ];

        $pdf = Pdf::loadView('pdf.session-recap', $data);

        // Sauvegarder le PDF
        $filename = "recaps/session_{$session->uuid}.pdf";
        Storage::put($filename, $pdf->output());

        return $filename;
    }

    /**
     * Génère un PDF de devis à partir d'une réponse JSON de l'IA
     */
    public function generateQuotePdf(
        AiSession $session,
        array $quoteData,
        array $options = []
    ): string {
        $session->load(['agent', 'publicToken']);

        $data = [
            'session' => $session,
            'quote' => $quoteData,
            'client_info' => $session->publicToken?->client_info ?? [],
            'company_info' => $options['company_info'] ?? [],
            'generated_at' => now(),
            'quote_number' => $options['quote_number'] ?? 'PRE-' . strtoupper(Str::random(8)),
            'validity_days' => $options['validity_days'] ?? 30,
        ];

        $pdf = Pdf::loadView('pdf.quote', $data);

        // Options PDF
        $pdf->setPaper('a4');
        $pdf->setOption('isHtml5ParserEnabled', true);

        // Sauvegarder
        $filename = "quotes/quote_{$session->uuid}.pdf";
        Storage::put($filename, $pdf->output());

        return $filename;
    }

    /**
     * Génère un PDF des photos du projet
     */
    public function generatePhotosPdf(AiSession $session): ?string
    {
        $photos = AiMessage::where('session_id', $session->id)
            ->whereNotNull('attachments')
            ->get()
            ->pluck('attachments')
            ->flatten(1)
            ->filter(fn($a) => str_starts_with($a['mime'] ?? '', 'image/'));

        if ($photos->isEmpty()) {
            return null;
        }

        $data = [
            'session' => $session,
            'photos' => $photos,
            'generated_at' => now(),
        ];

        $pdf = Pdf::loadView('pdf.photos', $data);
        $pdf->setPaper('a4', 'landscape');

        $filename = "photos/photos_{$session->uuid}.pdf";
        Storage::put($filename, $pdf->output());

        return $filename;
    }
}
```

---

## 16. Exemples de Prompts Système

### Agent BTP - Expert Devis (avec qualification et décomposition)

```markdown
Tu es un expert en bâtiment spécialisé dans l'établissement de devis.
Tu travailles pour un artisan et tu aides ses clients à définir leur projet.

## PHASE 1 - PRÉSENTATION
Présente-toi brièvement et explique que tu vas poser quelques questions pour comprendre le projet.

## PHASE 2 - QUALIFICATION (OBLIGATOIRE)
Avant de proposer un devis, tu DOIS collecter les informations suivantes :
- Nature exacte des travaux souhaités
- Dimensions / surfaces concernées
- Équipements spécifiques souhaités
- Niveau de gamme (standard, milieu de gamme, haut de gamme)
- Contraintes particulières (accès, délais, etc.)

Pose les questions une par une ou par petits groupes.
Si le client envoie des photos, décris ce que tu y vois et utilise ces informations.
Continue jusqu'à avoir suffisamment d'éléments.

## PHASE 3 - DÉCOMPOSITION
Une fois le besoin clair, décompose le projet en prestations élémentaires.
Pour chaque prestation, cherche dans les ouvrages disponibles.

Si tu as besoin de recherches supplémentaires, retourne :
```json
{
  "action": "search_additional",
  "queries": [
    "démolition carrelage salle de bain",
    "pose douche italienne receveur extra-plat",
    "carrelage grès cérame antidérapant"
  ]
}
```

## PHASE 4 - PROPOSITION DE DEVIS
Une fois toutes les prestations identifiées, génère un JSON structuré :
```json
{
  "phase": "devis",
  "resume_projet": "Description courte du projet",
  "prestations": [
    {
      "designation": "Démolition carrelage existant",
      "ouvrage_id": 123,
      "ouvrage_code": "DEM-CAR-001",
      "unite": "m²",
      "quantite": 8,
      "prix_unitaire": 25.00,
      "total": 200.00,
      "justification": "Sol et murs SDB"
    }
  ],
  "total_ht": 3500.00,
  "tva": 700.00,
  "total_ttc": 4200.00,
  "avertissement": "⚠️ Devis estimatif basé sur vos indications. Les quantités définitives seront confirmées après visite technique.",
  "validite_jours": 30
}
```

## RÈGLES IMPORTANTES
- Utilise UNIQUEMENT les ouvrages fournis en contexte
- Les quantités sont des ESTIMATIONS, précise-le toujours
- Sois courtois et professionnel
- Si tu ne trouves pas un ouvrage correspondant, indique "ouvrage non trouvé"
- Pose des questions si quelque chose n'est pas clair
```

### Agent Support Client (simple Q&A)

```markdown
Tu es l'assistant support de [Nom du logiciel].
Tu aides les utilisateurs à résoudre leurs problèmes et à utiliser le logiciel.

## TON RÔLE
- Répondre aux questions sur l'utilisation du logiciel
- Guider les utilisateurs pas à pas
- Résoudre les problèmes courants

## RÈGLES
- Sois concis et clair
- Utilise des listes numérotées pour les étapes
- Si tu ne sais pas, dis-le et suggère de contacter le support humain
- Base tes réponses UNIQUEMENT sur la documentation fournie

## FORMAT
Réponds en texte simple, avec des étapes numérotées si nécessaire.
```

### Configuration Agent BTP dans la base

```json
{
  "name": "Expert BTP",
  "slug": "expert-btp",
  "retrieval_mode": "SQL_HYDRATION",
  "hydration_config": {
    "table": "ouvrages",
    "key": "db_id",
    "fields": ["*"],
    "relations": ["fournitures", "main_oeuvres"]
  },
  "max_rag_results": 50,
  "allow_iterative_search": true,
  "response_format": "json",
  "allow_attachments": true,
  "allow_public_access": true,
  "default_token_expiry_hours": 168,
  "model": "llama3.1:70b",
  "temperature": 0.7
}
```

---

## Résumé

Ce document décrit l'architecture complète du moteur IA :

### Services Core
1. **OllamaService** : Communication avec le serveur d'inférence
2. **EmbeddingService** : Génération de vecteurs sémantiques
3. **QdrantService** : Stockage et recherche vectorielle
4. **HydrationService** : Enrichissement SQL des résultats
5. **PromptBuilder** : Construction des prompts structurés
6. **RagService** : Orchestration du pipeline RAG complet
7. **DispatcherService** : Point d'entrée et gestion des sessions
8. **LearningService** : Boucle d'amélioration continue

### Services Document
9. **DocumentService** : Upload, extraction et indexation de documents
10. **ChunkingService** : Découpage intelligent pour le RAG
11. **Extracteurs** : PDF, Audio/Vidéo (Whisper), OCR

### Services Métier
12. **PublicAccessService** : Génération de liens publics pour clients
13. **IterativeRagService** : RAG multi-requêtes pour agents complexes
14. **PdfGeneratorService** : Génération de PDF (récaps, devis)

### Caractéristiques
Le système est conçu pour être :
- **Modulaire** : Chaque service a une responsabilité unique
- **Configurable** : Tout est paramétrable via BDD ou config (par agent)
- **Extensible** : Facile d'ajouter de nouveaux agents ou modes
- **Flexible** : Chaque agent peut avoir son propre comportement (via prompt système)
- **Observable** : Logs et métriques à chaque étape

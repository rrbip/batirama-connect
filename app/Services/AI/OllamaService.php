<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\DTOs\AI\LLMResponse;
use App\Models\Agent;
use App\Services\AI\Contracts\LLMServiceInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        $requestedModel = $options['_requested_model'] ?? $model;
        $usedFallback = $options['_used_fallback'] ?? false;

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
                raw: $data,
                usedFallback: $usedFallback,
                requestedModel: $requestedModel
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
                    'fallback_model' => null,
                    '_requested_model' => $requestedModel,
                    '_used_fallback' => true,
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
            if (empty(trim($line))) {
                continue;
            }

            $data = json_decode($line, true);
            if (isset($data['response'])) {
                yield $data['response'];
            }

            if ($data['done'] ?? false) {
                break;
            }
        }
    }

    public function chat(array $messages, array $options = []): LLMResponse
    {
        $startTime = microtime(true);
        $model = $options['model'] ?? $this->defaultModel;
        $requestedModel = $options['_requested_model'] ?? $model;
        $usedFallback = $options['_used_fallback'] ?? false;

        $response = Http::timeout($this->timeout)
            ->post("{$this->baseUrl}/api/chat", [
                'model' => $model,
                'messages' => $messages,
                'stream' => false,
                'options' => [
                    'temperature' => $options['temperature'] ?? 0.7,
                    'num_predict' => $options['max_tokens'] ?? 2048,
                ],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("Ollama chat error: " . $response->body());
        }

        $data = $response->json();
        $generationTime = (int) ((microtime(true) - $startTime) * 1000);

        return new LLMResponse(
            content: $data['message']['content'] ?? '',
            model: $model,
            tokensPrompt: $data['prompt_eval_count'] ?? null,
            tokensCompletion: $data['eval_count'] ?? null,
            generationTimeMs: $generationTime,
            raw: $data,
            usedFallback: $usedFallback,
            requestedModel: $requestedModel
        );
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
        try {
            $response = Http::timeout(10)->get("{$this->baseUrl}/api/tags");

            if (!$response->successful()) {
                return [];
            }

            return collect($response->json('models', []))
                ->pluck('name')
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Liste les modèles avec leurs détails (taille, etc.)
     */
    public function listModelsWithDetails(): array
    {
        try {
            $response = Http::timeout(10)->get("{$this->baseUrl}/api/tags");

            if (!$response->successful()) {
                return [];
            }

            return collect($response->json('models', []))
                ->map(function ($model) {
                    return [
                        'name' => $model['name'] ?? '',
                        'size' => $model['size'] ?? 0,
                        'size_human' => $this->formatBytes($model['size'] ?? 0),
                        'modified_at' => $model['modified_at'] ?? null,
                        'digest' => $model['digest'] ?? null,
                        'details' => $model['details'] ?? [],
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to list models with details', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Supprime un modèle d'Ollama
     */
    public function deleteModel(string $model): bool
    {
        try {
            $response = Http::timeout(60)
                ->delete("{$this->baseUrl}/api/delete", [
                    'name' => $model,
                ]);

            if ($response->successful()) {
                Log::info('Model deleted successfully', ['model' => $model]);
                return true;
            }

            Log::error('Failed to delete model', [
                'model' => $model,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('Exception while deleting model', ['model' => $model, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Télécharge un modèle (avec timeout long pour les gros modèles)
     */
    public function pullModel(string $model): bool
    {
        try {
            $response = Http::timeout(1800) // 30 minutes pour les gros modèles
                ->post("{$this->baseUrl}/api/pull", [
                    'name' => $model,
                    'stream' => false,
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Failed to pull model', ['model' => $model, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Vérifie si un modèle existe localement
     */
    public function modelExists(string $model): bool
    {
        $models = $this->listModels();
        return in_array($model, $models, true);
    }

    /**
     * Formate les bytes en taille lisible
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Récupère une liste de modèles depuis une URL externe
     */
    public function fetchModelsFromUrl(string $url): ?array
    {
        try {
            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                Log::warning('Failed to fetch models list from URL', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json();

            // Valider le format
            if (!is_array($data)) {
                Log::warning('Invalid models list format from URL', ['url' => $url]);
                return null;
            }

            // S'assurer que chaque entrée a les champs requis
            $validatedModels = [];
            foreach ($data as $key => $model) {
                if (is_array($model) && isset($model['name'])) {
                    $validatedModels[$key] = [
                        'name' => $model['name'],
                        'size' => $model['size'] ?? 'Taille inconnue',
                        'type' => $model['type'] ?? 'chat',
                        'description' => $model['description'] ?? '',
                    ];
                }
            }

            return $validatedModels;
        } catch (\Exception $e) {
            Log::error('Exception fetching models from URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Récupère les modèles populaires depuis l'API Ollama (si disponible)
     * Note: L'API Ollama ne fournit pas de liste des modèles disponibles,
     * cette méthode essaie de récupérer des infos sur des modèles connus
     */
    public function fetchPopularModelsInfo(): array
    {
        $popularModels = [
            'llama3.2:1b', 'llama3.2:3b', 'llama3.1:8b', 'mistral:7b',
            'gemma2:2b', 'gemma2:9b', 'phi3:mini', 'qwen2.5:7b',
            'nomic-embed-text', 'codellama:7b',
        ];

        $modelsInfo = [];
        foreach ($popularModels as $modelName) {
            try {
                $response = Http::timeout(5)->post("{$this->baseUrl}/api/show", [
                    'name' => $modelName,
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $modelsInfo[$modelName] = [
                        'name' => $modelName,
                        'size' => isset($data['size']) ? $this->formatBytes($data['size']) : 'Taille variable',
                        'type' => str_contains($modelName, 'embed') ? 'embedding' :
                                 (str_contains($modelName, 'code') ? 'code' : 'chat'),
                        'description' => $data['modelfile'] ?? '',
                        'available' => true,
                    ];
                }
            } catch (\Exception $e) {
                // Modèle non disponible ou erreur, on continue
            }
        }

        return $modelsInfo;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}

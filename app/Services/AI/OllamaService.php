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
                    'fallback_model' => null
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
            raw: $data
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

    public function pullModel(string $model): bool
    {
        try {
            $response = Http::timeout(600)
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

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\DTOs\AI\LLMResponse;
use App\Models\Agent;
use App\Services\AI\Contracts\LLMServiceInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service pour l'API Google Gemini.
 *
 * Supporte les modèles Gemini 2.x (Flash, Pro) avec :
 * - Génération de texte
 * - Vision/multimodal (images natives)
 * - Streaming
 */
class GeminiService implements LLMServiceInterface
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private string $apiKey;
    private string $defaultModel;
    private int $timeout;

    public function __construct(
        string $apiKey,
        string $model = 'gemini-2.5-flash'
    ) {
        $this->apiKey = $apiKey;
        $this->defaultModel = $model;
        $this->timeout = config('ai.gemini.timeout', 120);
    }

    /**
     * Crée une instance pour un agent spécifique.
     */
    public static function forAgent(Agent $agent): self
    {
        return new self(
            apiKey: $agent->llm_api_key,
            model: $agent->llm_api_model ?? 'gemini-2.5-flash'
        );
    }

    /**
     * Génère une réponse à partir d'un prompt.
     */
    public function generate(string $prompt, array $options = []): LLMResponse
    {
        $startTime = microtime(true);
        $model = $options['model'] ?? $this->defaultModel;

        try {
            $requestBody = [
                'contents' => [
                    [
                        'parts' => $this->buildParts($prompt, $options),
                    ]
                ],
                'generationConfig' => [
                    'temperature' => $options['temperature'] ?? 0.7,
                    'maxOutputTokens' => $options['max_tokens'] ?? 2048,
                    'topP' => $options['top_p'] ?? 0.9,
                ],
            ];

            // Ajouter les safety settings si spécifiés
            if (isset($options['safety_settings'])) {
                $requestBody['safetySettings'] = $options['safety_settings'];
            }

            $response = Http::timeout($this->timeout)
                ->post($this->buildUrl($model, 'generateContent'), $requestBody);

            if (!$response->successful()) {
                $error = $response->json('error.message') ?? $response->body();
                Log::error('Gemini API error', [
                    'status' => $response->status(),
                    'error' => $error,
                    'model' => $model,
                ]);
                throw new \RuntimeException("Gemini API error: {$error}");
            }

            $data = $response->json();
            $generationTime = (int) ((microtime(true) - $startTime) * 1000);

            // Extraire le contenu de la réponse
            $content = $this->extractContent($data);

            // Extraire les metadata d'usage
            $usageMetadata = $data['usageMetadata'] ?? [];

            return new LLMResponse(
                content: $content,
                model: $model,
                tokensPrompt: $usageMetadata['promptTokenCount'] ?? null,
                tokensCompletion: $usageMetadata['candidatesTokenCount'] ?? null,
                generationTimeMs: $generationTime,
                raw: $data
            );

        } catch (ConnectionException $e) {
            Log::error('Gemini connection failed', [
                'error' => $e->getMessage(),
                'model' => $model,
            ]);
            throw $e;
        }
    }

    /**
     * Chat avec historique de messages (format Ollama compatible).
     *
     * @param array $messages Format: [["role" => "user|assistant|system", "content" => "..."], ...]
     */
    public function chat(array $messages, array $options = []): LLMResponse
    {
        $startTime = microtime(true);
        $model = $options['model'] ?? $this->defaultModel;

        try {
            // Extraire le system prompt si présent
            $systemInstruction = null;
            foreach ($messages as $msg) {
                if (($msg['role'] ?? '') === 'system') {
                    $systemInstruction = $msg['content'];
                    break;
                }
            }

            // Convertir les messages au format Gemini
            $contents = $this->convertMessagesToGeminiFormat($messages, $model, $systemInstruction);

            $requestBody = [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => $options['temperature'] ?? 0.7,
                    'maxOutputTokens' => $options['max_tokens'] ?? 2048,
                    'topP' => $options['top_p'] ?? 0.9,
                ],
            ];

            // Ajouter le system prompt si présent ET si le modèle le supporte
            // Les modèles Gemma ne supportent pas systemInstruction
            if ($systemInstruction && !$this->isGemmaModel($model)) {
                $requestBody['systemInstruction'] = [
                    'parts' => [['text' => $systemInstruction]]
                ];
            }

            $response = Http::timeout($this->timeout)
                ->post($this->buildUrl($model, 'generateContent'), $requestBody);

            if (!$response->successful()) {
                $error = $response->json('error.message') ?? $response->body();
                Log::error('Gemini chat error', [
                    'status' => $response->status(),
                    'error' => $error,
                    'model' => $model,
                ]);
                throw new \RuntimeException("Gemini chat error: {$error}");
            }

            $data = $response->json();
            $generationTime = (int) ((microtime(true) - $startTime) * 1000);
            $content = $this->extractContent($data);
            $usageMetadata = $data['usageMetadata'] ?? [];

            return new LLMResponse(
                content: $content,
                model: $model,
                tokensPrompt: $usageMetadata['promptTokenCount'] ?? null,
                tokensCompletion: $usageMetadata['candidatesTokenCount'] ?? null,
                generationTimeMs: $generationTime,
                raw: $data
            );

        } catch (ConnectionException $e) {
            Log::error('Gemini chat connection failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Convertit les messages du format Ollama vers le format Gemini.
     *
     * Pour les modèles Gemma qui ne supportent pas systemInstruction,
     * le system prompt est injecté comme premier message utilisateur.
     */
    private function convertMessagesToGeminiFormat(array $messages, string $model = '', ?string $systemInstruction = null): array
    {
        $contents = [];
        $isGemma = $this->isGemmaModel($model);

        // Pour Gemma, injecter le system prompt comme premier message user
        if ($isGemma && $systemInstruction) {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => "[Instructions système]\n{$systemInstruction}\n[Fin des instructions]"]],
            ];
            // Ajouter une réponse model vide pour maintenir l'alternance user/model
            $contents[] = [
                'role' => 'model',
                'parts' => [['text' => "Compris, je suivrai ces instructions."]],
            ];
        }

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';

            // Skip system messages (handled separately)
            if ($role === 'system') {
                continue;
            }

            // Gemini utilise 'model' au lieu de 'assistant'
            $geminiRole = $role === 'assistant' ? 'model' : 'user';

            $contents[] = [
                'role' => $geminiRole,
                'parts' => [['text' => $message['content'] ?? '']],
            ];
        }

        return $contents;
    }

    /**
     * Vérifie si le modèle est un modèle Gemma (ne supporte pas systemInstruction).
     */
    private function isGemmaModel(string $model): bool
    {
        return str_starts_with(strtolower($model), 'gemma');
    }

    /**
     * Génère une réponse en streaming.
     */
    public function generateStream(string $prompt, array $options = []): \Generator
    {
        $model = $options['model'] ?? $this->defaultModel;

        $requestBody = [
            'contents' => [
                [
                    'parts' => [['text' => $prompt]],
                ]
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.7,
                'maxOutputTokens' => $options['max_tokens'] ?? 2048,
            ],
        ];

        $response = Http::timeout($this->timeout)
            ->withOptions(['stream' => true])
            ->post($this->buildUrl($model, 'streamGenerateContent') . '&alt=sse', $requestBody);

        $body = $response->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $chunk = $body->read(1024);
            $buffer .= $chunk;

            // Parse SSE events
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                if (str_starts_with($event, 'data: ')) {
                    $jsonStr = substr($event, 6);
                    if ($jsonStr === '[DONE]') {
                        return;
                    }

                    $data = json_decode($jsonStr, true);
                    if ($data && isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                        yield $data['candidates'][0]['content']['parts'][0]['text'];
                    }
                }
            }
        }
    }

    /**
     * Vérifie la disponibilité du service.
     */
    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(10)
                ->get(self::BASE_URL . '/models', ['key' => $this->apiKey]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::warning('Gemini availability check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Liste les modèles disponibles.
     */
    public function listModels(): array
    {
        try {
            $response = Http::timeout(10)
                ->get(self::BASE_URL . '/models', ['key' => $this->apiKey]);

            if (!$response->successful()) {
                return $this->getDefaultModels();
            }

            $models = $response->json('models', []);

            return collect($models)
                ->filter(fn ($m) => str_contains($m['name'] ?? '', 'gemini'))
                ->pluck('name')
                ->map(fn ($name) => str_replace('models/', '', $name))
                ->toArray();

        } catch (\Exception $e) {
            return $this->getDefaultModels();
        }
    }

    /**
     * Génère une réponse avec image (vision).
     */
    public function generateWithImage(string $prompt, string $imagePath, array $options = []): LLMResponse
    {
        $imageData = base64_encode(file_get_contents($imagePath));
        $mimeType = mime_content_type($imagePath) ?: 'image/jpeg';

        return $this->generate($prompt, [
            ...$options,
            'image' => [
                'data' => $imageData,
                'mime_type' => $mimeType,
            ],
        ]);
    }

    /**
     * Génère une réponse avec image base64.
     */
    public function generateWithImageBase64(string $prompt, string $base64Data, string $mimeType, array $options = []): LLMResponse
    {
        return $this->generate($prompt, [
            ...$options,
            'image' => [
                'data' => $base64Data,
                'mime_type' => $mimeType,
            ],
        ]);
    }

    /**
     * Construit l'URL de l'API avec la clé.
     */
    private function buildUrl(string $model, string $method): string
    {
        return self::BASE_URL . "/models/{$model}:{$method}?key={$this->apiKey}";
    }

    /**
     * Construit les parts du message (texte + image optionnelle).
     */
    private function buildParts(string $prompt, array $options): array
    {
        $parts = [['text' => $prompt]];

        // Ajouter l'image si présente
        if (isset($options['image'])) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $options['image']['mime_type'],
                    'data' => $options['image']['data'],
                ],
            ];
        }

        return $parts;
    }

    /**
     * Extrait le contenu textuel de la réponse.
     */
    private function extractContent(array $data): string
    {
        // Vérifier si la génération a été bloquée
        if (isset($data['promptFeedback']['blockReason'])) {
            $reason = $data['promptFeedback']['blockReason'];
            Log::warning('Gemini response blocked', ['reason' => $reason]);
            return "[Réponse bloquée par les filtres de sécurité: {$reason}]";
        }

        // Extraire le texte des candidates
        $candidates = $data['candidates'] ?? [];
        if (empty($candidates)) {
            return '';
        }

        $parts = $candidates[0]['content']['parts'] ?? [];
        $textParts = array_filter($parts, fn ($p) => isset($p['text']));

        return implode('', array_column($textParts, 'text'));
    }

    /**
     * Retourne la liste des modèles par défaut.
     */
    private function getDefaultModels(): array
    {
        return [
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
            'gemini-2.5-pro',
            'gemini-2.0-flash',
            'gemini-1.5-flash',
            'gemini-1.5-pro',
        ];
    }
}

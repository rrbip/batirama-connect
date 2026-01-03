<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\LLMProvider;
use App\Models\Agent;
use App\Services\AI\Contracts\LLMServiceInterface;

/**
 * Factory pour créer le service LLM approprié selon la configuration de l'agent.
 */
class LLMServiceFactory
{
    /**
     * Crée le service LLM approprié pour un agent.
     *
     * Falls back to Ollama if:
     * - The configured provider requires an API key but none is set
     * - The provider is not supported
     */
    public static function forAgent(Agent $agent): OllamaService|GeminiService
    {
        $provider = $agent->getLLMProvider();

        // Check if provider requires API key and if it's available
        if ($provider->requiresApiKey() && empty($agent->llm_api_key)) {
            \Illuminate\Support\Facades\Log::warning('LLMServiceFactory: API key missing for provider, falling back to Ollama', [
                'agent' => $agent->slug,
                'provider' => $provider->value,
            ]);
            return OllamaService::forAgent($agent);
        }

        return match ($provider) {
            LLMProvider::GEMINI => GeminiService::forAgent($agent),
            LLMProvider::OPENAI => throw new \RuntimeException('OpenAI support coming soon'),
            default => OllamaService::forAgent($agent),
        };
    }

    /**
     * Crée un service Ollama avec configuration par défaut.
     */
    public static function defaultOllama(): OllamaService
    {
        return new OllamaService();
    }

    /**
     * Crée un service Gemini avec une clé API.
     */
    public static function gemini(string $apiKey, string $model = 'gemini-2.5-flash'): GeminiService
    {
        return new GeminiService($apiKey, $model);
    }

    /**
     * Vérifie si le provider d'un agent est disponible.
     */
    public static function isAvailable(Agent $agent): bool
    {
        try {
            $service = self::forAgent($agent);
            return $service->isAvailable();
        } catch (\Exception $e) {
            return false;
        }
    }
}

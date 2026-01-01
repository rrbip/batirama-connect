<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Providers LLM supportés pour les agents IA.
 *
 * Permet de configurer différents backends LLM par agent :
 * - Ollama (self-hosted, par défaut)
 * - Gemini API (Google, cloud)
 * - OpenAI API (futur)
 */
enum LLMProvider: string implements HasLabel
{
    case OLLAMA = 'ollama';
    case GEMINI = 'gemini';
    case OPENAI = 'openai';

    public function getLabel(): string
    {
        return match ($this) {
            self::OLLAMA => 'Ollama (Self-Hosted)',
            self::GEMINI => 'Google Gemini API',
            self::OPENAI => 'OpenAI API',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::OLLAMA => 'Serveur local Ollama - Gratuit, nécessite GPU/CPU dédié',
            self::GEMINI => 'API Google Gemini - 250 req/jour gratuit, puis payant',
            self::OPENAI => 'API OpenAI GPT - Payant uniquement',
        };
    }

    /**
     * Ce provider nécessite-t-il une clé API ?
     */
    public function requiresApiKey(): bool
    {
        return match ($this) {
            self::OLLAMA => false,
            self::GEMINI, self::OPENAI => true,
        };
    }

    /**
     * Modèle par défaut pour ce provider.
     */
    public function defaultModel(): string
    {
        return match ($this) {
            self::OLLAMA => 'mistral:7b',
            self::GEMINI => 'gemini-2.5-flash',
            self::OPENAI => 'gpt-4o-mini',
        };
    }

    /**
     * Liste des modèles disponibles pour ce provider.
     */
    public function availableModels(): array
    {
        return match ($this) {
            self::OLLAMA => [], // Dynamique, récupéré depuis le serveur
            self::GEMINI => [
                'gemini-2.5-flash' => 'Gemini 2.5 Flash (Recommandé)',
                'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash Lite (Plus rapide)',
                'gemini-2.5-pro' => 'Gemini 2.5 Pro (Plus puissant)',
                'gemini-2.0-flash' => 'Gemini 2.0 Flash (Legacy)',
            ],
            self::OPENAI => [
                'gpt-4o-mini' => 'GPT-4o Mini (Économique)',
                'gpt-4o' => 'GPT-4o (Performant)',
                'gpt-4-turbo' => 'GPT-4 Turbo',
            ],
        };
    }

    /**
     * Ce provider supporte-t-il la vision nativement ?
     */
    public function supportsVision(): bool
    {
        return match ($this) {
            self::OLLAMA => false, // Nécessite un modèle spécifique (llava)
            self::GEMINI => true,  // Multimodal natif
            self::OPENAI => true,  // GPT-4o supporte la vision
        };
    }

    /**
     * URL de base de l'API.
     */
    public function baseUrl(): ?string
    {
        return match ($this) {
            self::OLLAMA => null, // Configuré via ollama_host/port
            self::GEMINI => 'https://generativelanguage.googleapis.com/v1beta',
            self::OPENAI => 'https://api.openai.com/v1',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

use App\DTOs\AI\LLMResponse;

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

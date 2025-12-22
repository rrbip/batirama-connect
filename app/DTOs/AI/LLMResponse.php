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

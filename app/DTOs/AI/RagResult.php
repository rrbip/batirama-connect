<?php

declare(strict_types=1);

namespace App\DTOs\AI;

readonly class RagResult
{
    public function __construct(
        public string $id,
        public float $score,
        public string $content,
        public array $payload = [],
        public ?array $hydratedData = null
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'score' => $this->score,
            'content' => $this->content,
            'payload' => $this->payload,
            'hydrated_data' => $this->hydratedData,
        ];
    }
}

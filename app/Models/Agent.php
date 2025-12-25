<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'icon',
        'color',
        'system_prompt',
        'qdrant_collection',
        'retrieval_mode',
        'hydration_config',
        'ollama_host',
        'ollama_port',
        'model',
        'fallback_model',
        'context_window_size',
        'max_tokens',
        'temperature',
        'max_rag_results',
        'min_rag_score',
        'max_learned_responses',
        'learned_min_score',
        'context_token_limit',
        'strict_mode',
        'allow_iterative_search',
        'response_format',
        'allow_attachments',
        'allow_public_access',
        'default_token_expiry_hours',
        'is_active',
    ];

    protected $casts = [
        'hydration_config' => 'array',
        'temperature' => 'float',
        'min_rag_score' => 'float',
        'learned_min_score' => 'float',
        'is_active' => 'boolean',
        'allow_iterative_search' => 'boolean',
        'allow_attachments' => 'boolean',
        'allow_public_access' => 'boolean',
        'strict_mode' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AiSession::class);
    }

    public function promptVersions(): HasMany
    {
        return $this->hasMany(SystemPromptVersion::class);
    }

    public function publicAccessTokens(): HasMany
    {
        return $this->hasMany(PublicAccessToken::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function getOllamaUrl(): string
    {
        $host = $this->ollama_host ?? config('ai.ollama.host');
        $port = $this->ollama_port ?? config('ai.ollama.port');

        return "http://{$host}:{$port}";
    }

    public function getModel(): string
    {
        return $this->model ?? config('ai.ollama.default_model');
    }

    public function usesHydration(): bool
    {
        return $this->retrieval_mode === 'SQL_HYDRATION';
    }

    /**
     * Retourne le score minimum RAG (valeur agent ou config globale)
     */
    public function getMinRagScore(): float
    {
        return $this->min_rag_score ?? config('ai.rag.min_score', 0.5);
    }

    /**
     * Retourne le nombre max de réponses apprises (valeur agent ou config globale)
     */
    public function getMaxLearnedResponses(): int
    {
        return $this->max_learned_responses ?? config('ai.rag.max_learned_responses', 3);
    }

    /**
     * Retourne le score minimum pour les réponses apprises (valeur agent ou config globale)
     */
    public function getLearnedMinScore(): float
    {
        return $this->learned_min_score ?? config('ai.rag.learned_min_score', 0.75);
    }

    /**
     * Retourne la limite de tokens pour le contexte (valeur agent ou config globale)
     */
    public function getContextTokenLimit(): int
    {
        return $this->context_token_limit ?? config('ai.rag.context_size', 4000);
    }

    /**
     * Retourne les garde-fous à ajouter si strict_mode est activé
     */
    public function getStrictModeGuardrails(): string
    {
        if (!$this->strict_mode) {
            return '';
        }

        return <<<'GUARDRAILS'

## CONTRAINTES DE RÉPONSE (Mode Strict)

- Ne réponds QU'avec les informations présentes dans le contexte fourni
- Si l'information demandée n'est pas dans le contexte, indique clairement : "Je n'ai pas cette information dans ma base de connaissances"
- Ne fais JAMAIS d'hypothèses ou d'inventions sur des données chiffrées (prix, quantités, dimensions)
- Cite toujours la source de tes affirmations quand c'est pertinent
- Si plusieurs sources se contredisent, signale cette incohérence

GUARDRAILS;
    }
}

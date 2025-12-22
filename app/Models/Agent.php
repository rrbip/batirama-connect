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
        'is_active' => 'boolean',
        'allow_iterative_search' => 'boolean',
        'allow_attachments' => 'boolean',
        'allow_public_access' => 'boolean',
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
}

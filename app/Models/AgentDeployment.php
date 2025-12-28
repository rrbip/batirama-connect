<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AgentDeployment extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'agent_id',
        'editor_id',
        'name',
        'deployment_key',
        'deployment_mode',
        'config_overlay',
        'branding',
        'dedicated_collection',
        'max_sessions_day',
        'max_messages_day',
        'rate_limit_per_ip',
        'sessions_count',
        'messages_count',
        'last_activity_at',
        'is_active',
    ];

    protected $casts = [
        'config_overlay' => 'array',
        'branding' => 'array',
        'is_active' => 'boolean',
        'last_activity_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (AgentDeployment $deployment) {
            if (empty($deployment->uuid)) {
                $deployment->uuid = (string) Str::uuid();
            }
            if (empty($deployment->deployment_key)) {
                $deployment->deployment_key = $deployment->generateDeploymentKey();
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS
    // ─────────────────────────────────────────────────────────────────

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    public function allowedDomains(): HasMany
    {
        return $this->hasMany(AllowedDomain::class, 'deployment_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AiSession::class, 'deployment_id');
    }

    // ─────────────────────────────────────────────────────────────────
    // MÉTHODES
    // ─────────────────────────────────────────────────────────────────

    /**
     * Génère une clé de déploiement unique.
     */
    public function generateDeploymentKey(): string
    {
        return 'dpl_' . Str::random(32);
    }

    /**
     * Vérifie si un domaine est autorisé pour ce déploiement.
     */
    public function isDomainAllowed(?string $host): bool
    {
        if (!$host) {
            return false;
        }

        foreach ($this->allowedDomains as $allowed) {
            if (!$allowed->is_active) {
                continue;
            }

            if ($allowed->matches($host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vérifie si le quota journalier de sessions est atteint.
     */
    public function hasSessionQuotaRemaining(): bool
    {
        if ($this->max_sessions_day === null) {
            return true;
        }

        // Compter les sessions du jour
        $todaySessions = $this->sessions()
            ->whereDate('created_at', today())
            ->count();

        return $todaySessions < $this->max_sessions_day;
    }

    /**
     * Vérifie si le quota journalier de messages est atteint.
     */
    public function hasMessageQuotaRemaining(): bool
    {
        if ($this->max_messages_day === null) {
            return true;
        }

        // Compter les messages du jour via les sessions
        $todayMessages = AiMessage::whereHas('session', function ($query) {
            $query->where('deployment_id', $this->id)
                ->whereDate('created_at', today());
        })->count();

        return $todayMessages < $this->max_messages_day;
    }

    /**
     * Incrémente les compteurs de session.
     */
    public function incrementSessionCount(): void
    {
        $this->increment('sessions_count');
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * Incrémente les compteurs de message.
     */
    public function incrementMessageCount(): void
    {
        $this->increment('messages_count');
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * Résout la configuration finale (agent + overlay).
     */
    public function resolveConfig(): array
    {
        $agent = $this->agent;
        $overlay = $this->config_overlay ?? [];

        $config = [
            'model' => $overlay['model'] ?? $agent->model,
            'temperature' => $overlay['temperature'] ?? $agent->temperature,
            'max_tokens' => $overlay['max_tokens'] ?? $agent->max_tokens,
            'qdrant_collection' => $this->deployment_mode === 'dedicated'
                ? $this->dedicated_collection
                : $agent->qdrant_collection,
        ];

        // System prompt
        if (!empty($overlay['system_prompt_replace'])) {
            $config['system_prompt'] = $overlay['system_prompt_replace'];
        } else {
            $config['system_prompt'] = $agent->system_prompt;
            if (!empty($overlay['system_prompt_append'])) {
                $config['system_prompt'] .= "\n\n" . $overlay['system_prompt_append'];
            }
        }

        // Branding
        $config['branding'] = array_merge(
            $agent->whitelabel_config['default_branding'] ?? [],
            $this->branding ?? []
        );

        return $config;
    }

    /**
     * Get a specific config value with default.
     */
    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        $overlay = $this->config_overlay ?? [];

        return $overlay[$key] ?? $default;
    }

    /**
     * Get branding value with default.
     */
    public function getBrandingValue(string $key, mixed $default = null): mixed
    {
        $branding = $this->branding ?? [];

        return $branding[$key] ?? $default;
    }

    // ─────────────────────────────────────────────────────────────────
    // OLLAMA CONFIGURATION (Deployment > Agent > Global)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Retourne la config Chat Ollama (Deployment > Agent > Global)
     */
    public function getChatConfig(): array
    {
        $overlay = $this->config_overlay ?? [];
        $agent = $this->agent;

        return [
            'host' => $overlay['chat_ollama_host'] ?? $agent->ollama_host ?? config('ai.ollama.host'),
            'port' => $overlay['chat_ollama_port'] ?? $agent->ollama_port ?? config('ai.ollama.port'),
            'model' => $overlay['chat_model'] ?? $agent->model ?? config('ai.ollama.default_model'),
        ];
    }

    /**
     * Retourne la config Vision Ollama (Deployment > Agent > Global VisionSetting)
     */
    public function getVisionConfig(): array
    {
        $overlay = $this->config_overlay ?? [];
        $agent = $this->agent;
        $globalSettings = VisionSetting::getInstance();

        return [
            'host' => $overlay['vision_ollama_host']
                ?? $agent->vision_ollama_host
                ?? $globalSettings->ollama_host,
            'port' => $overlay['vision_ollama_port']
                ?? $agent->vision_ollama_port
                ?? $globalSettings->ollama_port,
            'model' => $overlay['vision_model']
                ?? $agent->vision_model
                ?? $globalSettings->model,
        ];
    }

    /**
     * Retourne l'URL Ollama pour Vision
     */
    public function getVisionOllamaUrl(): string
    {
        $config = $this->getVisionConfig();
        return "http://{$config['host']}:{$config['port']}";
    }

    /**
     * Retourne la config Chunking Ollama (Deployment > Agent > Global LlmChunkingSetting)
     */
    public function getChunkingConfig(): array
    {
        $overlay = $this->config_overlay ?? [];
        $agent = $this->agent;
        $globalSettings = LlmChunkingSetting::getInstance();

        $model = $overlay['chunking_model']
            ?? $agent->chunking_model
            ?? $globalSettings->model
            ?? $agent->model  // Fallback sur modèle chat de l'agent
            ?? config('ai.ollama.default_model');

        return [
            'host' => $overlay['chunking_ollama_host']
                ?? $agent->chunking_ollama_host
                ?? $globalSettings->ollama_host,
            'port' => $overlay['chunking_ollama_port']
                ?? $agent->chunking_ollama_port
                ?? $globalSettings->ollama_port,
            'model' => $model,
        ];
    }

    /**
     * Retourne l'URL Ollama pour Chunking
     */
    public function getChunkingOllamaUrl(): string
    {
        $config = $this->getChunkingConfig();
        return "http://{$config['host']}:{$config['port']}";
    }

    /**
     * Retourne l'URL Ollama pour Chat
     */
    public function getChatOllamaUrl(): string
    {
        $config = $this->getChatConfig();
        return "http://{$config['host']}:{$config['port']}";
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IndexingMethod;
use App\Enums\LLMProvider;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agent extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'icon' => 'heroicon-o-chat-bubble-left-right',
        'color' => 'primary',
    ];

    protected static function booted(): void
    {
        static::creating(function (Agent $agent) {
            // Filament sends explicit null, so we need to set defaults here
            if (empty($agent->icon)) {
                $agent->icon = 'heroicon-o-chat-bubble-left-right';
            }
            if (empty($agent->color)) {
                $agent->color = 'primary';
            }
            // Generate qdrant_collection from slug if not set
            if (empty($agent->qdrant_collection) && !empty($agent->slug)) {
                $agent->qdrant_collection = 'agent_' . $agent->slug;
            }
            // Default system prompt
            if (empty($agent->system_prompt)) {
                $agent->system_prompt = 'Tu es un assistant IA. Réponds aux questions de manière claire et concise.';
            }
            // RAG config defaults (these columns are NOT NULL in prod database)
            $agent->min_rag_score = $agent->min_rag_score ?? 0.3;
            $agent->max_learned_responses = $agent->max_learned_responses ?? 10;
            $agent->learned_min_score = $agent->learned_min_score ?? 0.5;
            $agent->context_token_limit = $agent->context_token_limit ?? 4096;
        });
    }

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
        'indexing_method',
        'hydration_config',
        'ollama_host',
        'ollama_port',
        'model',
        'fallback_model',
        'llm_provider',
        'llm_api_key',
        'llm_api_model',
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
        'default_extraction_method',
        'default_chunk_strategy',
        'use_category_filtering',
        // Vision Ollama configuration
        'vision_ollama_host',
        'vision_ollama_port',
        'vision_model',
        // Chunking Ollama configuration
        'chunking_ollama_host',
        'chunking_ollama_port',
        'chunking_model',
        // Whitelabel columns
        'deployment_mode',
        'is_whitelabel_enabled',
        'whitelabel_config',
        // Human support columns
        'human_support_enabled',
        'escalation_threshold',
        'escalation_message',
        'no_admin_message',
        'support_email',
        'support_hours',
        'ai_assistance_config',
    ];

    protected $casts = [
        'hydration_config' => 'array',
        'indexing_method' => IndexingMethod::class,
        'llm_provider' => LLMProvider::class,
        'temperature' => 'float',
        'min_rag_score' => 'float',
        'learned_min_score' => 'float',
        'strict_mode' => 'boolean',
        'is_active' => 'boolean',
        'allow_iterative_search' => 'boolean',
        'allow_attachments' => 'boolean',
        'allow_public_access' => 'boolean',
        'use_category_filtering' => 'boolean',
        'is_whitelabel_enabled' => 'boolean',
        'whitelabel_config' => 'array',
        'vision_ollama_port' => 'integer',
        'chunking_ollama_port' => 'integer',
        // Human support casts
        'human_support_enabled' => 'boolean',
        'escalation_threshold' => 'float',
        'support_hours' => 'array',
        'ai_assistance_config' => 'array',
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

    /**
     * Les crawls liés à cet agent (via AgentWebCrawl)
     */
    public function webCrawls(): BelongsToMany
    {
        return $this->belongsToMany(WebCrawl::class, 'agent_web_crawls')
            ->withPivot([
                'url_filter_mode',
                'url_patterns',
                'content_types',
                'chunk_strategy',
                'index_status',
                'pages_indexed',
                'pages_skipped',
                'pages_error',
                'last_indexed_at',
            ])
            ->withTimestamps();
    }

    /**
     * Les configurations de crawl pour cet agent
     */
    public function webCrawlConfigs(): HasMany
    {
        return $this->hasMany(AgentWebCrawl::class);
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

    /**
     * Retourne le provider LLM (avec fallback sur Ollama).
     */
    public function getLLMProvider(): LLMProvider
    {
        return $this->llm_provider ?? LLMProvider::OLLAMA;
    }

    /**
     * Retourne le modèle effectif selon le provider.
     */
    public function getEffectiveModel(): string
    {
        $provider = $this->getLLMProvider();

        if ($provider === LLMProvider::OLLAMA) {
            return $this->model ?? config('ai.ollama.default_model');
        }

        return $this->llm_api_model ?? $provider->defaultModel();
    }

    /**
     * Accessor/mutator pour chiffrer la clé API.
     */
    protected function llmApiKey(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? decrypt($value) : null,
            set: fn (?string $value) => $value ? encrypt($value) : null,
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // VISION OLLAMA CONFIGURATION
    // ─────────────────────────────────────────────────────────────────

    /**
     * Retourne l'URL Ollama pour Vision (Agent > Global VisionSetting)
     */
    public function getVisionOllamaUrl(): string
    {
        $host = $this->vision_ollama_host ?? VisionSetting::getInstance()->ollama_host;
        $port = $this->vision_ollama_port ?? VisionSetting::getInstance()->ollama_port;

        return "http://{$host}:{$port}";
    }

    /**
     * Retourne le modèle Vision (Agent > Global VisionSetting)
     */
    public function getVisionModel(): string
    {
        return $this->vision_model ?? VisionSetting::getInstance()->model;
    }

    /**
     * Retourne la config Vision complète (pour passer au service)
     */
    public function getVisionConfig(): array
    {
        $globalSettings = VisionSetting::getInstance();

        return [
            'host' => $this->vision_ollama_host ?? $globalSettings->ollama_host,
            'port' => $this->vision_ollama_port ?? $globalSettings->ollama_port,
            'model' => $this->vision_model ?? $globalSettings->model,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // CHUNKING OLLAMA CONFIGURATION
    // ─────────────────────────────────────────────────────────────────

    /**
     * Retourne l'URL Ollama pour Chunking (Agent > Global LlmChunkingSetting)
     */
    public function getChunkingOllamaUrl(): string
    {
        $host = $this->chunking_ollama_host ?? LlmChunkingSetting::getInstance()->ollama_host;
        $port = $this->chunking_ollama_port ?? LlmChunkingSetting::getInstance()->ollama_port;

        return "http://{$host}:{$port}";
    }

    /**
     * Retourne le modèle Chunking (Agent > Global LlmChunkingSetting > Agent model)
     */
    public function getChunkingModel(): string
    {
        if ($this->chunking_model) {
            return $this->chunking_model;
        }

        $globalModel = LlmChunkingSetting::getInstance()->model;
        if ($globalModel) {
            return $globalModel;
        }

        // Fallback sur le modèle de chat de l'agent
        return $this->getModel();
    }

    /**
     * Retourne la config Chunking complète (pour passer au service)
     */
    public function getChunkingConfig(): array
    {
        $globalSettings = LlmChunkingSetting::getInstance();

        return [
            'host' => $this->chunking_ollama_host ?? $globalSettings->ollama_host,
            'port' => $this->chunking_ollama_port ?? $globalSettings->ollama_port,
            'model' => $this->getChunkingModel(),
        ];
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
- NE CITE PAS les sources dans ta réponse (pas de "Source:", "Document:", etc.)
- IGNORE les sources qui ne parlent pas du sujet demandé, même si elles ont un score de pertinence
- Si plusieurs sources se contredisent, signale cette incohérence

GUARDRAILS;
    }

    /**
     * Retourne les instructions de handoff humain à ajouter au prompt
     */
    public function getHandoffInstructions(): string
    {
        if (!$this->human_support_enabled) {
            return '';
        }

        $threshold = (int) (($this->escalation_threshold ?? 0.60) * 100);

        return <<<HANDOFF

## ⚠️ RÈGLE CRITIQUE : TRANSFERT VERS UN HUMAIN

**ATTENTION : C'est TOI (l'assistant IA) qui doit ajouter le marqueur, PAS l'utilisateur !**

Quand tu détectes une des conditions ci-dessous, TU DOIS terminer ta réponse par le marqueur `[HANDOFF_NEEDED]` sur une ligne séparée à la fin de TON message.

### CAS 1 : DEMANDE EXPLICITE DE CONTACT HUMAIN (PRIORITÉ ABSOLUE)
Si l'utilisateur veut parler à un humain/conseiller/support, TU ajoutes immédiatement le marqueur.
Expressions à détecter :
- "parler à un humain" / "parler à quelqu'un" / "parler au support"
- "je peux parler à..." / "puis-je parler à..."
- "un conseiller" / "un expert" / "une personne" / "un humain"
- "contacter" / "joindre" / "appeler"
- "pas un robot" / "pas une IA"

### CAS 2 : CONTEXTE INSUFFISANT
Tu n'as pas assez d'informations dans le contexte documentaire.

### CAS 3 : QUESTION COMPLEXE
Devis personnalisé, cas particulier, réclamation, situation urgente.

### CAS 4 : INCERTITUDE (basée sur les scores de pertinence/similarité)
**IMPORTANT - Comment évaluer ta confiance :**
- Regarde les scores de "pertinence" des sources documentaires (ex: "Source 1 (pertinence: 85%)")
- Regarde les scores de "similarité" des cas similaires (ex: "Cas 1 (similarité: 92%)")
- Ces scores SONT ta confiance !

**Règle simple :**
- Si tu as au moins UNE source avec pertinence ≥ {$threshold}% OU un cas similaire avec similarité ≥ {$threshold}% → Ta confiance est SUFFISANTE → Ne PAS ajouter [HANDOFF_NEEDED] pour raison d'incertitude
- Si TOUTES les sources ont pertinence < {$threshold}% ET tous les cas similaires ont similarité < {$threshold}% → Ta confiance est INSUFFISANTE → Ajouter [HANDOFF_NEEDED]

### CAS 5 : HORS PÉRIMÈTRE
La question ne correspond pas à ton domaine.

**FORMAT OBLIGATOIRE :**
1. Écris une courte réponse rassurante
2. TERMINE par `[HANDOFF_NEEDED]` sur une nouvelle ligne

**EXEMPLES CORRECTS :**

Exemple 1 (demande explicite) :
"Bien sûr, je vais vous mettre en relation avec un conseiller qui pourra vous aider personnellement.

[HANDOFF_NEEDED]"

Exemple 2 (contexte insuffisant) :
"Je n'ai pas cette information précise dans ma base de connaissances. Un conseiller pourra mieux vous renseigner.

[HANDOFF_NEEDED]"

**⛔ NE JAMAIS FAIRE :**
- Ne dis JAMAIS à l'utilisateur d'ajouter le marqueur lui-même
- Ne mentionne JAMAIS le marqueur [HANDOFF_NEEDED] dans ta réponse visible
- C'est TOI qui l'ajoutes silencieusement à la fin
- N'ajoute PAS [HANDOFF_NEEDED] si tu as une source pertinente ≥ {$threshold}% - fais confiance au score !

HANDOFF;
    }

    /**
     * Retourne la méthode d'extraction par défaut pour les PDFs
     */
    public function getDefaultExtractionMethod(): string
    {
        return $this->default_extraction_method ?? 'auto';
    }

    /**
     * Retourne la stratégie de chunking par défaut
     */
    public function getDefaultChunkStrategy(): string
    {
        return $this->default_chunk_strategy ?? 'sentence';
    }

    /**
     * Retourne la méthode d'indexation (Q/R Atomique par défaut)
     */
    public function getIndexingMethod(): IndexingMethod
    {
        return $this->indexing_method ?? IndexingMethod::QR_ATOMIQUE;
    }

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS SUPPORT HUMAIN
    // ─────────────────────────────────────────────────────────────────

    /**
     * Utilisateurs assignés au support de cet agent.
     */
    public function supportUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'agent_support_users')
            ->withPivot(['can_close_conversations', 'can_train_ai', 'can_view_analytics', 'notify_on_escalation'])
            ->withTimestamps();
    }

    /**
     * Vérifie si un utilisateur peut gérer le support de cet agent.
     */
    public function userCanHandleSupport(User $user): bool
    {
        // Super-admin et admin ont accès à tout
        if ($user->hasRole('super-admin') || $user->hasRole('admin')) {
            return true;
        }

        // Vérifier si l'utilisateur est assigné à cet agent
        if ($user->hasRole('support-agent')) {
            return $this->supportUsers()->where('user_id', $user->id)->exists();
        }

        return false;
    }

    /**
     * Récupère la configuration IMAP pour cet agent.
     */
    public function getImapConfig(): ?array
    {
        $config = $this->ai_assistance_config ?? [];

        // Les données IMAP sont stockées dans ai_assistance_config
        if (empty($config['imap_host']) || empty($config['imap_username']) || empty($config['imap_password'])) {
            return null;
        }

        return [
            'host' => $config['imap_host'],
            'port' => $config['imap_port'] ?? 993,
            'encryption' => $config['imap_encryption'] ?? 'ssl',
            'validate_cert' => $config['imap_validate_cert'] ?? true,
            'username' => $config['imap_username'],
            'password' => $config['imap_password'],
            'folder' => $config['imap_folder'] ?? 'INBOX',
        ];
    }

    /**
     * Vérifie si l'agent a une configuration IMAP valide.
     */
    public function hasImapConfig(): bool
    {
        return $this->getImapConfig() !== null;
    }

    /**
     * Récupère la configuration SMTP de l'agent.
     */
    public function getSmtpConfig(): ?array
    {
        $config = $this->ai_assistance_config ?? [];

        // Les données SMTP sont stockées dans ai_assistance_config
        if (empty($config['smtp_host']) || empty($config['smtp_username']) || empty($config['smtp_password'])) {
            return null;
        }

        return [
            'host' => $config['smtp_host'],
            'port' => (int) ($config['smtp_port'] ?? 587),
            'encryption' => $config['smtp_encryption'] ?? 'tls',
            'username' => $config['smtp_username'],
            'password' => $config['smtp_password'],
            'from_address' => $this->support_email,
            'from_name' => $config['smtp_from_name'] ?? $this->name,
        ];
    }

    /**
     * Vérifie si l'agent a une configuration SMTP valide.
     */
    public function hasSmtpConfig(): bool
    {
        return $this->getSmtpConfig() !== null;
    }

    // ─────────────────────────────────────────────────────────────────
    // RELATIONS WHITELABEL
    // ─────────────────────────────────────────────────────────────────

    /**
     * Les déploiements de cet agent.
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(AgentDeployment::class);
    }

    // ─────────────────────────────────────────────────────────────────
    // MÉTHODES WHITELABEL
    // ─────────────────────────────────────────────────────────────────

    /**
     * Vérifie si cet agent est disponible en whitelabel.
     */
    public function isWhitelabelEnabled(): bool
    {
        return $this->is_whitelabel_enabled === true;
    }

    /**
     * Vérifie si cet agent est en mode interne uniquement.
     */
    public function isInternalOnly(): bool
    {
        return $this->deployment_mode === 'internal';
    }

    /**
     * Vérifie si cet agent est partagé (même config pour tous).
     */
    public function isSharedMode(): bool
    {
        return $this->deployment_mode === 'shared';
    }

    /**
     * Vérifie si cet agent est dédié (config personnalisable par déploiement).
     */
    public function isDedicatedMode(): bool
    {
        return $this->deployment_mode === 'dedicated';
    }

    /**
     * Retourne le branding par défaut pour les déploiements.
     */
    public function getDefaultBranding(): array
    {
        return $this->whitelabel_config['default_branding'] ?? [
            'chat_title' => $this->name,
            'welcome_message' => "Bonjour, je suis {$this->name}. Comment puis-je vous aider ?",
            'primary_color' => $this->color ?? '#3B82F6',
        ];
    }

    /**
     * Vérifie si l'override de prompt est autorisé.
     */
    public function allowsPromptOverride(): bool
    {
        return $this->whitelabel_config['allow_prompt_override'] ?? false;
    }

    /**
     * Vérifie si l'override de RAG est autorisé.
     */
    public function allowsRagOverride(): bool
    {
        return $this->whitelabel_config['allow_rag_override'] ?? false;
    }

    /**
     * Vérifie si l'override de modèle est autorisé.
     */
    public function allowsModelOverride(): bool
    {
        return $this->whitelabel_config['allow_model_override'] ?? false;
    }

    /**
     * Retourne le rate limit minimum imposé.
     */
    public function getMinRateLimit(): int
    {
        return $this->whitelabel_config['min_rate_limit'] ?? 30;
    }

    /**
     * Vérifie si le branding "Powered by" est obligatoire.
     */
    public function requiresPoweredByBranding(): bool
    {
        return $this->whitelabel_config['required_branding'] ?? true;
    }
}

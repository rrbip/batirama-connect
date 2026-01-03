<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Liste configurable pour les données de référence système.
 *
 * Permet de gérer des listes clé-valeur dynamiques (modèles LLM, modes de paiement, etc.)
 * sans modifier le code source.
 */
class ConfigurableList extends Model
{
    // Catégories prédéfinies
    public const CATEGORY_AI = 'ai';
    public const CATEGORY_MARKETPLACE = 'marketplace';
    public const CATEGORY_GENERAL = 'general';

    // Clés système
    public const KEY_GEMINI_MODELS = 'gemini_models';
    public const KEY_OPENAI_MODELS = 'openai_models';
    public const KEY_OLLAMA_MODELS = 'ollama_models';
    public const KEY_SKIP_REASONS = 'accelerated_learning_skip_reasons';

    protected $fillable = [
        'key',
        'name',
        'description',
        'category',
        'data',
        'is_system',
    ];

    protected $casts = [
        'data' => 'array',
        'is_system' => 'boolean',
    ];

    /**
     * Récupère une liste par sa clé avec mise en cache.
     */
    public static function getByKey(string $key, array $default = []): array
    {
        return Cache::remember(
            "configurable_list.{$key}",
            now()->addHours(1),
            fn () => static::where('key', $key)->first()?->data ?? $default
        );
    }

    /**
     * Récupère les options pour un Select Filament.
     */
    public static function getOptionsForSelect(string $key, array $default = []): array
    {
        return static::getByKey($key, $default);
    }

    /**
     * Met à jour une liste et invalide le cache.
     */
    public static function setByKey(string $key, array $data): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['data' => $data]
        );

        Cache::forget("configurable_list.{$key}");
    }

    /**
     * Récupère toutes les listes groupées par catégorie.
     */
    public static function getAllGroupedByCategory(): array
    {
        return static::all()
            ->groupBy('category')
            ->map(fn ($lists) => $lists->pluck('name', 'key'))
            ->toArray();
    }

    /**
     * Récupère les catégories disponibles avec leurs labels.
     */
    public static function getCategoryLabels(): array
    {
        return [
            self::CATEGORY_AI => 'Intelligence Artificielle',
            self::CATEGORY_MARKETPLACE => 'Marketplace',
            self::CATEGORY_GENERAL => 'Général',
        ];
    }

    /**
     * Invalide le cache pour cette liste.
     */
    public function clearCache(): void
    {
        Cache::forget("configurable_list.{$this->key}");
    }

    /**
     * Boot du modèle - invalide le cache à chaque modification.
     */
    protected static function booted(): void
    {
        static::saved(function (ConfigurableList $list) {
            $list->clearCache();
        });

        static::deleted(function (ConfigurableList $list) {
            $list->clearCache();
        });
    }

    /**
     * Retourne les données par défaut pour les listes système.
     */
    public static function getDefaultData(string $key): array
    {
        return match ($key) {
            self::KEY_GEMINI_MODELS => [
                'gemini-2.5-flash' => 'Gemini 2.5 Flash (Recommandé)',
                'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash Lite (Plus rapide)',
                'gemini-2.5-pro' => 'Gemini 2.5 Pro (Plus puissant)',
                'gemini-2.0-flash' => 'Gemini 2.0 Flash',
                'gemma-3-27b-it' => 'Gemma 3 27B (14K RPD)',
            ],
            self::KEY_OPENAI_MODELS => [
                'gpt-4o-mini' => 'GPT-4o Mini (Économique)',
                'gpt-4o' => 'GPT-4o (Performant)',
                'gpt-4-turbo' => 'GPT-4 Turbo',
            ],
            self::KEY_SKIP_REASONS => [
                'hors_sujet' => 'Question hors sujet',
                'deja_traite' => 'Déjà traité ailleurs',
                'pas_prioritaire' => 'Pas prioritaire',
                'doublon' => 'Question en doublon',
            ],
            default => [],
        };
    }
}

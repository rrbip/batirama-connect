<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisionSetting extends Model
{
    protected $fillable = [
        'model',
        'ollama_host',
        'ollama_port',
        'temperature',
        'timeout_seconds',
        'system_prompt',
        'image_dpi',
        'output_format',
        'max_pages',
        'store_images',
        'store_markdown',
        'storage_disk',
        'storage_path',
        'system_requirements',
    ];

    protected $casts = [
        'temperature' => 'float',
        'ollama_port' => 'integer',
        'image_dpi' => 'integer',
        'max_pages' => 'integer',
        'timeout_seconds' => 'integer',
        'store_images' => 'boolean',
        'store_markdown' => 'boolean',
        'system_requirements' => 'array',
    ];

    /**
     * Modèles vision disponibles avec leurs caractéristiques
     */
    public const AVAILABLE_MODELS = [
        'moondream' => [
            'name' => 'Moondream2',
            'size' => '1.8B',
            'vram' => '2 GB',
            'cpu_compatible' => true,
            'quality' => 'basic',
            'speed_cpu' => '10-30s/page',
            'speed_gpu' => '2-5s/page',
            'description' => 'Modèle léger, fonctionne sur CPU. Bon pour texte simple, limité sur tableaux complexes.',
        ],
        'llava:7b' => [
            'name' => 'LLaVA 7B',
            'size' => '7B',
            'vram' => '6 GB',
            'cpu_compatible' => false,
            'quality' => 'good',
            'speed_cpu' => '1-3min/page',
            'speed_gpu' => '5-10s/page',
            'description' => 'Bon équilibre qualité/performance. Nécessite GPU.',
        ],
        'llama3.2-vision' => [
            'name' => 'Llama 3.2 Vision',
            'size' => '11B',
            'vram' => '8 GB',
            'cpu_compatible' => false,
            'quality' => 'excellent',
            'speed_cpu' => '2-5min/page',
            'speed_gpu' => '10-20s/page',
            'description' => 'Meilleure qualité, excellent sur tableaux. Nécessite GPU 8GB+.',
        ],
        'llava:13b' => [
            'name' => 'LLaVA 13B',
            'size' => '13B',
            'vram' => '10 GB',
            'cpu_compatible' => false,
            'quality' => 'excellent',
            'speed_cpu' => 'Non recommandé',
            'speed_gpu' => '15-30s/page',
            'description' => 'Haute qualité, nécessite GPU 10GB+.',
        ],
    ];

    /**
     * Récupère l'instance singleton des settings
     */
    public static function getInstance(): self
    {
        $settings = static::first();

        if (!$settings) {
            $settings = static::create([
                'system_prompt' => static::getDefaultPrompt(),
                'system_requirements' => static::getSystemRequirements(),
            ]);
        }

        return $settings;
    }

    /**
     * Retourne le modèle à utiliser
     * Priorité: settings explicite > modèle de l'agent > config par défaut
     */
    public function getModelFor(?Agent $agent = null): string
    {
        if (!empty($this->model)) {
            return $this->model;
        }

        if ($agent && !empty($agent->model)) {
            return $agent->model;
        }

        return config('ai.ollama.default_model', 'llama3.2-vision:11b');
    }

    /**
     * Retourne l'URL complète d'Ollama
     */
    public function getOllamaUrl(): string
    {
        return "http://{$this->ollama_host}:{$this->ollama_port}";
    }

    /**
     * Vérifie si le modèle configuré est compatible CPU
     */
    public function isCpuCompatible(): bool
    {
        $modelInfo = self::AVAILABLE_MODELS[$this->model] ?? null;
        return $modelInfo['cpu_compatible'] ?? false;
    }

    /**
     * Retourne les infos du modèle configuré
     */
    public function getModelInfo(): ?array
    {
        return self::AVAILABLE_MODELS[$this->model] ?? null;
    }

    /**
     * Retourne le chemin de stockage complet
     */
    public function getStoragePath(string $documentId): string
    {
        return "{$this->storage_path}/{$documentId}";
    }

    /**
     * Génère les informations système requises
     */
    public static function getSystemRequirements(): array
    {
        return [
            'dependencies' => [
                [
                    'name' => 'ImageMagick ou Poppler',
                    'command' => 'convert ou pdftoppm',
                    'purpose' => 'Conversion PDF vers images',
                    'install' => 'apt install imagemagick poppler-utils',
                ],
                [
                    'name' => 'Ollama',
                    'command' => 'ollama',
                    'purpose' => 'Serveur de modèles IA',
                    'install' => 'curl -fsSL https://ollama.com/install.sh | sh',
                ],
            ],
            'models' => [
                [
                    'name' => 'moondream',
                    'command' => 'ollama pull moondream',
                    'size' => '1.7 GB',
                    'recommended_for' => 'Développement / CPU only',
                ],
                [
                    'name' => 'llava:7b',
                    'command' => 'ollama pull llava:7b',
                    'size' => '4.7 GB',
                    'recommended_for' => 'Production avec GPU 6GB',
                ],
                [
                    'name' => 'llama3.2-vision',
                    'command' => 'ollama pull llama3.2-vision',
                    'size' => '7.9 GB',
                    'recommended_for' => 'Production avec GPU 8GB+',
                ],
            ],
            'hardware' => [
                'cpu_only' => [
                    'model' => 'moondream',
                    'ram' => '8 GB minimum',
                    'performance' => '10-30 secondes par page',
                ],
                'gpu_recommended' => [
                    'model' => 'llama3.2-vision',
                    'vram' => '8 GB minimum',
                    'performance' => '10-20 secondes par page',
                ],
            ],
        ];
    }

    /**
     * Prompt par défaut pour l'extraction
     */
    public static function getDefaultPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en extraction de documents techniques. Analyse cette image de document et extrais son contenu en Markdown.

# Règles d'extraction

1. **Préserve la structure** : Titres, sous-titres, paragraphes, listes
2. **Tableaux** : Utilise le format Markdown avec | pour les colonnes
   - Préserve TOUTES les lignes et colonnes
   - Ne résume jamais un tableau
3. **Données techniques** : Reproduis exactement les valeurs numériques (dimensions, poids, résistances, etc.)
4. **Formules** : Utilise le format LaTeX si possible ($formule$)
5. **Mise en page** : Ignore les éléments décoratifs (logos, bordures, numéros de page)

# Format de sortie

Réponds UNIQUEMENT avec le contenu Markdown extrait, sans commentaires ni explications.

Exemple de tableau extrait :
| Produit | Épaisseur | Largeur | Poids |
|---------|-----------|---------|-------|
| BA13 | 12,5 mm | 1200 mm | 8,5 kg/m² |
| BA15 | 15 mm | 1200 mm | 11 kg/m² |
PROMPT;
    }

    /**
     * Options pour le select Filament
     */
    public static function getModelOptions(): array
    {
        $options = [];
        foreach (self::AVAILABLE_MODELS as $key => $model) {
            $cpuTag = $model['cpu_compatible'] ? ' [CPU OK]' : ' [GPU requis]';
            $options[$key] = "{$model['name']} ({$model['size']}){$cpuTag}";
        }
        return $options;
    }

    /**
     * Vérifie si le service Ollama est accessible
     */
    public function checkOllamaConnection(): array
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->get($this->getOllamaUrl() . '/api/tags');

            if ($response->successful()) {
                $models = collect($response->json('models', []))
                    ->pluck('name')
                    ->toArray();

                $hasConfiguredModel = in_array($this->model, $models) ||
                    in_array($this->model . ':latest', $models);

                return [
                    'connected' => true,
                    'models_available' => $models,
                    'configured_model_installed' => $hasConfiguredModel,
                ];
            }

            return [
                'connected' => false,
                'error' => 'Ollama responded with status ' . $response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}

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
    ];

    protected $casts = [
        'temperature' => 'float',
        'ollama_port' => 'integer',
        'timeout_seconds' => 'integer',
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
     * Prompt par défaut pour extraction vision
     */
    public static function getDefaultPrompt(): string
    {
        return <<<'PROMPT'
Analyse cette image et extrait son contenu textuel en Markdown structuré.

RÈGLES:
1. Préserve la hiérarchie des titres (# ## ### etc.)
2. Préserve les listes et tableaux
3. Ignore les éléments de navigation et décoration
4. Retourne UNIQUEMENT le contenu Markdown, pas d'explication

MARKDOWN:
PROMPT;
    }
}

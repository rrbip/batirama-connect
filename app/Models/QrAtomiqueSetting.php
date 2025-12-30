<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QrAtomiqueSetting extends Model
{
    protected $fillable = [
        'model',
        'ollama_host',
        'ollama_port',
        'temperature',
        'threshold',
        'timeout_seconds',
        'system_prompt',
    ];

    protected $casts = [
        'temperature' => 'float',
        'ollama_port' => 'integer',
        'threshold' => 'integer',
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

        return config('ai.ollama.default_model', 'mistral:7b');
    }

    /**
     * Retourne l'URL complète d'Ollama
     */
    public function getOllamaUrl(): string
    {
        return "http://{$this->ollama_host}:{$this->ollama_port}";
    }

    /**
     * Construit le prompt avec les catégories
     */
    public function buildPrompt(string $content, ?string $parentContext = null): string
    {
        $categories = DocumentCategory::orderBy('name')->pluck('name')->toArray();
        $categoriesList = !empty($categories)
            ? implode(', ', $categories)
            : 'Aucune catégorie existante';

        $contextInfo = $parentContext
            ? "Contexte du document: {$parentContext}\n\n"
            : '';

        return str_replace(
            ['{CONTEXT}', '{CATEGORIES}', '{CONTENT}'],
            [$contextInfo, $categoriesList, $content],
            $this->system_prompt
        );
    }

    /**
     * Prompt par défaut pour génération Q/R
     */
    public static function getDefaultPrompt(): string
    {
        return <<<'PROMPT'
{CONTEXT}Analyse le texte suivant et génère des paires Question/Réponse.

RÈGLES IMPORTANTES:
1. RÉPONDS TOUJOURS EN FRANÇAIS - toutes les questions, réponses et résumés doivent être en français
2. La réponse doit être AUTONOME et ne JAMAIS faire référence au texte source (ne pas dire "Comme indiqué dans le document", "Le texte mentionne", etc.)
3. La réponse doit être directe et complète, comme si tu répondais à un utilisateur
4. Si le texte n'a aucune valeur informative (copyright, navigation, etc.), réponds avec "useful": false
5. Choisis une catégorie parmi les existantes ou proposes-en une nouvelle si nécessaire

Catégories existantes: {CATEGORIES}

TEXTE À ANALYSER:
{CONTENT}

RÉPONDS UNIQUEMENT EN FRANÇAIS avec un JSON valide au format suivant:
{
  "useful": true,
  "category": "NOM_CATEGORIE",
  "knowledge_units": [
    {
      "question": "Question claire et précise ?",
      "answer": "Réponse autonome et complète."
    }
  ],
  "summary": "Résumé en une phrase du contenu.",
  "raw_content_clean": "Texte nettoyé..."
}
PROMPT;
    }
}

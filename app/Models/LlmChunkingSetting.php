<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlmChunkingSetting extends Model
{
    protected $fillable = [
        'model',
        'ollama_host',
        'ollama_port',
        'temperature',
        'window_size',
        'overlap_percent',
        'max_retries',
        'timeout_seconds',
        'system_prompt',
    ];

    protected $casts = [
        'temperature' => 'float',
        'window_size' => 'integer',
        'overlap_percent' => 'integer',
        'max_retries' => 'integer',
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
     * Retourne le modèle à utiliser (celui configuré ou celui de l'agent)
     */
    public function getModelFor(?Agent $agent = null): string
    {
        if (!empty($this->model)) {
            return $this->model;
        }

        if ($agent && !empty($agent->model)) {
            return $agent->model;
        }

        return 'llama3.2';
    }

    /**
     * Retourne l'URL complète d'Ollama
     */
    public function getOllamaUrl(): string
    {
        return "http://{$this->ollama_host}:{$this->ollama_port}";
    }

    /**
     * Calcule le nombre de tokens d'overlap
     */
    public function getOverlapTokens(): int
    {
        return (int) ($this->window_size * $this->overlap_percent / 100);
    }

    /**
     * Construit le prompt complet avec les catégories
     */
    public function buildPrompt(string $inputText): string
    {
        $categories = DocumentCategory::getListForPrompt();

        $prompt = str_replace(
            ['{CATEGORIES}', '{INPUT_TEXT}'],
            [$categories, $inputText],
            $this->system_prompt
        );

        return $prompt;
    }

    /**
     * Prompt par défaut
     */
    public static function getDefaultPrompt(): string
    {
        return <<<'PROMPT'
# Rôle
Tu es un expert en structuration de connaissances pour des bases de données vectorielles (RAG).

# Tâche
Analyse le texte fourni et découpe-le en chunks sémantiques autonomes.

# Règles STRICTES

1. **Unicité du sujet** : Chaque chunk doit porter sur un concept, une action ou une idée unique.

2. **Autonomie (CRUCIAL)** : Réécris les pronoms et références implicites.
   - MAUVAIS : "Il a validé le budget."
   - BON : "Le Directeur Financier a validé le budget marketing 2024."
   - Le chunk doit être compréhensible SEUL, sans contexte.

3. **Fidélité** : Ne modifie JAMAIS les faits, chiffres ou le sens. Ajoute uniquement le contexte nécessaire.

4. **Taille** : Vise des chunks de 3 à 6 phrases.

5. **Catégorie** : Choisis la catégorie la plus pertinente parmi la liste fournie. Si aucune ne convient, propose une nouvelle catégorie dans "new_categories".

# Catégories disponibles
{CATEGORIES}

# Format de sortie
Réponds UNIQUEMENT avec un JSON valide, sans texte avant ni après.

```json
{
  "chunks": [
    {
      "content": "Le texte du chunk réécrit et autonome...",
      "keywords": ["mot-clé1", "mot-clé2", "mot-clé3"],
      "summary": "Résumé en une phrase courte.",
      "category": "Nom de la catégorie"
    }
  ],
  "new_categories": [
    {
      "name": "Nouvelle Catégorie",
      "description": "Description courte de cette catégorie"
    }
  ]
}
```

# Texte à traiter
{INPUT_TEXT}
PROMPT;
    }
}

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
     * Priorité: settings explicite > modèle de l'agent > config par défaut
     */
    public function getModelFor(?Agent $agent = null): string
    {
        // 1. Modèle explicitement configuré dans les settings LLM Chunking
        if (!empty($this->model)) {
            return $this->model;
        }

        // 2. Modèle de l'agent associé au document
        if ($agent && !empty($agent->model)) {
            return $agent->model;
        }

        // 3. Modèle par défaut de la config Ollama
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

3. **Fidélité ABSOLUE** :
   - Ne modifie JAMAIS les faits, chiffres, valeurs techniques ou le sens
   - PRÉSERVE INTÉGRALEMENT tous les tableaux, données chiffrées et spécifications techniques
   - Les valeurs numériques (dimensions, résistances, épaisseurs, poids, etc.) doivent être reproduites EXACTEMENT

4. **Tableaux et données techniques** :
   - Les tableaux doivent être préservés dans leur intégralité dans un seul chunk
   - Ne résume PAS les lignes d'un tableau - garde TOUTES les lignes
   - Format texte pour les tableaux : utilise des séparateurs clairs (| ou tabulations)
   - Un tableau = un chunk (ne pas découper un tableau en plusieurs chunks)

5. **Taille** : Adapte la taille selon le contenu
   - Texte descriptif : chunks de 4 à 10 phrases
   - Tableau ou liste technique : inclure l'INTÉGRALITÉ même si plus long
   - Ne JAMAIS tronquer du contenu technique

6. **Catégorie** : Choisis la catégorie la plus pertinente parmi la liste fournie. Si aucune ne convient, propose une nouvelle catégorie dans "new_categories".

# Catégories disponibles
{CATEGORIES}

# Format de sortie JSON - RESPECTE EXACTEMENT CE FORMAT

ATTENTION: Utilise EXACTEMENT ces noms de clés (en anglais) :
- "content" (pas "contenu")
- "keywords" (pas "tags", pas "mots_cles")
- "summary" (pas "resume", pas "résumé")
- "category" (pas "categorie", pas "catégorie")

Réponds UNIQUEMENT avec un JSON valide, sans texte avant ni après.

{
  "chunks": [
    {
      "content": "Le texte du chunk réécrit et autonome...",
      "keywords": ["mot-clé1", "mot-clé2", "mot-clé3"],
      "summary": "Résumé en une phrase courte.",
      "category": "Nom de la catégorie"
    }
  ],
  "new_categories": []
}

# Exemple avec tableau technique

{
  "chunks": [
    {
      "content": "Plaque Knauf BA13 - Caractéristiques techniques :\n| Épaisseur | Largeur | Longueur | Poids |\n| 12,5 mm | 1200 mm | 2500 mm | 8,5 kg/m² |\n| 12,5 mm | 1200 mm | 2600 mm | 8,5 kg/m² |\n| 12,5 mm | 1200 mm | 3000 mm | 8,5 kg/m² |\nRésistance thermique R = 0,05 m².K/W. Classement au feu : A2-s1, d0.",
      "keywords": ["BA13", "plaque plâtre", "dimensions", "poids", "résistance thermique"],
      "summary": "Caractéristiques techniques complètes de la plaque Knauf BA13.",
      "category": "Fiches techniques"
    }
  ],
  "new_categories": []
}

# Texte à traiter
{INPUT_TEXT}
PROMPT;
    }
}

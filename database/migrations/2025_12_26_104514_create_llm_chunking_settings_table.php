<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_chunking_settings', function (Blueprint $table) {
            $table->id();

            // Modèle Ollama
            $table->string('model', 100)->nullable(); // NULL = utilise le modèle de l'agent
            $table->string('ollama_host', 255)->default('ollama');
            $table->integer('ollama_port')->default(11434);
            $table->decimal('temperature', 3, 2)->default(0.30);

            // Pré-découpage
            $table->integer('window_size')->default(2000);
            $table->integer('overlap_percent')->default(10);

            // Traitement
            $table->integer('max_retries')->default(1);
            $table->integer('timeout_seconds')->default(300);

            // Prompt système
            $table->text('system_prompt');

            $table->timestamps();
        });

        // Insérer la configuration par défaut
        DB::table('llm_chunking_settings')->insert([
            'system_prompt' => $this->getDefaultPrompt(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_chunking_settings');
    }

    private function getDefaultPrompt(): string
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
};

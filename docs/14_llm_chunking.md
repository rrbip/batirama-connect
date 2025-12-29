# Chunking Assisté par LLM

## 1. Vue d'ensemble

### 1.1 Objectif

Implémenter une stratégie de découpage de documents utilisant une IA locale (Ollama) pour produire des chunks sémantiquement autonomes et optimisés pour la recherche vectorielle dans Qdrant.

### 1.2 Problème résolu

Les stratégies de chunking classiques (phrase, paragraphe, taille fixe) produisent des chunks qui :
- Peuvent couper une idée en plein milieu
- Contiennent des références implicites ("Il a dit...", "Ce document...", "Comme mentionné...")
- Ne sont pas compréhensibles hors contexte

Le chunking assisté par LLM résout ces problèmes en :
- Découpant selon le sens, pas selon un compteur
- Réécrivant les anaphores (pronoms → noms propres)
- Extrayant automatiquement mots-clés, résumés et catégories

### 1.3 Exemple concret

**Texte original :**
> Le directeur a présenté les résultats. Il a confirmé que le budget serait maintenu. Cela rassure les équipes.

**Chunk classique (phrase) :**
> "Il a confirmé que le budget serait maintenu."
→ Qdrant ne sait pas qui est "Il"

**Chunk assisté par LLM :**
> "Le Directeur Financier a confirmé lors de la réunion annuelle 2024 que le budget opérationnel serait maintenu à son niveau actuel."
→ Chunk autonome, parfaitement indexable

---

## 2. Architecture technique

### 2.1 Flux de traitement

```
Document uploadé
       ↓
Extraction texte (PDF, OCR, etc.)
       ↓
Pré-découpage en fenêtres (2000 tokens, overlap 10%)
       ↓
Pour chaque fenêtre :
   ├─→ Envoi à Ollama avec prompt structuré
   ├─→ Réception JSON (chunks, keywords, summary, category)
   ├─→ Validation JSON
   └─→ Création/mise à jour catégories
       ↓
Stockage chunks enrichis
       ↓
Vectorisation et indexation Qdrant
```

### 2.2 Composants

| Composant | Type | Rôle |
|-----------|------|------|
| `LlmChunkingSetting` | Model | Paramètres globaux du chunking LLM |
| `DocumentCategory` | Model | Catégories de documents (prédéfinies + générées) |
| `LlmChunkingService` | Service | Logique métier : pré-découpage, appel Ollama, parsing |
| `ProcessLlmChunkingJob` | Job | Traitement asynchrone sur queue dédiée |
| `DocumentCategoryResource` | Filament | CRUD des catégories |
| `LlmChunkingSettingsPage` | Filament | Configuration globale |

---

## 3. Modèle de données

### 3.1 Table `llm_chunking_settings`

Stocke les paramètres globaux du chunking LLM.

```sql
CREATE TABLE llm_chunking_settings (
    id BIGSERIAL PRIMARY KEY,

    -- Modèle Ollama
    model VARCHAR(100) DEFAULT NULL,           -- NULL = utilise le modèle de l'agent
    ollama_host VARCHAR(255) DEFAULT 'ollama',
    ollama_port INTEGER DEFAULT 11434,
    temperature DECIMAL(3,2) DEFAULT 0.3,

    -- Pré-découpage
    window_size INTEGER DEFAULT 2000,          -- Tokens par fenêtre
    overlap_percent INTEGER DEFAULT 10,        -- Chevauchement en %

    -- Traitement
    max_retries INTEGER DEFAULT 1,             -- Tentatives avant erreur
    timeout_seconds INTEGER DEFAULT 300,       -- Timeout par fenêtre (5 min)

    -- Prompt système
    system_prompt TEXT NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Note :** Table singleton (une seule ligne).

### 3.2 Table `document_categories`

Catégories de documents, alimentées manuellement ou par l'IA.

```sql
CREATE TABLE document_categories (
    id BIGSERIAL PRIMARY KEY,

    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    description TEXT NULL,
    color VARCHAR(7) DEFAULT '#6B7280',        -- Couleur hex pour l'UI

    is_ai_generated BOOLEAN DEFAULT FALSE,     -- Créée par l'IA
    usage_count INTEGER DEFAULT 0,             -- Nombre de chunks utilisant cette catégorie

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 3.3 Modifications table `document_chunks`

Ajout de colonnes pour le chunking assisté.

```sql
ALTER TABLE document_chunks ADD COLUMN original_content TEXT NULL;
ALTER TABLE document_chunks ADD COLUMN summary VARCHAR(500) NULL;
ALTER TABLE document_chunks ADD COLUMN keywords JSONB DEFAULT '[]';
ALTER TABLE document_chunks ADD COLUMN category_id BIGINT NULL REFERENCES document_categories(id) ON DELETE SET NULL;

CREATE INDEX idx_document_chunks_category ON document_chunks(category_id);
CREATE INDEX idx_document_chunks_keywords ON document_chunks USING GIN(keywords);
```

| Colonne | Type | Description |
|---------|------|-------------|
| `original_content` | TEXT | Texte original avant réécriture par l'IA |
| `summary` | VARCHAR(500) | Résumé une phrase généré par l'IA |
| `keywords` | JSONB | Mots-clés extraits `["budget", "RH", "2024"]` |
| `category_id` | FK | Catégorie principale du chunk |

---

## 4. Configuration globale

### 4.1 Paramètres par défaut

| Paramètre | Défaut | Description |
|-----------|--------|-------------|
| `model` | `null` | Modèle Ollama (null = modèle de l'agent) |
| `ollama_host` | `ollama` | Hôte du serveur Ollama |
| `ollama_port` | `11434` | Port Ollama |
| `temperature` | `0.3` | Créativité (0 = déterministe, 1 = créatif) |
| `window_size` | `2000` | Taille fenêtre en tokens |
| `overlap_percent` | `10` | Chevauchement fenêtres |
| `max_retries` | `1` | Tentatives avant échec |
| `timeout_seconds` | `300` | Timeout par fenêtre (5 min) |

### 4.2 Configuration multi-niveaux Ollama

La configuration Ollama (host, port, modèle) suit une **hiérarchie de priorité** pour le chunking :

```
┌────────────────────────────────────────────────┐
│ 1. AgentDeployment (config_overlay)            │  ← Priorité maximale
│    chunking_ollama_host, chunking_ollama_port  │
│    chunking_model                              │
├────────────────────────────────────────────────┤
│ 2. Agent                                       │
│    chunking_ollama_host, chunking_ollama_port  │
│    chunking_model                              │
├────────────────────────────────────────────────┤
│ 3. LlmChunkingSetting (global)                 │  ← Fallback
│    ollama_host, ollama_port, model             │
└────────────────────────────────────────────────┘
```

**Cas d'usage** :
- **Deployment** : Un client en marque blanche peut avoir son propre serveur Ollama
- **Agent** : Un agent peut utiliser un modèle différent (plus puissant ou plus léger)
- **Global** : Configuration par défaut pour tous les agents

**Exemple de configuration** :
```php
// AgentDeployment avec override
$deployment->config_overlay = [
    'chunking_ollama_host' => '192.168.1.100',
    'chunking_ollama_port' => 11434,
    'chunking_model' => 'mistral:7b',
];

// L'agent utilisera cette config au lieu de la globale
$config = $deployment->getChunkingConfig();
// → ['host' => '192.168.1.100', 'port' => 11434, 'model' => 'mistral:7b']
```

**Note** : La même hiérarchie existe pour Vision (`getVisionConfig()`) et Chat (`getChatConfig()`).

### 4.3 Prompt système par défaut

```markdown
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

5. **Catégorie** : Choisis la catégorie la plus pertinente parmi la liste fournie. Si aucune ne convient, propose une nouvelle catégorie.

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
```

---

## 5. Service LlmChunkingService

### 5.1 Responsabilités

1. **Pré-découpage** : Découper le texte brut en fenêtres avec overlap
2. **Appel Ollama** : Envoyer chaque fenêtre au LLM
3. **Parsing JSON** : Valider et extraire les chunks
4. **Gestion catégories** : Créer les nouvelles catégories suggérées par l'IA
5. **Stockage** : Sauvegarder les chunks enrichis

### 5.2 Interface

```php
interface LlmChunkingServiceInterface
{
    /**
     * Découpe un document en chunks via LLM
     */
    public function processDocument(Document $document): ChunkingResult;

    /**
     * Pré-découpe le texte en fenêtres
     */
    public function createWindows(string $text, int $windowSize, int $overlapPercent): array;

    /**
     * Traite une fenêtre via Ollama
     */
    public function processWindow(string $windowText, array $categories, Agent $agent): WindowResult;

    /**
     * Récupère ou crée une catégorie
     */
    public function resolveCategory(string $categoryName, ?string $description = null): DocumentCategory;
}
```

### 5.3 Algorithme de pré-découpage (fenêtre glissante)

```php
public function createWindows(string $text, int $windowSize, int $overlapPercent): array
{
    $tokens = $this->tokenize($text);
    $totalTokens = count($tokens);
    $overlapTokens = (int) ($windowSize * $overlapPercent / 100);
    $step = $windowSize - $overlapTokens;

    $windows = [];
    $position = 0;

    while ($position < $totalTokens) {
        $windowTokens = array_slice($tokens, $position, $windowSize);
        $windows[] = [
            'text' => implode(' ', $windowTokens),
            'start_position' => $position,
            'end_position' => min($position + $windowSize, $totalTokens),
        ];
        $position += $step;
    }

    return $windows;
}
```

---

## 6. Job ProcessLlmChunkingJob

### 6.1 Configuration

- **Queue** : `llm-chunking`
- **Timeout** : Aucun (traitement long accepté)
- **Retries** : Configurable (défaut: 1)

### 6.2 Workflow

```php
public function handle(LlmChunkingService $service): void
{
    $this->document->update(['extraction_status' => 'chunking']);

    try {
        $result = $service->processDocument($this->document);

        $this->document->update([
            'extraction_status' => 'completed',
            'chunk_count' => $result->chunkCount,
            'chunk_strategy' => 'llm_assisted',
        ]);

        // Dispatcher l'indexation Qdrant
        ProcessDocumentJob::dispatch($this->document, reindex: true);

    } catch (JsonParsingException $e) {
        $this->document->update([
            'extraction_status' => 'chunk_error',
            'extraction_error' => 'LLM n\'a pas produit un JSON valide: ' . $e->getMessage(),
        ]);
    } catch (\Exception $e) {
        $this->document->update([
            'extraction_status' => 'error',
            'extraction_error' => $e->getMessage(),
        ]);
    }
}
```

### 6.3 États du document

| État | Description |
|------|-------------|
| `pending` | En attente de traitement |
| `extracting` | Extraction texte en cours |
| `chunking` | Chunking LLM en cours |
| `completed` | Traitement terminé |
| `chunk_error` | Erreur de chunking (JSON invalide) |
| `error` | Erreur générale |

---

## 7. Interface Filament

### 7.1 DocumentCategoryResource

CRUD pour gérer les catégories de documents.

**Colonnes tableau :**
- Nom (avec badge couleur)
- Description
- Générée par IA (icône)
- Nombre d'utilisations
- Actions (éditer, supprimer si non utilisée)

**Formulaire :**
- Nom (requis)
- Slug (auto-généré)
- Description
- Couleur (color picker)

### 7.2 Page Configuration LLM Chunking

Accessible via menu "Configuration" > "Chunking LLM".

**Sections :**

1. **Modèle Ollama**
   - Modèle (select ou input)
   - Host / Port
   - Température (slider 0-1)

2. **Pré-découpage**
   - Taille fenêtre (tokens)
   - Overlap (%)

3. **Traitement**
   - Nombre de tentatives
   - Timeout (secondes)

4. **Prompt système**
   - Éditeur Markdown pleine largeur
   - Bouton "Réinitialiser prompt par défaut"

### 7.3 Mise à jour DocumentResource

Ajouter l'option "Assisté par LLM" dans le select de stratégie :

```php
Forms\Components\Select::make('chunk_strategy')
    ->options([
        'sentence' => 'Par phrases',
        'paragraph' => 'Par paragraphes',
        'fixed' => 'Taille fixe (500 tokens)',
        'recursive' => 'Récursif',
        'markdown' => 'Markdown (par headers)',  // Optimal pour HTML/MD
        'llm_assisted' => 'Assisté par LLM',
    ])
```

### 7.4 Page de Status

Ajouter une section pour la queue `llm-chunking` :
- Nombre de jobs en attente
- Job en cours (document + progression)
- Historique des derniers traitements

---

## 8. Intégration avec l'existant

### 8.1 Création de document

Quand `chunk_strategy = 'llm_assisted'` :
1. Extraction texte normale (PDF, OCR, etc.)
2. Au lieu d'appeler le chunker classique, dispatcher `ProcessLlmChunkingJob`
3. Le job s'exécute sur la queue `llm-chunking`

### 8.2 Import en masse

- Ajouter l'option dans le formulaire BulkImportDocuments
- Chaque document est traité individuellement via la queue

### 8.3 Web Crawler

- Utilise le `default_chunk_strategy` de l'agent
- Si `llm_assisted`, le `ProcessCrawledContentJob` dispatch vers `ProcessLlmChunkingJob`

### 8.4 AgentResource

- Ajouter `llm_assisted` dans les options de `default_chunk_strategy`

---

## 9. Gestion des erreurs

### 9.1 JSON invalide

Si l'IA ne produit pas un JSON valide :
1. Log l'erreur avec le texte de sortie brut
2. Marquer le document en `chunk_error`
3. L'admin peut :
   - Réessayer avec une autre stratégie
   - Modifier le prompt et réessayer
   - Éditer manuellement

### 9.2 Timeout Ollama

Si Ollama ne répond pas dans le délai :
1. Marquer la fenêtre comme échouée
2. Continuer avec les fenêtres suivantes (mode dégradé)
3. Signaler les fenêtres manquantes dans les métadonnées

### 9.3 Catégorie non trouvée

Si l'IA suggère une catégorie qui n'existe pas :
1. Créer automatiquement la catégorie avec `is_ai_generated = true`
2. L'admin peut la renommer/fusionner plus tard

---

## 10. Payload Qdrant enrichi

Pour chaque chunk assisté par LLM, le payload Qdrant inclut :

```json
{
  "document_id": 123,
  "chunk_index": 0,
  "content": "Le Directeur Financier a validé le budget...",
  "summary": "Validation du budget 2024 par le DF",
  "keywords": ["budget", "directeur financier", "2024"],
  "category": "Finance",
  "category_id": 5,
  "page_number": 3,
  "source": "rapport_annuel_2024.pdf"
}
```

Cela permet des recherches filtrées :
- `category = "Finance"`
- `keywords contains "budget"`

---

## 11. Monitoring

### 11.1 Métriques à suivre

- Temps moyen de traitement par document
- Taux d'échec JSON
- Nombre de catégories créées par l'IA
- Répartition des chunks par catégorie

### 11.2 Logs

Chaque traitement doit logger :
- Début/fin du traitement
- Nombre de fenêtres créées
- Nombre de chunks générés
- Catégories utilisées/créées
- Erreurs éventuelles

---

## 12. Migration depuis chunks existants

Pour les documents déjà indexés avec une stratégie classique :
1. L'admin peut sélectionner des documents et "Réindexer avec LLM"
2. Les anciens chunks sont supprimés
3. Le document repasse par le pipeline LLM
4. Les vecteurs Qdrant sont recréés

---

## 13. Sécurité

- Le prompt système ne doit pas contenir d'instructions malveillantes
- Les credentials Ollama sont stockés en config, pas en base
- Le texte extrait n'est jamais envoyé à une API externe (Ollama local)

---

## 14. Fichiers à créer/modifier

### Nouveaux fichiers

| Fichier | Description |
|---------|-------------|
| `database/migrations/xxxx_create_document_categories_table.php` | Table catégories |
| `database/migrations/xxxx_create_llm_chunking_settings_table.php` | Table settings |
| `database/migrations/xxxx_add_llm_columns_to_document_chunks_table.php` | Colonnes chunks |
| `app/Models/DocumentCategory.php` | Modèle catégorie |
| `app/Models/LlmChunkingSetting.php` | Modèle settings |
| `app/Services/LlmChunkingService.php` | Service principal |
| `app/Jobs/ProcessLlmChunkingJob.php` | Job de traitement |
| `app/Filament/Resources/DocumentCategoryResource.php` | CRUD catégories |
| `app/Filament/Pages/LlmChunkingSettings.php` | Page configuration |

### Fichiers à modifier

| Fichier | Modification |
|---------|--------------|
| `app/Filament/Resources/DocumentResource.php` | Ajouter option chunk_strategy |
| `app/Filament/Resources/AgentResource.php` | Ajouter option default_chunk_strategy |
| `app/Filament/Resources/DocumentResource/Pages/CreateDocument.php` | Support llm_assisted |
| `app/Filament/Resources/DocumentResource/Pages/BulkImportDocuments.php` | Support llm_assisted |
| `app/Jobs/ProcessDocumentJob.php` | Dispatcher vers LlmChunkingJob si nécessaire |
| `app/Jobs/Crawler/ProcessCrawledContentJob.php` | Support llm_assisted |
| `app/Models/DocumentChunk.php` | Nouvelles colonnes et relations |

---

## 15. Filtrage RAG par Catégorie

### 15.1 Vue d'ensemble

Le système peut pré-filtrer les résultats RAG en détectant automatiquement la catégorie de la question utilisateur. Cela améliore la pertinence en ne retournant que les chunks de la catégorie détectée.

### 15.2 Service CategoryDetectionService

**Fichier** : `app/Services/AI/CategoryDetectionService.php`

Détecte la catégorie d'une question via deux méthodes :

1. **Keyword matching** (rapide) - Confiance 90%
   - Cherche le nom de la catégorie dans la question
   - Ex: "comment fonctionne le parrainage ?" → catégorie "Parrainage"

2. **Embedding similarity** (fallback) - Confiance variable
   - Compare l'embedding de la question aux embeddings des catégories
   - Seuil minimum : 45% de similarité

```php
$detection = $categoryService->detect($question, $agent);
// Retourne: ['categories' => Collection, 'confidence' => 0.9, 'method' => 'keyword']

$filter = $categoryService->buildQdrantFilter($detection['categories']);
// Retourne le filtre Qdrant pour la recherche
```

### 15.3 Configuration par Agent

Chaque agent peut activer le filtrage par catégorie via le toggle "Filtrage par catégorie" dans les paramètres RAG (`AgentResource`).

| Option | Description |
|--------|-------------|
| `use_category_filtering` | Active la détection et le filtrage automatique |

### 15.4 Comportement du filtrage

Le filtrage utilise une **stratégie stricte basée sur la confiance** :

| Confiance | Comportement |
|-----------|--------------|
| ≥ 70% | **Filtrage strict** - Seuls les chunks de la catégorie détectée sont retournés, même s'il n'y en a qu'un |
| < 70% | **Fallback** - Si moins de 2 résultats filtrés, complète avec des résultats non filtrés |

**Exemple** : Question "Comment fonctionne le parrainage ?"
- Catégorie détectée : "Parrainage" (90% via keyword)
- Confiance ≥ 70% → Filtrage strict
- Résultat : Seuls les chunks "Parrainage" sont retournés

### 15.5 Payload Qdrant

Pour que le filtrage fonctionne, les chunks doivent avoir le champ `chunk_category` dans leur payload Qdrant :

```json
{
  "chunk_category": "Parrainage",
  "chunk_category_id": 5,
  "content": "...",
  "summary": "..."
}
```

**Important** : Les chunks indexés AVANT l'ajout des catégories n'ont pas ce champ. Il faut les ré-indexer via "Rebuild Index" pour ajouter les métadonnées de catégorie.

### 15.6 Debug dans la modale de test

La page `/admin/agents/{id}/test` affiche les détails du filtrage dans la section "Filtrage par catégorie" :
- Méthode de détection (keyword/embedding)
- Confiance (%)
- Catégories détectées
- Nombre de résultats filtrés vs total
- Indicateur de fallback utilisé

---

## 16. Résumés de chunks dans le contexte RAG

### 16.1 Enrichissement du contexte

Chaque chunk LLM possède un résumé (`summary`) généré par l'IA. Ce résumé est inclus dans le contexte RAG envoyé au LLM pour améliorer sa compréhension.

**Format dans le prompt** :
```
[Document: titre_document.pdf | Catégorie: Support]
Résumé: Ce chunk explique la procédure de parrainage...
Contenu: Le texte complet du chunk...
```

### 16.2 Pourquoi ne pas fusionner les chunks automatiquement

La fusion automatique des chunks consécutifs de même catégorie a été **désactivée** car elle fait perdre les résumés individuels. Chaque chunk conserve son propre résumé pour un meilleur contexte RAG.

La fusion reste disponible **manuellement** via la page "Gérer les chunks" si nécessaire.

---

## 17. Plan de déploiement

1. Exécuter les migrations
2. Créer les catégories de base via seeder (optionnel)
3. Configurer le prompt par défaut
4. Démarrer le worker : `php artisan queue:work --queue=llm-chunking`
5. Tester sur un document simple
6. Activer pour les nouveaux imports
7. **Ré-indexer les documents existants** pour ajouter les métadonnées de catégorie

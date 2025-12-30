# 18 - Refonte Agents IA : Alignement avec la Nouvelle Structure d'Indexation

> **Statut** : Document de travail
> **Branche** : `claude/fix-rag-indexing-structure-PkG6g`
> **Date de création** : 2025-12-30
> **Dernière mise à jour** : 2025-12-30

---

## 1. Contexte du Problème

### 1.1 Situation actuelle

La refonte RAG (document `refonte_rag_document_travail.md`) a introduit une **nouvelle structure d'indexation "Q/R Atomique"** dans Qdrant, mais les **agents IA et leurs déploiements n'ont pas été mis à jour** pour utiliser cette nouvelle structure.

### 1.2 Nouvelle structure d'indexation (Q/R Atomique)

Implémentée dans `RebuildAgentIndexJob.php` :

```
Pour chaque chunk "useful" :
├── N points Q/R (type: "qa_pair")
│   ├── Vecteur : embedding(question)
│   └── Payload : display_text, question, category, source_doc...
│
└── 1 point Source (type: "source_material")
    ├── Vecteur : embedding(summary + content)
    └── Payload : display_text, summary, category, source_doc...
```

### 1.3 Ancienne structure (toujours utilisée par RagService)

Le `RagService.php` cherche des champs qui n'existent plus dans la nouvelle structure :

| Champ attendu par RagService | Nouveau champ Q/R Atomique |
|------------------------------|----------------------------|
| `content` | `display_text` |
| `chunk_category` | `category` |
| N/A | `type` (qa_pair / source_material) |
| N/A | `question` |

### 1.4 Impact

- Les agents IA ne peuvent pas exploiter les points Q/R optimisés pour les questions
- Le filtrage par catégorie utilise l'ancien champ `chunk_category` au lieu de `category`
- Les réponses pré-rédigées (`display_text`) ne sont pas utilisées
- La distinction `qa_pair` vs `source_material` n'est pas exploitée

---

## 2. Proposition : Méthode d'Indexation Configurable

### 2.1 Analyse de la proposition

**Idée initiale** : Ajouter un paramétrage sur les agents IA relié à chaque méthode d'indexation.

**Avantages** :
- Permet d'avoir plusieurs méthodes d'indexation en parallèle
- Prépare l'ajout futur de nouvelles méthodes
- Rétrocompatibilité avec les agents existants
- Configuration explicite et traçable

**Points d'attention** :
- Pour l'instant une seule méthode ("Q/R Atomique") donc risque d'over-engineering
- Besoin de clarifier si la méthode est au niveau Agent ou Déploiement
- Impact sur les pipelines existants

### 2.2 Recommandation

**Approche pragmatique en 2 phases** :

1. **Phase 1 (immédiate)** : Adapter le `RagService` pour supporter la structure Q/R Atomique comme méthode par défaut, avec rétrocompatibilité
2. **Phase 2 (future)** : Créer le système de méthodes d'indexation configurables quand d'autres méthodes seront nécessaires

Cette approche évite l'over-engineering tout en préparant le terrain.

---

## 3. Spécifications Techniques

### 3.1 Phase 1 : Adaptation immédiate

#### 3.1.1 Modifications du RagService

Le `RagService` doit être adapté pour :

1. **Lire les bons champs** :
   - `display_text` au lieu de `content`
   - `category` au lieu de `chunk_category`

2. **Exploiter le champ `type`** :
   - Prioriser les résultats `qa_pair` pour les questions directes
   - Utiliser `source_material` comme contexte complémentaire

3. **Utiliser `question` pour le matching** :
   - Quand un résultat `qa_pair` est très pertinent, afficher la réponse directement

#### 3.1.2 Modifications du CategoryDetectionService

Mettre à jour le filtre Qdrant :

```php
// Avant
['key' => 'chunk_category', 'match' => ['value' => $categoryName]]

// Après
['key' => 'category', 'match' => ['value' => $categoryName]]
```

#### 3.1.3 Modifications du PromptBuilder

Adapter la construction du contexte pour utiliser :
- `display_text` comme contenu principal
- `question` pour les points Q/R
- `source_doc` et `parent_context` pour les citations

### 3.2 Phase 2 : Système de méthodes d'indexation (futur)

#### 3.2.1 Enum des méthodes d'indexation

```php
// app/Enums/IndexingMethod.php
enum IndexingMethod: string
{
    case QR_ATOMIQUE = 'qr_atomique';
    case LEGACY = 'legacy';           // Pour rétrocompatibilité
    // Futures méthodes...
    // case HIERARCHICAL = 'hierarchical';
    // case SUMMARY_TREE = 'summary_tree';

    public function label(): string
    {
        return match($this) {
            self::QR_ATOMIQUE => 'Q/R Atomique',
            self::LEGACY => 'Classique (legacy)',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::QR_ATOMIQUE => 'Génère des paires Question/Réponse pour chaque chunk + un point source. Optimal pour les FAQ et documentation.',
            self::LEGACY => 'Indexation simple du contenu brut. Pour la rétrocompatibilité.',
        };
    }
}
```

#### 3.2.2 Configuration Agent

Ajouter au modèle `Agent` :

```php
// Migration
Schema::table('agents', function (Blueprint $table) {
    $table->string('indexing_method')->default('qr_atomique');
});

// Modèle Agent
protected $casts = [
    'indexing_method' => IndexingMethod::class,
];

public function getIndexingMethod(): IndexingMethod
{
    return $this->indexing_method ?? IndexingMethod::QR_ATOMIQUE;
}
```

#### 3.2.3 Configuration Déploiement (override)

Le déploiement peut surcharger la méthode de l'agent :

```php
// Dans AgentDeployment
public function getIndexingMethod(): IndexingMethod
{
    // Priorité : config_overlay > agent
    $overlay = $this->config_overlay['indexing_method'] ?? null;

    if ($overlay) {
        return IndexingMethod::from($overlay);
    }

    return $this->agent->getIndexingMethod();
}
```

#### 3.2.4 Service de stratégie d'indexation

```php
// app/Services/AI/IndexingStrategyService.php
class IndexingStrategyService
{
    public function getPayloadMapping(IndexingMethod $method): array
    {
        return match($method) {
            IndexingMethod::QR_ATOMIQUE => [
                'content_field' => 'display_text',
                'category_field' => 'category',
                'has_types' => true,
                'types' => ['qa_pair', 'source_material'],
                'question_field' => 'question',
            ],
            IndexingMethod::LEGACY => [
                'content_field' => 'content',
                'category_field' => 'chunk_category',
                'has_types' => false,
            ],
        };
    }

    public function buildSearchFilter(IndexingMethod $method, array $categories): array
    {
        $mapping = $this->getPayloadMapping($method);

        // Construire le filtre selon la méthode
        // ...
    }
}
```

---

## 4. Plan d'Implémentation Phase 1

### 4.1 Fichiers à modifier

| Fichier | Modification |
|---------|--------------|
| `app/Services/AI/RagService.php` | Adapter les champs de lecture (`display_text`, `category`) |
| `app/Services/AI/CategoryDetectionService.php` | Changer `chunk_category` → `category` |
| `app/Services/AI/PromptBuilder.php` | Utiliser les nouveaux champs dans le contexte |
| `app/Services/AI/LearningService.php` | Vérifier la compatibilité FAQ |

### 4.2 Détail des modifications RagService

```php
// Dans retrieveContextWithDetection()

// Après la recherche Qdrant, normaliser les résultats
$results = collect($results)->map(function ($result) {
    $payload = $result['payload'] ?? [];

    // Normaliser pour compatibilité
    // Si nouveau format (Q/R Atomique)
    if (isset($payload['display_text'])) {
        $payload['content'] = $payload['display_text'];
    }

    // Normaliser la catégorie
    if (isset($payload['category']) && !isset($payload['chunk_category'])) {
        $payload['chunk_category'] = $payload['category'];
    }

    $result['payload'] = $payload;
    return $result;
})->toArray();
```

### 4.3 Modification CategoryDetectionService

```php
// Dans buildQdrantFilter()

public function buildQdrantFilter(Collection $categories): array
{
    if ($categories->isEmpty()) {
        return [];
    }

    $conditions = $categories->map(fn ($category) => [
        'key' => 'category',  // Changé de 'chunk_category'
        'match' => ['value' => $category->name],
    ])->toArray();

    return ['should' => $conditions];
}
```

### 4.4 Modification PromptBuilder

Adapter `formatRagResults()` pour utiliser les bons champs :

```php
private function formatRagResult(array $result, int $index): string
{
    $payload = $result['payload'] ?? [];

    // Utiliser display_text en priorité, sinon content
    $content = $payload['display_text'] ?? $payload['content'] ?? '';

    // Pour les Q/R pairs, inclure la question
    $questionContext = '';
    if (($payload['type'] ?? '') === 'qa_pair' && isset($payload['question'])) {
        $questionContext = "Question associée: {$payload['question']}\n";
    }

    // Source et contexte
    $source = $payload['source_doc'] ?? 'Document inconnu';
    $parentContext = $payload['parent_context'] ?? '';

    return sprintf(
        "[Source %d - %s%s]\n%s%s",
        $index + 1,
        $source,
        $parentContext ? " > {$parentContext}" : '',
        $questionContext,
        $content
    );
}
```

---

## 5. Impact sur les Fonctionnalités Existantes

### 5.1 FAQ (Learned Responses)

La collection `learned_responses` utilise une structure différente et n'est **pas impactée** par ce changement. Elle reste sur :
- `question`, `answer`
- Pas de champ `type`

### 5.2 Crawl Web

Le crawl utilise `IndexDocumentChunksJob` qui doit être vérifié pour s'assurer qu'il utilise le format Q/R Atomique. **À vérifier** : est-ce que le crawl passe par le pipeline complet ?

### 5.3 Rétrocompatibilité

Les agents avec d'anciens documents indexés (avant la refonte) :
- Continueront de fonctionner grâce à la normalisation des champs
- Devraient être ré-indexés via "Reconstruire l'index" pour bénéficier du nouveau format

---

## 6. Tests à Effectuer

### 6.1 Tests unitaires

- [ ] RagService avec payload Q/R Atomique
- [ ] RagService avec payload legacy (rétrocompatibilité)
- [ ] CategoryDetectionService avec nouveau champ `category`
- [ ] PromptBuilder avec les deux formats

### 6.2 Tests d'intégration

- [ ] Chat avec agent utilisant Q/R Atomique
- [ ] Vérifier que les réponses Q/R sont utilisées
- [ ] Vérifier le filtrage par catégorie
- [ ] Tester la recherche itérative

### 6.3 Tests manuels

- [ ] Importer un document, vérifier l'indexation Q/R
- [ ] Poser une question correspondant à une Q/R générée
- [ ] Vérifier la citation des sources dans la réponse
- [ ] Tester avec un agent ayant des documents legacy

---

## 7. Questions Ouvertes

1. **Priorité Q/R vs Source** : Doit-on prioriser les résultats `qa_pair` sur `source_material` dans le scoring ?

2. **Affichage direct** : Si un `qa_pair` a un score très élevé (>0.95), doit-on afficher la réponse directement sans passer par le LLM ?

3. **Migration des anciens index** : Faut-il forcer une reconstruction des index pour tous les agents existants ?

4. **Configuration UI** : Faut-il ajouter un champ "Méthode d'indexation" dans le formulaire Agent dès maintenant ou attendre la Phase 2 ?

---

## 8. Ordre de Développement Recommandé

1. **Modifier `CategoryDetectionService`** - Changement le plus simple (`chunk_category` → `category`)
2. **Modifier `RagService`** - Ajouter la normalisation des payloads
3. **Modifier `PromptBuilder`** - Adapter le formatage du contexte
4. **Vérifier `IndexDocumentChunksJob`** - S'assurer que le crawl utilise Q/R Atomique
5. **Tests** - Valider tous les scénarios
6. **Documentation** - Mettre à jour les docs utilisateur

---

## 9. Récapitulatif des Décisions

| Sujet | Décision |
|-------|----------|
| Approche | Phase 1 (adaptation) puis Phase 2 (configurabilité) |
| Champ contenu | `display_text` avec fallback sur `content` |
| Champ catégorie | `category` (migration depuis `chunk_category`) |
| Rétrocompatibilité | Normalisation automatique des payloads |
| FAQ | Non impactée (structure différente) |
| UI Agent | Pas de changement Phase 1, ajout futur Phase 2 |

---

> **Statut** : Document de travail - En attente de validation

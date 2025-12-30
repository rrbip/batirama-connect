# 18 - Refonte Agents IA : Alignement avec la Nouvelle Structure d'Indexation

> **Statut** : Cahier des charges - Prêt pour développement
> **Branche** : `claude/fix-rag-indexing-structure-PkG6g`
> **Date de création** : 2025-12-30
> **Dernière mise à jour** : 2025-12-30

---

## 1. Contexte du Problème

### 1.1 Situation actuelle

La refonte RAG (document `refonte_rag_document_travail.md`) a introduit une **nouvelle structure d'indexation "Q/R Atomique"** dans Qdrant via `RebuildAgentIndexJob` et `QrGeneratorService`, mais :

1. Les **services de recherche** (`RagService`, `PromptBuilder`, `CategoryDetectionService`) utilisent encore l'ancienne structure
2. Le **job d'indexation classique** (`IndexDocumentChunksJob`) n'utilise pas le format Q/R Atomique
3. La **FAQ** (`LearningService`) n'est pas synchronisée avec l'index de l'agent

### 1.2 Nouvelle structure d'indexation (Q/R Atomique)

Implémentée dans `RebuildAgentIndexJob.php` et `QrGeneratorService.php` :

```
Pour chaque chunk "useful" :
├── N points Q/R (type: "qa_pair")
│   ├── Vecteur : embedding(question)
│   └── Payload : type, display_text, question, category, source_doc, parent_context, chunk_id, document_id, agent_id
│
└── 1 point Source (type: "source_material")
    ├── Vecteur : embedding(summary + content)
    └── Payload : type, display_text, summary, category, source_doc, parent_context, chunk_id, document_id, agent_id
```

### 1.3 Ancienne structure (utilisée par certains jobs)

Utilisée par `IndexDocumentChunksJob.php` :

```
1 point par chunk :
├── Vecteur : embedding(category + content)
└── Payload : content, document_id, document_uuid, document_title, chunk_index, category, source_type, indexed_at, summary, keywords, chunk_category, chunk_category_id
```

### 1.4 Matrice des incohérences

| Composant | Champ attendu | Nouveau champ Q/R | Statut |
|-----------|---------------|-------------------|--------|
| `RagService` | `content` | `display_text` | ❌ À corriger |
| `RagService` (logs) | `chunk_category` | `category` | ❌ À corriger |
| `CategoryDetectionService` | `chunk_category` | `category` | ❌ À corriger |
| `PromptBuilder` | `content` | `display_text` | ❌ À corriger |
| `IndexDocumentChunksJob` | Ancien format | Q/R Atomique | ❌ À adapter |
| `LearningService` | Collection séparée | Aussi dans agent | ❌ À synchroniser |

---

## 2. Analyse Complète des Fichiers Impactés

### 2.1 Fichiers utilisant Qdrant - Actions requises

| Fichier | Utilisation | Action |
|---------|-------------|--------|
| `app/Services/AI/RagService.php` | Recherche vectorielle | **MODIFIER** - Supporter les deux formats + réponse directe Q/R |
| `app/Services/AI/CategoryDetectionService.php` | Filtrage catégorie | **MODIFIER** - `chunk_category` → `category` |
| `app/Services/AI/PromptBuilder.php` | Formatage contexte | **MODIFIER** - Utiliser `display_text`, `question`, `source_doc` |
| `app/Jobs/IndexDocumentChunksJob.php` | Indexation documents | **MODIFIER** - Utiliser Q/R Atomique selon méthode agent |
| `app/Services/AI/LearningService.php` | FAQ learned_responses | **MODIFIER** - Synchroniser avec index agent (type=qa_pair) |
| `app/Filament/Pages/FaqsPage.php` | Gestion FAQ | **MODIFIER** - Double indexation (learned_responses + agent) |

### 2.2 Fichiers déjà compatibles Q/R Atomique

| Fichier | Statut |
|---------|--------|
| `app/Jobs/RebuildAgentIndexJob.php` | ✅ Correct |
| `app/Services/Pipeline/QrGeneratorService.php` | ✅ Correct |
| `app/Jobs/Pipeline/ProcessMarkdownToQrJob.php` | ✅ Délègue à QrGeneratorService |
| `app/Observers/DocumentChunkObserver.php` | ✅ Gère les deux formats |
| `app/Observers/DocumentObserver.php` | ✅ Gère les deux formats |

### 2.3 Fichiers non impactés

| Fichier | Raison |
|---------|--------|
| `app/Console/Commands/QdrantInitCommand.php` | Initialisation one-shot |
| `app/Console/Commands/QdrantCleanupCommand.php` | Nettoyage générique |
| `app/Console/Commands/QdrantStatsCommand.php` | Lecture seule |
| `app/Console/Commands/IndexOuvragesCommand.php` | Collection produits séparée |

---

## 3. Objectifs de la Refonte

### 3.1 Objectif principal

Aligner tous les composants sur la structure d'indexation Q/R Atomique avec :
1. **Méthode d'indexation configurable** sur Agent/Déploiement
2. **Réponse directe** pour les Q/R avec score > 0.95
3. **FAQ synchronisée** avec l'index agent (type=qa_pair)
4. **Rétrocompatibilité** avec les anciens index

### 3.2 Décisions validées

| Sujet | Décision |
|-------|----------|
| Méthode d'indexation | Paramètre `indexing_method` sur Agent avec override possible sur Déploiement |
| Réponse directe Q/R | Si score > 0.95 sur un `qa_pair`, retourner directement sans LLM |
| FAQ | Double indexation : `learned_responses` + collection agent avec `type=qa_pair` |
| Rétrocompatibilité | Normalisation des payloads pour supporter les deux formats |
| Over-engineering | Accepté - Implémenter tout maintenant pour ne pas oublier |

---

## 4. Spécifications Techniques

### 4.1 Enum IndexingMethod

```php
// app/Enums/IndexingMethod.php
<?php

declare(strict_types=1);

namespace App\Enums;

enum IndexingMethod: string
{
    case QR_ATOMIQUE = 'qr_atomique';
    case LEGACY = 'legacy';

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

    public function getPayloadMapping(): array
    {
        return match($this) {
            self::QR_ATOMIQUE => [
                'content_field' => 'display_text',
                'category_field' => 'category',
                'has_types' => true,
                'types' => ['qa_pair', 'source_material'],
                'question_field' => 'question',
                'source_field' => 'source_doc',
                'context_field' => 'parent_context',
            ],
            self::LEGACY => [
                'content_field' => 'content',
                'category_field' => 'chunk_category',
                'has_types' => false,
                'source_field' => 'document_title',
            ],
        };
    }
}
```

### 4.2 Migration Agent

```php
// database/migrations/xxxx_add_indexing_method_to_agents.php
Schema::table('agents', function (Blueprint $table) {
    $table->string('indexing_method')->default('qr_atomique')->after('retrieval_mode');
});
```

### 4.3 Modèle Agent - Méthodes à ajouter

```php
// app/Models/Agent.php

use App\Enums\IndexingMethod;

protected $casts = [
    // ... existing casts
    'indexing_method' => IndexingMethod::class,
];

public function getIndexingMethod(): IndexingMethod
{
    return $this->indexing_method ?? IndexingMethod::QR_ATOMIQUE;
}

public function getPayloadMapping(): array
{
    return $this->getIndexingMethod()->getPayloadMapping();
}
```

### 4.4 Modèle AgentDeployment - Override

```php
// app/Models/AgentDeployment.php

public function getEffectiveIndexingMethod(): IndexingMethod
{
    // Priorité : config_overlay > agent
    $overlay = $this->config_overlay['indexing_method'] ?? null;

    if ($overlay) {
        return IndexingMethod::tryFrom($overlay) ?? $this->agent->getIndexingMethod();
    }

    return $this->agent->getIndexingMethod();
}
```

### 4.5 Service IndexingStrategyService

```php
// app/Services/AI/IndexingStrategyService.php
<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\IndexingMethod;
use App\Models\Agent;

class IndexingStrategyService
{
    /**
     * Normalise un payload Qdrant pour être compatible avec les deux formats.
     * Permet au code de toujours utiliser les mêmes champs.
     */
    public function normalizePayload(array $payload): array
    {
        // Normaliser content (nouveau format utilise display_text)
        if (isset($payload['display_text']) && !isset($payload['content'])) {
            $payload['content'] = $payload['display_text'];
        }

        // Normaliser category (nouveau format utilise category au lieu de chunk_category)
        if (isset($payload['category']) && !isset($payload['chunk_category'])) {
            $payload['chunk_category'] = $payload['category'];
        }

        // Normaliser source (nouveau format utilise source_doc)
        if (isset($payload['source_doc']) && !isset($payload['document_title'])) {
            $payload['document_title'] = $payload['source_doc'];
        }

        return $payload;
    }

    /**
     * Construit le filtre Qdrant selon la méthode d'indexation.
     */
    public function buildCategoryFilter(IndexingMethod $method, array $categoryNames): array
    {
        if (empty($categoryNames)) {
            return [];
        }

        $mapping = $method->getPayloadMapping();
        $categoryField = $mapping['category_field'];

        $conditions = array_map(fn ($name) => [
            'key' => $categoryField,
            'match' => ['value' => $name],
        ], $categoryNames);

        return ['should' => $conditions];
    }

    /**
     * Détermine si un résultat est une Q/R directe utilisable.
     */
    public function isDirectQrResult(array $result, float $minScore = 0.95): bool
    {
        $payload = $result['payload'] ?? [];
        $score = $result['score'] ?? 0;

        return ($payload['type'] ?? '') === 'qa_pair'
            && $score >= $minScore
            && !empty($payload['display_text']);
    }

    /**
     * Extrait la réponse directe d'un résultat Q/R.
     */
    public function extractDirectAnswer(array $result): ?array
    {
        $payload = $result['payload'] ?? [];

        if (($payload['type'] ?? '') !== 'qa_pair') {
            return null;
        }

        return [
            'question' => $payload['question'] ?? '',
            'answer' => $payload['display_text'] ?? '',
            'source' => $payload['source_doc'] ?? '',
            'context' => $payload['parent_context'] ?? '',
            'score' => $result['score'] ?? 0,
        ];
    }
}
```

---

## 5. Modifications des Services Existants

### 5.1 RagService - Modifications

**Fichier** : `app/Services/AI/RagService.php`

#### 5.1.1 Injection de dépendance

```php
public function __construct(
    private EmbeddingService $embeddingService,
    private QdrantService $qdrantService,
    private OllamaService $ollamaService,
    private HydrationService $hydrationService,
    private PromptBuilder $promptBuilder,
    private LearningService $learningService,
    private CategoryDetectionService $categoryDetectionService,
    private IndexingStrategyService $indexingStrategyService  // NOUVEAU
) {}
```

#### 5.1.2 Méthode query() - Réponse directe Q/R

```php
public function query(Agent $agent, string $userMessage, ?AiSession $session = null): LLMResponse
{
    // 1. Recherche des réponses apprises (priorité haute)
    $learnedResponses = $this->learningService->findSimilarLearnedResponses(...);

    // 2. Recherche dans la base vectorielle
    $retrieval = $this->retrieveContextWithDetection($agent, $userMessage);
    $ragResults = $retrieval['results'];

    // NOUVEAU: Vérifier si on a une réponse Q/R directe (score > 0.95)
    $directQr = $this->findDirectQrResponse($ragResults);
    if ($directQr !== null) {
        return $this->buildDirectQrResponse($directQr, $agent);
    }

    // Suite du traitement normal...
}

/**
 * Cherche une réponse Q/R directe parmi les résultats.
 */
private function findDirectQrResponse(array $ragResults): ?array
{
    foreach ($ragResults as $result) {
        if ($this->indexingStrategyService->isDirectQrResult($result, 0.95)) {
            return $this->indexingStrategyService->extractDirectAnswer($result);
        }
    }
    return null;
}

/**
 * Construit une réponse directe depuis un Q/R match.
 */
private function buildDirectQrResponse(array $qr, Agent $agent): LLMResponse
{
    $answer = $qr['answer'];

    // Optionnel: ajouter une mention de source
    if (!empty($qr['source'])) {
        $answer .= "\n\n*Source: {$qr['source']}*";
    }

    Log::info('RagService: Direct Q/R response used', [
        'agent' => $agent->slug,
        'question_matched' => $qr['question'],
        'score' => $qr['score'],
    ]);

    return new LLMResponse(
        content: $answer,
        model: 'direct_qr_match',
        tokensPrompt: 0,
        tokensCompletion: 0,
        generationTimeMs: 0,
        raw: [
            'direct_qr' => true,
            'matched_question' => $qr['question'],
            'score' => $qr['score'],
            'source' => $qr['source'],
        ]
    );
}
```

#### 5.1.3 Méthode retrieveContextWithDetection() - Normalisation

```php
public function retrieveContextWithDetection(Agent $agent, string $query): array
{
    // ... code existant jusqu'à la recherche Qdrant ...

    $results = $this->qdrantService->search(...);

    // NOUVEAU: Normaliser les payloads pour compatibilité
    $results = array_map(function ($result) {
        $result['payload'] = $this->indexingStrategyService->normalizePayload(
            $result['payload'] ?? []
        );
        return $result;
    }, $results);

    // ... suite du code ...
}
```

### 5.2 CategoryDetectionService - Modifications

**Fichier** : `app/Services/AI/CategoryDetectionService.php`

```php
/**
 * Construit le filtre Qdrant pour les catégories détectées.
 * Supporte les deux formats (legacy et Q/R Atomique).
 */
public function buildQdrantFilter(Collection $categories): array
{
    if ($categories->isEmpty()) {
        return [];
    }

    // Utiliser les deux champs pour compatibilité
    $conditions = [];

    foreach ($categories as $category) {
        // Nouveau format (Q/R Atomique)
        $conditions[] = [
            'key' => 'category',
            'match' => ['value' => $category->name],
        ];
        // Ancien format (legacy) - pour rétrocompatibilité
        $conditions[] = [
            'key' => 'chunk_category',
            'match' => ['value' => $category->name],
        ];
    }

    return ['should' => $conditions];
}
```

### 5.3 PromptBuilder - Modifications

**Fichier** : `app/Services/AI/PromptBuilder.php`

```php
/**
 * Formate un résultat RAG pour inclusion dans le contexte.
 * Supporte les deux formats de payload.
 */
private function formatRagResult(array $result, int $index): string
{
    $payload = $result['payload'] ?? [];
    $score = $result['score'] ?? 0;

    // Contenu principal (supporte les deux formats)
    $content = $payload['display_text'] ?? $payload['content'] ?? '';

    // Source et contexte
    $source = $payload['source_doc'] ?? $payload['document_title'] ?? 'Document';
    $parentContext = $payload['parent_context'] ?? '';
    $category = $payload['category'] ?? $payload['chunk_category'] ?? '';

    // Pour les Q/R pairs, afficher la question associée
    $questionInfo = '';
    if (($payload['type'] ?? '') === 'qa_pair' && !empty($payload['question'])) {
        $questionInfo = "Question: {$payload['question']}\nRéponse: ";
    }

    // Format final
    $header = "[Source {$index}]";
    if ($category) {
        $header .= " [{$category}]";
    }
    $header .= " - {$source}";
    if ($parentContext) {
        $header .= " > {$parentContext}";
    }

    return "{$header}\n{$questionInfo}{$content}";
}
```

### 5.4 LearningService - Double indexation FAQ

**Fichier** : `app/Services/AI/LearningService.php`

```php
/**
 * Valide et apprend une réponse IA.
 * Indexe dans learned_responses ET dans la collection de l'agent.
 */
public function validateAndLearn(
    AiMessage $message,
    int $validatorId,
    ?string $correctedAnswer = null
): bool {
    // ... code existant pour learned_responses ...

    // NOUVEAU: Indexer aussi dans la collection de l'agent comme qa_pair
    $this->indexInAgentCollection($message, $question, $answer);

    return true;
}

/**
 * Ajoute une FAQ manuelle.
 * Indexe dans learned_responses ET dans la collection de l'agent.
 */
public function addManualFaq(
    Agent $agent,
    string $question,
    string $answer,
    int $userId
): bool {
    // ... code existant pour learned_responses ...

    // NOUVEAU: Indexer aussi dans la collection de l'agent comme qa_pair
    $this->indexFaqInAgentCollection($agent, $question, $answer);

    return true;
}

/**
 * Indexe une FAQ dans la collection de l'agent avec type=qa_pair.
 */
private function indexFaqInAgentCollection(
    Agent $agent,
    string $question,
    string $answer
): void {
    if (empty($agent->qdrant_collection)) {
        return;
    }

    $embedding = $this->embeddingService->embed($question);

    $pointId = Str::uuid()->toString();

    $this->qdrantService->upsert($agent->qdrant_collection, [[
        'id' => $pointId,
        'vector' => $embedding,
        'payload' => [
            'type' => 'qa_pair',
            'category' => 'FAQ',
            'display_text' => $answer,
            'question' => $question,
            'source_doc' => 'FAQ Validée',
            'parent_context' => '',
            'chunk_id' => null,
            'document_id' => null,
            'agent_id' => $agent->id,
            'is_faq' => true,  // Marqueur pour distinguer des Q/R documentaires
            'indexed_at' => now()->toIso8601String(),
        ],
    ]]);

    Log::info('LearningService: FAQ indexed in agent collection', [
        'agent' => $agent->slug,
        'collection' => $agent->qdrant_collection,
        'point_id' => $pointId,
    ]);
}
```

### 5.5 IndexDocumentChunksJob - Utilisation de la méthode d'indexation

**Fichier** : `app/Jobs/IndexDocumentChunksJob.php`

```php
public function handle(QdrantService $qdrantService, EmbeddingService $embeddingService): void
{
    $agent = $this->document->agent;
    $indexingMethod = $agent->getIndexingMethod();

    if ($indexingMethod === IndexingMethod::QR_ATOMIQUE) {
        // Déléguer au QrGeneratorService ou RebuildAgentIndexJob
        // Les chunks doivent avoir knowledge_units
        $this->indexQrAtomique($qdrantService, $embeddingService, $agent);
    } else {
        // Format legacy
        $this->indexLegacy($qdrantService, $embeddingService, $agent);
    }
}

private function indexQrAtomique(...): void
{
    // Si les chunks ont des knowledge_units, créer les points Q/R
    // Sinon, marquer comme non indexés (le pipeline Q/R n'a pas été exécuté)
    foreach ($this->document->chunks as $chunk) {
        if (empty($chunk->knowledge_units)) {
            Log::warning('IndexDocumentChunksJob: Chunk has no knowledge_units, skipping Q/R indexation', [
                'chunk_id' => $chunk->id,
            ]);
            continue;
        }

        // Utiliser la même logique que RebuildAgentIndexJob
        // ...
    }
}

private function indexLegacy(...): void
{
    // Code existant (ancien format)
    // ...
}
```

---

## 6. Interface Utilisateur

### 6.1 Formulaire Agent - Champ méthode d'indexation

**Fichier** : `app/Filament/Resources/AgentResource.php`

Ajouter dans la section RAG :

```php
Forms\Components\Select::make('indexing_method')
    ->label('Méthode d\'indexation')
    ->options(collect(IndexingMethod::cases())->mapWithKeys(fn ($m) => [
        $m->value => $m->label(),
    ]))
    ->default('qr_atomique')
    ->helperText(fn ($state) => IndexingMethod::tryFrom($state)?->description() ?? '')
    ->reactive(),
```

### 6.2 Formulaire Déploiement - Override

Ajouter dans `config_overlay` la possibilité de surcharger la méthode :

```php
Forms\Components\Select::make('config_overlay.indexing_method')
    ->label('Méthode d\'indexation (override)')
    ->options([
        '' => 'Hériter de l\'agent',
        ...collect(IndexingMethod::cases())->mapWithKeys(fn ($m) => [
            $m->value => $m->label(),
        ]),
    ])
    ->helperText('Laissez vide pour utiliser la configuration de l\'agent'),
```

---

## 7. Plan d'Implémentation

### 7.1 Ordre de développement

| # | Tâche | Fichiers | Priorité |
|---|-------|----------|----------|
| 1 | Créer enum `IndexingMethod` | `app/Enums/IndexingMethod.php` | Haute |
| 2 | Migration `indexing_method` sur agents | `database/migrations/` | Haute |
| 3 | Créer `IndexingStrategyService` | `app/Services/AI/IndexingStrategyService.php` | Haute |
| 4 | Modifier `Agent` et `AgentDeployment` | `app/Models/` | Haute |
| 5 | Modifier `CategoryDetectionService` | `app/Services/AI/CategoryDetectionService.php` | Haute |
| 6 | Modifier `RagService` (normalisation + réponse directe) | `app/Services/AI/RagService.php` | Haute |
| 7 | Modifier `PromptBuilder` | `app/Services/AI/PromptBuilder.php` | Haute |
| 8 | Modifier `LearningService` (double indexation FAQ) | `app/Services/AI/LearningService.php` | Moyenne |
| 9 | Modifier `FaqsPage` | `app/Filament/Pages/FaqsPage.php` | Moyenne |
| 10 | Modifier `IndexDocumentChunksJob` | `app/Jobs/IndexDocumentChunksJob.php` | Moyenne |
| 11 | Modifier UI Agent/Déploiement | `app/Filament/Resources/` | Basse |
| 12 | Tests | `tests/` | Haute |

### 7.2 Estimations

- **Développement** : 4-6 heures
- **Tests** : 2 heures
- **Documentation** : 1 heure

---

## 8. Tests à Effectuer

### 8.1 Tests unitaires

- [ ] `IndexingMethod` enum - labels, descriptions, payload mapping
- [ ] `IndexingStrategyService` - normalisation payloads
- [ ] `IndexingStrategyService` - détection Q/R directe
- [ ] `CategoryDetectionService` - filtre compatible deux formats
- [ ] `RagService` - réponse directe si score > 0.95

### 8.2 Tests d'intégration

- [ ] Chat avec agent Q/R Atomique - résultats corrects
- [ ] Chat avec agent Legacy - rétrocompatibilité
- [ ] FAQ ajoutée manuellement - indexée dans agent collection
- [ ] FAQ validée depuis message - indexée dans agent collection
- [ ] Question FAQ avec score > 0.95 - réponse directe

### 8.3 Tests manuels

- [ ] Créer un agent avec méthode Q/R Atomique
- [ ] Importer un document, vérifier l'indexation
- [ ] Poser une question correspondant exactement à une Q/R
- [ ] Vérifier la réponse directe (sans appel LLM)
- [ ] Créer un déploiement avec override de méthode
- [ ] Ajouter une FAQ, vérifier la double indexation

---

## 9. Points d'attention

### 9.1 Rétrocompatibilité

- Les agents existants gardent leurs index legacy fonctionnels
- La normalisation des payloads permet de lire les deux formats
- Le filtre catégorie cherche dans `category` ET `chunk_category`

### 9.2 Performance

- La réponse directe Q/R évite un appel LLM (gain significatif)
- La double indexation FAQ ajoute une opération Qdrant par FAQ
- Le score 0.95 pour réponse directe est conservateur (évite faux positifs)

### 9.3 Cohérence des données

- Reconstruire l'index (`RebuildAgentIndexJob`) après migration pour uniformiser
- Les nouveaux documents utilisent automatiquement Q/R Atomique
- Les anciens documents restent en legacy jusqu'à reindexation

---

## 10. Récapitulatif des Décisions

| Sujet | Décision |
|-------|----------|
| Enum méthodes | `IndexingMethod` avec `QR_ATOMIQUE` et `LEGACY` |
| Niveau configuration | Agent avec override possible sur Déploiement |
| Seuil réponse directe | Score > 0.95 sur `qa_pair` |
| FAQ | Double indexation : `learned_responses` + collection agent |
| Rétrocompatibilité | Normalisation payloads + filtre dual |
| Champ catégorie | `category` (nouveau) + `chunk_category` (fallback) |
| Champ contenu | `display_text` (nouveau) + `content` (fallback) |

---

> **Statut** : Cahier des charges validé - Prêt pour développement

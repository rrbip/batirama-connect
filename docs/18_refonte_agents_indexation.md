# 18 - Refonte Agents IA : Alignement avec la Structure Q/R Atomique

> **Statut** : Cahier des charges - Prêt pour développement
> **Branche** : `claude/fix-rag-indexing-structure-PkG6g`
> **Date de création** : 2025-12-30
> **Dernière mise à jour** : 2025-12-30

---

## 1. Contexte et Objectifs

### 1.1 Situation actuelle

La refonte RAG a introduit la structure d'indexation **Q/R Atomique** dans `RebuildAgentIndexJob` et `QrGeneratorService`, mais :

1. Les **services de recherche** (`RagService`, `PromptBuilder`, `CategoryDetectionService`) utilisent encore les anciens champs
2. Le **job d'indexation** (`IndexDocumentChunksJob`) utilise l'ancien format
3. La **FAQ** n'est pas synchronisée avec l'index de l'agent

### 1.2 Objectifs

1. **Aligner tous les composants** sur la structure Q/R Atomique
2. **Supprimer le code legacy** - pas de rétrocompatibilité, on migre tout
3. **Architecture extensible** - prévoir l'ajout de nouvelles méthodes d'indexation (ex: pour Expert BTP/devis)
4. **FAQ synchronisée** avec l'index agent (type=qa_pair)
5. **Réponse directe** pour les Q/R avec score > 0.95

### 1.3 Approche

**Pas de rétrocompatibilité** : On supprime l'ancien format et on force la reconstruction des index après déploiement.

```bash
# Post-déploiement obligatoire
php artisan agent:reindex --all
```

---

## 2. Structure Q/R Atomique (Rappel)

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

### Champs utilisés

| Champ | Description |
|-------|-------------|
| `type` | `qa_pair` ou `source_material` |
| `display_text` | Contenu à afficher (réponse pour Q/R, contenu pour source) |
| `question` | Question associée (Q/R uniquement) |
| `category` | Catégorie du chunk |
| `source_doc` | Titre du document source |
| `parent_context` | Contexte hiérarchique (breadcrumbs) |
| `chunk_id` | ID du chunk en base |
| `document_id` | ID du document en base |
| `agent_id` | ID de l'agent |

---

## 3. Architecture Extensible

### 3.1 Enum IndexingMethod

Pour l'instant un seul mode, mais prévu pour en ajouter d'autres :

```php
// app/Enums/IndexingMethod.php
<?php

declare(strict_types=1);

namespace App\Enums;

enum IndexingMethod: string
{
    case QR_ATOMIQUE = 'qr_atomique';
    // Futures méthodes possibles :
    // case DEVIS_STRUCTURE = 'devis_structure';  // Pour Expert BTP
    // case HIERARCHICAL = 'hierarchical';
    // case SUMMARY_TREE = 'summary_tree';

    public function label(): string
    {
        return match($this) {
            self::QR_ATOMIQUE => 'Q/R Atomique',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::QR_ATOMIQUE => 'Génère des paires Question/Réponse autonomes pour chaque chunk. Optimal pour FAQ et documentation.',
        };
    }
}
```

### 3.2 Migration Agent

```php
// database/migrations/xxxx_add_indexing_method_to_agents.php
Schema::table('agents', function (Blueprint $table) {
    $table->string('indexing_method')->default('qr_atomique')->after('retrieval_mode');
});
```

### 3.3 Modèle Agent

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
```

### 3.4 Modèle AgentDeployment - Override possible

```php
// app/Models/AgentDeployment.php

public function getEffectiveIndexingMethod(): IndexingMethod
{
    $overlay = $this->config_overlay['indexing_method'] ?? null;

    if ($overlay) {
        return IndexingMethod::tryFrom($overlay) ?? $this->agent->getIndexingMethod();
    }

    return $this->agent->getIndexingMethod();
}
```

---

## 4. Fichiers à Modifier

### 4.1 Vue d'ensemble

| Fichier | Action | Description |
|---------|--------|-------------|
| `RagService.php` | **Modifier** | Lire les champs Q/R + réponse directe si score > 0.95 |
| `CategoryDetectionService.php` | **Modifier** | Utiliser `category` au lieu de `chunk_category` |
| `PromptBuilder.php` | **Modifier** | Utiliser `display_text`, `question`, `source_doc` |
| `LearningService.php` | **Modifier** | Double indexation FAQ |
| `FaqsPage.php` | **Modifier** | Appeler double indexation |
| `IndexDocumentChunksJob.php` | **Supprimer/Refactorer** | Déléguer au pipeline Q/R Atomique |

### 4.2 Fichiers déjà corrects

- `RebuildAgentIndexJob.php` ✅
- `QrGeneratorService.php` ✅
- `ProcessMarkdownToQrJob.php` ✅
- `DocumentChunkObserver.php` ✅ (gère `qdrant_point_ids`)
- `DocumentObserver.php` ✅

---

## 5. Spécifications Techniques

### 5.1 IndexingStrategyService

Service pour gérer les stratégies d'indexation et la détection de réponse directe :

```php
// app/Services/AI/IndexingStrategyService.php
<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\IndexingMethod;

class IndexingStrategyService
{
    /**
     * Seuil de score pour réponse directe Q/R (sans appel LLM).
     */
    public const DIRECT_QR_THRESHOLD = 0.95;

    /**
     * Détermine si un résultat est une Q/R directe utilisable.
     */
    public function isDirectQrResult(array $result): bool
    {
        $payload = $result['payload'] ?? [];
        $score = $result['score'] ?? 0;

        return ($payload['type'] ?? '') === 'qa_pair'
            && $score >= self::DIRECT_QR_THRESHOLD
            && !empty($payload['display_text']);
    }

    /**
     * Extrait la réponse directe d'un résultat Q/R.
     */
    public function extractDirectAnswer(array $result): array
    {
        $payload = $result['payload'] ?? [];

        return [
            'question' => $payload['question'] ?? '',
            'answer' => $payload['display_text'] ?? '',
            'source' => $payload['source_doc'] ?? '',
            'context' => $payload['parent_context'] ?? '',
            'category' => $payload['category'] ?? '',
            'score' => $result['score'] ?? 0,
        ];
    }

    /**
     * Construit le filtre Qdrant pour les catégories.
     */
    public function buildCategoryFilter(array $categoryNames): array
    {
        if (empty($categoryNames)) {
            return [];
        }

        $conditions = array_map(fn ($name) => [
            'key' => 'category',
            'match' => ['value' => $name],
        ], $categoryNames);

        return ['should' => $conditions];
    }
}
```

### 5.2 RagService - Modifications

```php
// app/Services/AI/RagService.php

public function __construct(
    // ... existing deps
    private IndexingStrategyService $indexingStrategyService
) {}

public function query(Agent $agent, string $userMessage, ?AiSession $session = null): LLMResponse
{
    // 1. Recherche des réponses apprises (priorité haute)
    $learnedResponses = $this->learningService->findSimilarLearnedResponses(
        question: $userMessage,
        agentSlug: $agent->slug,
        limit: $agent->getMaxLearnedResponses(),
        minScore: $agent->getLearnedMinScore()
    );

    // 2. Recherche dans la base vectorielle
    $retrieval = $this->retrieveContextWithDetection($agent, $userMessage);
    $ragResults = $retrieval['results'];
    $categoryDetection = $retrieval['category_detection'];

    // 3. NOUVEAU: Vérifier si on a une réponse Q/R directe (score > 0.95)
    $directQr = $this->findDirectQrResponse($ragResults);
    if ($directQr !== null) {
        return $this->buildDirectQrResponse($directQr, $agent, $categoryDetection);
    }

    // 4. Suite du traitement normal (hydratation, LLM, etc.)
    // ... code existant ...
}

/**
 * Cherche une réponse Q/R directe parmi les résultats.
 */
private function findDirectQrResponse(array $ragResults): ?array
{
    foreach ($ragResults as $result) {
        if ($this->indexingStrategyService->isDirectQrResult($result)) {
            return $this->indexingStrategyService->extractDirectAnswer($result);
        }
    }
    return null;
}

/**
 * Construit une réponse directe depuis un Q/R match.
 */
private function buildDirectQrResponse(array $qr, Agent $agent, ?array $categoryDetection): LLMResponse
{
    $answer = $qr['answer'];

    Log::info('RagService: Direct Q/R response used', [
        'agent' => $agent->slug,
        'question_matched' => $qr['question'],
        'score' => $qr['score'],
        'source' => $qr['source'],
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
            'category' => $qr['category'],
            'context' => [
                'category_detection' => $categoryDetection,
            ],
        ]
    );
}
```

### 5.3 CategoryDetectionService - Modifications

```php
// app/Services/AI/CategoryDetectionService.php

/**
 * Construit le filtre Qdrant pour les catégories détectées.
 */
public function buildQdrantFilter(Collection $categories): array
{
    if ($categories->isEmpty()) {
        return [];
    }

    $conditions = $categories->map(fn ($category) => [
        'key' => 'category',  // Utilise le champ Q/R Atomique
        'match' => ['value' => $category->name],
    ])->toArray();

    return ['should' => $conditions];
}
```

### 5.4 PromptBuilder - Modifications

```php
// app/Services/AI/PromptBuilder.php

/**
 * Formate un résultat RAG pour inclusion dans le contexte.
 */
private function formatRagResult(array $result, int $index): string
{
    $payload = $result['payload'] ?? [];

    // Champs Q/R Atomique
    $content = $payload['display_text'] ?? '';
    $source = $payload['source_doc'] ?? 'Document';
    $parentContext = $payload['parent_context'] ?? '';
    $category = $payload['category'] ?? '';
    $type = $payload['type'] ?? '';

    // Pour les Q/R pairs, afficher la question associée
    $questionInfo = '';
    if ($type === 'qa_pair' && !empty($payload['question'])) {
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

### 5.5 LearningService - Double indexation FAQ

```php
// app/Services/AI/LearningService.php

/**
 * Indexe une FAQ dans la collection de l'agent avec type=qa_pair.
 */
public function indexFaqInAgentCollection(
    Agent $agent,
    string $question,
    string $answer,
    ?int $messageId = null
): ?string {
    if (empty($agent->qdrant_collection)) {
        Log::warning('LearningService: Agent has no Qdrant collection', [
            'agent' => $agent->slug,
        ]);
        return null;
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
            'is_faq' => true,
            'message_id' => $messageId,
            'indexed_at' => now()->toIso8601String(),
        ],
    ]]);

    Log::info('LearningService: FAQ indexed in agent collection', [
        'agent' => $agent->slug,
        'collection' => $agent->qdrant_collection,
        'point_id' => $pointId,
    ]);

    return $pointId;
}

/**
 * Valide et apprend une réponse IA.
 */
public function validateAndLearn(
    AiMessage $message,
    int $validatorId,
    ?string $correctedAnswer = null
): bool {
    // ... code existant pour learned_responses ...

    // Double indexation : aussi dans la collection de l'agent
    $agent = $message->session->agent;
    $this->indexFaqInAgentCollection($agent, $question, $answer, $message->id);

    return true;
}
```

### 5.6 IndexDocumentChunksJob - Refactoring

Ce job doit maintenant utiliser le format Q/R Atomique. Deux options :

**Option A** : Déléguer au pipeline existant
```php
// Le job dispatch ProcessMarkdownToQrJob qui gère déjà Q/R Atomique
```

**Option B** : Utiliser la même logique que RebuildAgentIndexJob
```php
public function handle(QdrantService $qdrantService, EmbeddingService $embeddingService): void
{
    $agent = $this->document->agent;

    foreach ($this->document->chunks()->where('useful', true)->get() as $chunk) {
        if (empty($chunk->knowledge_units)) {
            Log::warning('IndexDocumentChunksJob: Chunk without knowledge_units, skipping', [
                'chunk_id' => $chunk->id,
            ]);
            continue;
        }

        // Utiliser la même logique que RebuildAgentIndexJob::buildPointsForChunk()
        $points = $this->buildQrAtomiquePoints($chunk, $embeddingService, $agent);

        if (!empty($points['points'])) {
            $qdrantService->upsert($agent->qdrant_collection, $points['points']);

            $chunk->update([
                'qdrant_point_ids' => $points['point_ids'],
                'qdrant_points_count' => count($points['point_ids']),
                'is_indexed' => true,
                'indexed_at' => now(),
            ]);
        }
    }
}
```

---

## 6. Interface Utilisateur

### 6.1 Formulaire Agent

Ajouter dans la section RAG (pour le futur, quand on aura plusieurs méthodes) :

```php
Forms\Components\Select::make('indexing_method')
    ->label('Méthode d\'indexation')
    ->options(collect(IndexingMethod::cases())->mapWithKeys(fn ($m) => [
        $m->value => $m->label(),
    ]))
    ->default('qr_atomique')
    ->helperText(fn ($state) => IndexingMethod::tryFrom($state)?->description() ?? '')
    ->disabled()  // Désactivé tant qu'on n'a qu'une méthode
    ->dehydrated(),
```

---

## 7. Commande de Migration

### 7.1 Améliorer AgentReindexCommand

Ajouter l'option `--all` :

```php
// app/Console/Commands/AgentReindexCommand.php

protected $signature = 'agent:reindex
    {agent? : Slug de l\'agent (optionnel si --all)}
    {--all : Réindexer tous les agents}
    {--force : Supprimer et recréer la collection}';

public function handle(): int
{
    if ($this->option('all')) {
        $agents = Agent::whereNotNull('qdrant_collection')->get();

        $this->info("Réindexation de {$agents->count()} agents...");

        foreach ($agents as $agent) {
            $this->info("  → {$agent->name}");
            RebuildAgentIndexJob::dispatch($agent);
        }

        $this->info('Jobs de réindexation lancés.');
        return Command::SUCCESS;
    }

    // ... code existant pour un agent spécifique ...
}
```

---

## 8. Plan d'Implémentation

### 8.1 Ordre de développement

| # | Tâche | Fichiers |
|---|-------|----------|
| 1 | Créer enum `IndexingMethod` | `app/Enums/IndexingMethod.php` |
| 2 | Créer migration | `database/migrations/` |
| 3 | Modifier modèle `Agent` | `app/Models/Agent.php` |
| 4 | Modifier modèle `AgentDeployment` | `app/Models/AgentDeployment.php` |
| 5 | Créer `IndexingStrategyService` | `app/Services/AI/IndexingStrategyService.php` |
| 6 | Modifier `CategoryDetectionService` | `app/Services/AI/CategoryDetectionService.php` |
| 7 | Modifier `RagService` | `app/Services/AI/RagService.php` |
| 8 | Modifier `PromptBuilder` | `app/Services/AI/PromptBuilder.php` |
| 9 | Modifier `LearningService` | `app/Services/AI/LearningService.php` |
| 10 | Modifier `FaqsPage` | `app/Filament/Pages/FaqsPage.php` |
| 11 | Refactorer `IndexDocumentChunksJob` | `app/Jobs/IndexDocumentChunksJob.php` |
| 12 | Améliorer `AgentReindexCommand` | `app/Console/Commands/AgentReindexCommand.php` |
| 13 | Modifier UI Agent | `app/Filament/Resources/AgentResource.php` |

### 8.2 Post-déploiement

```bash
# 1. Lancer les migrations
php artisan migrate

# 2. Réindexer tous les agents
php artisan agent:reindex --all --force

# 3. Vérifier les stats
php artisan qdrant:stats
```

---

## 9. Tests

### 9.1 Tests unitaires

- [ ] `IndexingMethod` enum
- [ ] `IndexingStrategyService::isDirectQrResult()`
- [ ] `IndexingStrategyService::extractDirectAnswer()`
- [ ] `CategoryDetectionService::buildQdrantFilter()` avec champ `category`

### 9.2 Tests d'intégration

- [ ] Chat avec agent - résultats Q/R Atomique
- [ ] Question exacte d'une Q/R → réponse directe (pas de LLM)
- [ ] FAQ ajoutée → indexée dans agent collection
- [ ] FAQ validée → indexée dans agent collection

### 9.3 Tests manuels

- [ ] Importer document, vérifier indexation Q/R
- [ ] Poser question correspondant à Q/R, vérifier réponse directe
- [ ] Vérifier logs "Direct Q/R response used"
- [ ] `agent:reindex --all` fonctionne

---

## 10. Récapitulatif des Décisions

| Sujet | Décision |
|-------|----------|
| Rétrocompatibilité legacy | **Non** - On supprime l'ancien format |
| Architecture | Extensible avec enum `IndexingMethod` |
| Méthode actuelle | `QR_ATOMIQUE` uniquement |
| Seuil réponse directe | Score > 0.95 sur `qa_pair` |
| FAQ | Double indexation : `learned_responses` + agent collection |
| Migration | `agent:reindex --all --force` post-déploiement |
| Champ catégorie | `category` uniquement |
| Champ contenu | `display_text` uniquement |

---

> **Statut** : Cahier des charges validé - Prêt pour développement

# Cahier des Charges : Configuration RAG par Agent

## 1. Contexte et Objectifs

### 1.1 Contexte
Le système Batirama Connect permet de créer des agents IA personnalisés depuis un backoffice Filament. Chaque agent peut avoir des besoins différents en termes de récupération de contexte (RAG) et de comportement.

**Problème identifié :** Les paramètres RAG étaient globaux (`config/ai.php`), ne permettant pas d'adapter le comportement par agent.

### 1.2 Objectifs
- Permettre aux administrateurs de configurer finement le comportement RAG de chaque agent
- Offrir un contrôle sur la précision vs exhaustivité des résultats
- Proposer un mode "strict" optionnel pour les agents nécessitant des garde-fous anti-hallucination
- Maintenir la compatibilité avec la configuration globale (fallback)

---

## 2. Spécifications Fonctionnelles

### 2.1 Nouveaux Champs de Configuration

| Champ | Type | Défaut | Plage | Description |
|-------|------|--------|-------|-------------|
| `min_rag_score` | float | 0.5 | 0.0 - 1.0 | Score minimum de similarité vectorielle pour inclure un document dans le contexte |
| `max_learned_responses` | integer | 3 | 0 - 10 | Nombre maximum de réponses apprises (cas similaires validés) à inclure |
| `learned_min_score` | float | 0.75 | 0.0 - 1.0 | Score minimum pour inclure une réponse apprise |
| `context_token_limit` | integer | 4000 | 1000 - 16000 | Limite de tokens pour le contexte documentaire injecté |
| `strict_mode` | boolean | false | - | Active les garde-fous automatiques anti-hallucination |

### 2.2 Comportement du Mode Strict

Quand `strict_mode = true`, le texte suivant est automatiquement ajouté au prompt système :

```
## CONTRAINTES DE RÉPONSE (Mode Strict)

- Ne réponds QU'avec les informations présentes dans le contexte fourni
- Si l'information demandée n'est pas dans le contexte, indique clairement :
  "Je n'ai pas cette information dans ma base de connaissances"
- Ne fais JAMAIS d'hypothèses ou d'inventions sur des données chiffrées
  (prix, quantités, dimensions)
- Cite toujours la source de tes affirmations quand c'est pertinent
- Si plusieurs sources se contredisent, signale cette incohérence
```

**Cas d'usage recommandés :**
- Agents BTP (prix, quantités, normes techniques)
- Agents support client (procédures exactes)
- Agents médicaux/juridiques (informations sensibles)

**Cas où NE PAS utiliser :**
- Agents créatifs (rédaction, brainstorming)
- Agents conversationnels généralistes

### 2.3 Mécanisme de Fallback

Chaque paramètre utilise la valeur de l'agent si définie, sinon la valeur globale de `config/ai.php` :

```php
// Exemple dans Agent.php
public function getMinRagScore(): float
{
    return $this->min_rag_score ?? config('ai.rag.min_score', 0.5);
}
```

---

## 3. Spécifications Techniques

### 3.1 Migration Base de Données

**Fichier :** `database/migrations/2025_12_25_000001_add_rag_config_fields_to_agents_table.php`

```sql
ALTER TABLE agents ADD COLUMN min_rag_score FLOAT DEFAULT 0.5;
ALTER TABLE agents ADD COLUMN max_learned_responses INTEGER DEFAULT 3;
ALTER TABLE agents ADD COLUMN learned_min_score FLOAT DEFAULT 0.75;
ALTER TABLE agents ADD COLUMN context_token_limit INTEGER DEFAULT 4000;
ALTER TABLE agents ADD COLUMN strict_mode BOOLEAN DEFAULT FALSE;
```

### 3.2 Modèle Agent

**Fichier :** `app/Models/Agent.php`

**Modifications :**
- Ajout des champs au tableau `$fillable`
- Ajout des casts appropriés (`float`, `boolean`)
- Ajout de 5 méthodes helpers avec fallback sur config globale

```php
protected $fillable = [
    // ... existants ...
    'min_rag_score',
    'max_learned_responses',
    'learned_min_score',
    'context_token_limit',
    'strict_mode',
];

protected $casts = [
    // ... existants ...
    'min_rag_score' => 'float',
    'learned_min_score' => 'float',
    'strict_mode' => 'boolean',
];

// Méthodes helpers
public function getMinRagScore(): float;
public function getMaxLearnedResponses(): int;
public function getLearnedMinScore(): float;
public function getContextTokenLimit(): int;
public function getStrictModeGuardrails(): string;
```

### 3.3 Interface Filament

**Fichier :** `app/Filament/Resources/AgentResource.php`

**Onglet :** "RAG & Retrieval"

**Sections ajoutées :**

1. **Configuration RAG** (existante, enrichie)
   - `min_rag_score` : TextInput numérique avec step 0.05, min 0, max 1

2. **Réponses apprises** (nouvelle section)
   - `max_learned_responses` : TextInput numérique
   - `learned_min_score` : TextInput numérique avec step 0.05
   - `context_token_limit` : TextInput numérique

3. **Mode de fonctionnement** (nouvelle section)
   - `strict_mode` : Toggle avec helper text explicatif

### 3.4 Service RagService

**Fichier :** `app/Services/AI/RagService.php`

**Modifications :**

```php
// Avant (config globale)
$minScore = config('ai.rag.min_score', 0.5);

// Après (config par agent avec fallback)
$minScore = $agent->getMinRagScore();
```

Points d'utilisation :
- `retrieveContext()` : utilise `getMinRagScore()`
- `query()` : utilise `getMaxLearnedResponses()`, `getLearnedMinScore()`, `getContextTokenLimit()`

### 3.5 Service PromptBuilder

**Fichier :** `app/Services/AI/PromptBuilder.php`

**Modification :**

```php
public function buildChatMessages(...): array
{
    $systemContent = $agent->system_prompt;

    // Ajout automatique des garde-fous si strict_mode activé
    $systemContent .= $agent->getStrictModeGuardrails();

    // ... reste du code ...
}
```

---

## 4. Interface Utilisateur

### 4.1 Formulaire Agent - Onglet "RAG & Retrieval"

```
┌─────────────────────────────────────────────────────────────────┐
│ Configuration RAG                                               │
├─────────────────────────────────────────────────────────────────┤
│ Mode de récupération    │ [Vecteurs uniquement ▼]              │
│ Collection Qdrant       │ [agent_btp_ouvrages    ]              │
│ Max résultats RAG       │ [5                     ]              │
│ Score minimum RAG       │ [0.5                   ]              │
│                         │ 0.5 = permissif, 0.8 = strict        │
│ Recherche itérative     │ [○]                                   │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ Réponses apprises                                               │
│ Configuration du système d'apprentissage continu                │
├─────────────────────────────────────────────────────────────────┤
│ Max réponses apprises   │ [3                     ]              │
│                         │ Nombre de cas similaires à inclure   │
│ Score minimum           │ [0.75                  ]              │
│                         │ Score minimum pour les réponses      │
│ Limite tokens contexte  │ [4000                  ]              │
│                         │ Limite de tokens pour le contexte    │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ Mode de fonctionnement                                          │
├─────────────────────────────────────────────────────────────────┤
│ Mode strict             │ [○]                                   │
│                         │ Ajoute automatiquement des garde-fous │
│                         │ contre les hallucinations. Recommandé │
│                         │ pour les agents factuels (support,    │
│                         │ BTP, médical).                        │
└─────────────────────────────────────────────────────────────────┘
```

---

## 5. Exemples de Configuration

### 5.1 Agent Expert BTP (strict, précis)

```json
{
  "min_rag_score": 0.65,
  "max_learned_responses": 5,
  "learned_min_score": 0.80,
  "context_token_limit": 6000,
  "strict_mode": true
}
```

**Justification :**
- Score élevé pour ne retourner que des documents très pertinents
- Plus de réponses apprises car domaine technique avec beaucoup de cas
- Mode strict car les prix et quantités doivent être exacts

### 5.2 Agent Support Client (équilibré)

```json
{
  "min_rag_score": 0.50,
  "max_learned_responses": 3,
  "learned_min_score": 0.75,
  "context_token_limit": 4000,
  "strict_mode": false
}
```

**Justification :**
- Score permissif pour couvrir plus de questions
- Moins de réponses apprises (procédures standardisées)
- Mode strict désactivé pour permettre reformulation naturelle

### 5.3 Agent Créatif (flexible)

```json
{
  "min_rag_score": 0.40,
  "max_learned_responses": 2,
  "learned_min_score": 0.70,
  "context_token_limit": 3000,
  "strict_mode": false
}
```

**Justification :**
- Score bas pour inspiration large
- Peu de réponses apprises (créativité vs reproduction)
- Mode strict désactivé pour liberté créative

---

## 6. Tests et Validation

### 6.1 Tests Unitaires

| Test | Description | Attendu |
|------|-------------|---------|
| `test_agent_uses_custom_min_rag_score` | Agent avec `min_rag_score=0.8` | RagService utilise 0.8 |
| `test_agent_fallback_to_config` | Agent sans `min_rag_score` défini | RagService utilise config globale |
| `test_strict_mode_adds_guardrails` | Agent avec `strict_mode=true` | Prompt contient "CONTRAINTES DE RÉPONSE" |
| `test_strict_mode_disabled` | Agent avec `strict_mode=false` | Prompt sans garde-fous additionnels |

### 6.2 Tests d'Intégration

1. Créer un agent avec configuration personnalisée via Filament
2. Envoyer une question via l'interface de test
3. Vérifier dans "Voir le contexte" que les paramètres sont appliqués
4. Comparer avec un agent utilisant les valeurs par défaut

---

## 7. Migration des Données

### 7.1 Stratégie

- Les nouveaux champs ont des valeurs par défaut
- Les agents existants continuent de fonctionner sans modification
- Les administrateurs peuvent ajuster les paramètres progressivement

### 7.2 Rollback

```php
public function down(): void
{
    Schema::table('agents', function (Blueprint $table) {
        $table->dropColumn([
            'min_rag_score',
            'max_learned_responses',
            'learned_min_score',
            'context_token_limit',
            'strict_mode',
        ]);
    });
}
```

---

## 8. Documentation Mise à Jour

- `docs/02_database_schema.md` : Schéma agents avec nouveaux champs
- `docs/03_ai_core_logic.md` : Section "Configuration par agent" détaillée

---

## 9. Historique des Versions

| Version | Date | Auteur | Description |
|---------|------|--------|-------------|
| 1.0 | 2025-12-25 | Claude | Création initiale |

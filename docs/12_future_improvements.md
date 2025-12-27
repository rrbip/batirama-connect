# 12 - Améliorations Futures (Machine Plus Puissante)

> **Statut** : 🚫 DOCUMENT DE RÉFLEXION - NE PAS DÉVELOPPER
> **Date** : Décembre 2025
> **Prérequis** : Infrastructure avec GPU dédié ou machine plus performante

---

## ⚠️ AVERTISSEMENT IMPORTANT

```
╔══════════════════════════════════════════════════════════════════════════════╗
║                                                                              ║
║   🚫  CE DOCUMENT EST UNIQUEMENT UNE RÉFLEXION TECHNIQUE                    ║
║                                                                              ║
║   AUCUN DÉVELOPPEMENT NE DOIT ÊTRE LANCÉ SANS :                             ║
║                                                                              ║
║   1. ✅ Un cahier des charges formel validé (comme 06_admin_panel.md)       ║
║   2. ✅ L'approbation explicite du client                                   ║
║   3. ✅ L'infrastructure machine disponible et validée                      ║
║   4. ✅ Un budget temps/ressources approuvé                                 ║
║                                                                              ║
║   Ce document capture des IDÉES pour ne pas les oublier.                    ║
║   Le code présenté est ILLUSTRATIF, pas une implémentation finale.          ║
║                                                                              ║
╚══════════════════════════════════════════════════════════════════════════════╝
```

### Processus obligatoire avant développement

| Étape | Description | Responsable |
|-------|-------------|-------------|
| 1 | Créer un cahier des charges détaillé (nouveau fichier .md) | Tech Lead |
| 2 | Valider les spécifications avec le client | Product Owner |
| 3 | Estimer le temps et les ressources | Équipe Dev |
| 4 | Obtenir l'approbation budget | Client |
| 5 | Vérifier les prérequis machine | DevOps |
| 6 | **Seulement alors** : Commencer le développement | Équipe Dev |

---

## Objectif

Ce document recense les améliorations planifiées qui nécessitent une **machine plus puissante** (GPU, RAM supplémentaire, ou modèles plus grands). Ces fonctionnalités sont différées pour une version ultérieure.

---

## 1. Reformulation Contextuelle des Questions (Query Rewriting)

### 1.1 Problème Actuel

Actuellement, la recherche RAG utilise directement la question de l'utilisateur pour générer l'embedding de recherche. Cela pose problème quand :

- La question contient des **références contextuelles** ("Quel est son prix ?", "Et pour ça ?")
- La question utilise des **pronoms** ("il", "elle", "celui-ci")
- La question fait référence à des **éléments de la conversation précédente**

**Exemple problématique :**
```
User: Quel est le prix du béton armé C25/30 ?
Bot: Le prix est de 95€/m³...

User: Et pour une dalle de 20m² ?
     ↑ La recherche RAG cherche "dalle 20m²" sans contexte béton
```

### 1.2 Solution : Query Rewriting par LLM

Avant la recherche RAG, utiliser un LLM rapide pour **reformuler la question** en une requête autonome :

```
┌─────────────────────────────────────────────────────────────────┐
│                    FLUX RAG AMÉLIORÉ                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Question: "Et pour une dalle de 20m² ?"                       │
│                      │                                         │
│                      ▼                                         │
│  ┌─────────────────────────────────────┐                       │
│  │ LLM Query Rewriter (rapide)         │                       │
│  │                                     │                       │
│  │ Contexte: historique conversation   │                       │
│  │ Instruction: reformuler en question │                       │
│  │              autonome               │                       │
│  └───────────────┬─────────────────────┘                       │
│                  │                                             │
│                  ▼                                             │
│  Question reformulée:                                          │
│  "Quelle quantité de béton armé C25/30 pour une dalle de 20m²?"│
│                  │                                             │
│                  ▼                                             │
│  ┌─────────────────────────────────────┐                       │
│  │ Embedding + Recherche Qdrant        │                       │
│  │ (plus pertinent maintenant)         │                       │
│  └───────────────┬─────────────────────┘                       │
│                  │                                             │
│                  ▼                                             │
│  Documents pertinents → LLM génération réponse                 │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 1.3 Prompt de Reformulation

```
Tu es un assistant qui reformule les questions pour les rendre autonomes.

Historique de conversation:
{conversation_history}

Question actuelle: {user_question}

Reformule cette question en une question autonome et complète qui peut être comprise sans contexte.
Si la question est déjà autonome, retourne-la telle quelle.
Retourne UNIQUEMENT la question reformulée, sans explication.
```

### 1.4 Implémentation Prévue

```php
// app/Services/AI/QueryRewriter.php

class QueryRewriter
{
    public function __construct(
        private OllamaService $ollama
    ) {}

    /**
     * Reformule une question en utilisant le contexte de conversation
     */
    public function rewrite(string $question, array $conversationHistory): string
    {
        // Si pas d'historique, pas besoin de reformuler
        if (empty($conversationHistory)) {
            return $question;
        }

        // Utiliser un modèle rapide pour la reformulation
        $response = $this->ollama->chat([
            [
                'role' => 'system',
                'content' => $this->getRewritePrompt($conversationHistory),
            ],
            [
                'role' => 'user',
                'content' => $question,
            ],
        ], [
            'model' => config('ai.query_rewrite.model', 'mistral:7b'),
            'temperature' => 0.1, // Très déterministe
            'max_tokens' => 200,
        ]);

        return trim($response->content);
    }

    private function getRewritePrompt(array $history): string
    {
        $formattedHistory = collect($history)
            ->map(fn ($msg) => "{$msg['role']}: {$msg['content']}")
            ->join("\n");

        return <<<PROMPT
Tu reformules les questions pour les rendre autonomes.

Historique:
{$formattedHistory}

Reformule la question suivante en une question complète et autonome.
Retourne UNIQUEMENT la question reformulée.
PROMPT;
    }
}
```

### 1.5 Intégration dans RagService

```php
// Dans RagService.php

public function query(Agent $agent, string $userMessage, ?AiSession $session = null): LLMResponse
{
    // 1. Récupérer l'historique de conversation
    $history = $this->getConversationHistory($session, $agent->context_window_size);

    // 2. [NOUVEAU] Reformuler la question si nécessaire
    if ($agent->enable_query_rewriting && !empty($history)) {
        $rewrittenQuery = $this->queryRewriter->rewrite($userMessage, $history);
        Log::info('Query rewritten', [
            'original' => $userMessage,
            'rewritten' => $rewrittenQuery,
        ]);
    } else {
        $rewrittenQuery = $userMessage;
    }

    // 3. Recherche RAG avec la question reformulée
    $ragResults = $this->retrieveContext($agent, $rewrittenQuery);

    // ... reste du traitement
}
```

### 1.6 Configuration Agent

Nouvelle option dans la configuration de l'agent :

| Champ | Type | Description |
|-------|------|-------------|
| `enable_query_rewriting` | boolean | Active la reformulation contextuelle |
| `query_rewrite_model` | string | Modèle à utiliser (défaut: mistral:7b) |

### 1.7 Prérequis Machine

| Ressource | Minimum | Recommandé |
|-----------|---------|------------|
| RAM | 16 GB | 32 GB |
| GPU VRAM | 8 GB | 16 GB |
| Modèle | mistral:7b | mistral-small (24B) |

**Impact performance** : Ajoute ~500ms-2s par requête selon le modèle.

---

## 2. Génération Augmentée avec Réflexion (Chain of Thought)

### 2.1 Description

Utiliser des techniques de "Chain of Thought" pour améliorer la qualité des réponses sur des questions complexes :

1. L'IA réfléchit étape par étape avant de répondre
2. Vérification croisée des sources RAG
3. Auto-correction si incohérence détectée

### 2.2 Prérequis

- Modèle plus grand (70B+ paramètres)
- GPU avec 48GB+ VRAM ou multi-GPU
- Temps de réponse acceptable (10-30s)

---

## 3. Génération d'Embeddings Avancés

### 3.1 Description

Remplacer `nomic-embed-text` par des modèles d'embedding plus performants :

| Modèle | Dimensions | Avantages |
|--------|------------|-----------|
| `mxbai-embed-large` | 1024 | Meilleure précision sémantique |
| `snowflake-arctic-embed` | 1024 | Optimisé multilingue |
| `bge-large` | 1024 | Bon pour le français |

### 3.2 Prérequis

- Ré-indexation complète des documents
- Plus de RAM pour Qdrant (vecteurs plus grands)

---

## 4. Multi-Agent Orchestration

### 4.1 Description

Permettre à plusieurs agents de collaborer sur une même question :

```
Question complexe → Orchestrateur
                    │
         ┌─────────┼─────────┐
         ▼         ▼         ▼
    Agent BTP  Agent Prix  Agent Réglementation
         │         │         │
         └─────────┴─────────┘
                   │
                   ▼
            Réponse synthétisée
```

### 4.2 Prérequis

- Plusieurs modèles en parallèle
- Multi-GPU ou cluster
- Orchestrateur intelligent

---

## 5. Fine-tuning Spécifique BTP

### 5.1 Description

Créer un modèle spécialisé BTP par fine-tuning :

1. Collecter les réponses validées (learned_responses)
2. Fine-tuner un modèle de base avec ces données
3. Déployer le modèle spécialisé

### 5.2 Prérequis

- GPU puissant pour l'entraînement (A100, H100)
- 10,000+ exemples de qualité
- Pipeline MLOps (expérimentation, versioning)

---

## 6. Extraction Vision OCR (Alternative à Tesseract)

### 6.1 Contexte

L'extraction actuelle utilise Tesseract OCR qui fonctionne bien pour le texte simple mais présente des limites :

| Limitation Tesseract | Impact |
|---------------------|--------|
| Perte de structure | Tableaux et colonnes mélangés |
| Texte "plat" | Difficile de savoir quel prix correspond à quel libellé |
| Pré-traitement requis | Images bruitées = mauvais résultats |

### 6.2 Solution : Modèles Vision Multimodaux

Utiliser un modèle Vision (LLaVA, Llama 3.2 Vision) capable de "voir" et comprendre le document :

```
┌─────────────────────────────────────────────────────────────────┐
│                WORKFLOW VISION OCR                               │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Document (PDF/Image)                                           │
│         │                                                       │
│         ▼                                                       │
│  ┌─────────────────────────────────────┐                       │
│  │ Conversion en image (pdftoppm)      │                       │
│  │ - 300 DPI                           │                       │
│  │ - Format PNG                        │                       │
│  └───────────────┬─────────────────────┘                       │
│                  │                                             │
│                  ▼                                             │
│  ┌─────────────────────────────────────┐                       │
│  │ Ollama Vision Model                 │                       │
│  │ (llava:13b ou llama3.2-vision)      │                       │
│  │                                     │                       │
│  │ Prompt: "Extrais le contenu de ce   │                       │
│  │ document en Markdown structuré.     │                       │
│  │ Préserve les tableaux et la mise    │                       │
│  │ en page."                           │                       │
│  └───────────────┬─────────────────────┘                       │
│                  │                                             │
│                  ▼                                             │
│  Sortie Markdown structuré:                                    │
│  - Titres (# ## ###)                                           │
│  - Tableaux (| col1 | col2 |)                                  │
│  - Listes (- item)                                             │
│                  │                                             │
│                  ▼                                             │
│  Chunking → Embedding → Qdrant                                 │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 6.3 Comparatif des Solutions OCR

| Solution | Intégration Laravel | Qualité Structure | Ressources | Cas d'usage |
|----------|-------------------|-------------------|------------|-------------|
| **Tesseract** | ⭐⭐⭐⭐⭐ (PHP natif) | ⭐ (Médiocre) | CPU léger | Texte simple |
| **Ollama Vision** | ⭐⭐⭐⭐ (API REST) | ⭐⭐⭐⭐⭐ | GPU requis | Documents structurés |
| **Got-OCR 2.0** | ⭐⭐ (Python) | ⭐⭐⭐⭐ (Markdown) | Moyenne | Archivage |
| **PaddleOCR** | ⭐⭐ (Python) | ⭐⭐⭐ | Moyenne | Multilingue |
| **API Cloud** | ⭐⭐⭐⭐ (SDK) | ⭐⭐⭐⭐⭐ | Payant | Zero maintenance |

### 6.4 Implémentation Prévue

```php
// app/Services/VisionExtractorService.php

class VisionExtractorService
{
    public function __construct(
        private OllamaService $ollama
    ) {}

    /**
     * Extrait le texte d'une image via un modèle Vision
     */
    public function extractFromImage(string $imagePath): string
    {
        $imageBase64 = base64_encode(file_get_contents($imagePath));

        $response = $this->ollama->chat([
            [
                'role' => 'user',
                'content' => $this->getExtractionPrompt(),
                'images' => [$imageBase64],
            ],
        ], [
            'model' => config('ai.vision.model', 'llava:13b'),
            'temperature' => 0.1,
        ]);

        return $response->content;
    }

    private function getExtractionPrompt(): string
    {
        return <<<PROMPT
Extrais le contenu de ce document en format Markdown structuré.

Instructions:
- Préserve la hiérarchie (titres, sous-titres)
- Conserve les tableaux au format Markdown
- Garde les listes à puces
- Indique les montants et prix clairement
- Si c'est une facture/devis, structure les données

Retourne UNIQUEMENT le contenu extrait, pas de commentaires.
PROMPT;
    }
}
```

### 6.5 Configuration Agent (Future)

Nouveau champ dans la configuration de l'agent :

| Champ | Type | Options | Description |
|-------|------|---------|-------------|
| `extraction_mode` | enum | `text`, `ocr`, `vision` | Mode d'extraction des documents |

```
┌─────────────────────────────────────────────────────────────────┐
│ Mode d'extraction                                               │
├─────────────────────────────────────────────────────────────────┤
│ ○ Texte (pdftotext) - Rapide, CPU uniquement                   │
│ ○ OCR (Tesseract)   - Pour images et PDFs scannés              │
│ ● Vision (LLaVA)    - Compréhension structure, GPU requis      │
└─────────────────────────────────────────────────────────────────┘
```

### 6.6 Prérequis Machine

| Ressource | Tesseract | Vision (LLaVA 13B) |
|-----------|-----------|-------------------|
| RAM | 2 GB | 16 GB |
| GPU VRAM | Non requis | 10+ GB |
| Temps/page | ~1s | ~10-30s |
| Qualité structure | ⭐ | ⭐⭐⭐⭐⭐ |

### 6.7 Modèles Vision Recommandés

| Modèle | VRAM | Qualité | Commande Ollama |
|--------|------|---------|-----------------|
| LLaVA 7B | 5 GB | Bonne | `ollama pull llava:7b` |
| LLaVA 13B | 10 GB | Très bonne | `ollama pull llava:13b` |
| Llama 3.2 Vision | 12 GB | Excellente | `ollama pull llama3.2-vision` |
| BakLLaVA | 8 GB | Bonne | `ollama pull bakllava` |

---

## 7. Roadmap d'Implémentation

### Phase 1 : Query Rewriting (Priorité Haute)

| Étape | Description | Effort |
|-------|-------------|--------|
| 1 | Créer `QueryRewriter` service | 1 jour |
| 2 | Intégrer dans `RagService` | 0.5 jour |
| 3 | Ajouter config agent | 0.5 jour |
| 4 | Tests et validation | 1 jour |
| 5 | Mise à jour documentation | 0.5 jour |

**Prérequis** : Machine avec 16GB+ RAM, GPU 8GB+

### Phase 2 : Embeddings Avancés (Priorité Moyenne)

| Étape | Description | Effort |
|-------|-------------|--------|
| 1 | Évaluer modèles d'embedding | 1 jour |
| 2 | Script de migration Qdrant | 1 jour |
| 3 | Ré-indexation complète | 0.5 jour |
| 4 | Validation qualité | 1 jour |

### Phase 3+ : Améliorations Majeures (Priorité Basse)

Ces améliorations nécessitent une infrastructure dédiée et seront planifiées ultérieurement.

---

## 7. Métriques de Succès

| Amélioration | Métrique | Objectif |
|--------------|----------|----------|
| Query Rewriting | Pertinence résultats RAG | +20% |
| Embeddings Avancés | Score similarité moyen | +15% |
| Chain of Thought | Qualité perçue réponses | +30% |
| Fine-tuning BTP | Réponses correctes | +40% |

---

## 8. Notes Techniques

### 8.1 Reformulation Simple Actuelle

Actuellement, une reformulation basique existe dans `RagService::reformulateQuery()` :

```php
private function reformulateQuery(string $query): string
{
    // Simplification basique - peut être amélioré avec un LLM
    $query = Str::lower($query);

    // Supprimer les mots interrogatifs
    $query = preg_replace(
        '/^(comment|quel|quelle|quels|quelles|combien|pourquoi|est-ce que)\s+/i',
        '',
        $query
    );

    // Supprimer la ponctuation finale
    $query = rtrim($query, '?!.');

    return trim($query);
}
```

Cette méthode est utilisée uniquement pour la recherche itérative et ne gère pas le contexte conversationnel.

### 8.2 Visualisation dans l'Admin

Une fois le Query Rewriting implémenté, la popup de contexte IA affichera :

```
┌────────────────────────────────────────────────────────────────┐
│ 🔄 0. Question reformulée                          [▼ ouvert]  │
├────────────────────────────────────────────────────────────────┤
│ Original: "Et pour une dalle de 20m² ?"                       │
│ Reformulée: "Quelle quantité de béton C25/30 pour 20m² ?"     │
└────────────────────────────────────────────────────────────────┘
```

---

## Validation

- [ ] Document validé par l'équipe technique
- [ ] Infrastructure définie pour Phase 1
- [ ] Budget machine approuvé

**Commentaires :**

_Ce document sera mis à jour quand les prérequis machine seront disponibles._

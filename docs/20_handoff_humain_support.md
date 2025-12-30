# Système de Handoff Humain pour le Support IA

## 1. Objectifs

### 1.1 Objectif principal
Permettre une transition fluide entre l'IA et un agent humain quand l'IA ne peut pas répondre avec confiance.

### 1.2 Objectifs secondaires
- **Éviter les hallucinations** : L'IA ne doit pas inventer de réponses
- **Collecter les cas non couverts** : Identifier les lacunes dans la base de connaissances
- **Entraîner l'IA** : Utiliser les résolutions humaines pour améliorer l'IA
- **Garantir une réponse** : L'utilisateur obtient toujours une aide, même si l'IA échoue

---

## 2. Flux utilisateur

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           FLUX COMPLET                                       │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  1. Question utilisateur                                                     │
│         ↓                                                                    │
│  2. RAG Search                                                               │
│         ↓                                                                    │
│  3. Score max >= seuil (60%) ?                                              │
│         │                                                                    │
│     OUI │                    NON                                             │
│         ↓                      ↓                                             │
│  4a. Réponse IA         4b. Escalade                                        │
│         │                      ↓                                             │
│         │               5. Admin connecté ?                                  │
│         │                   │         │                                      │
│         │               OUI │         │ NON                                  │
│         │                   ↓         ↓                                      │
│         │           6a. Chat live   6b. Mode email asynchrone               │
│         │                   │              ↓                                 │
│         │                   │       7. Email bidirectionnel                  │
│         │                   │              ↓                                 │
│         │                   │       8. Réponses par email parsées            │
│         │                   ↓              ↓                                 │
│         │           9. Résolution (live ou async)                           │
│         │                      ↓                                             │
│         │              10. Marquer comme résolu                              │
│         │                      ↓                                             │
│         │              11. Apprentissage IA (2 options)                      │
│         │                      ↓                                             │
│         └──────────────► 12. Feedback utilisateur (optionnel)               │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Modèle de données

### 3.1 Table `support_conversations`

```sql
CREATE TABLE support_conversations (
    id BIGSERIAL PRIMARY KEY,

    -- Contexte
    agent_id BIGINT REFERENCES agents(id),
    session_id VARCHAR(255) NOT NULL,           -- Session chat utilisateur
    user_id BIGINT REFERENCES users(id) NULL,   -- Si utilisateur connecté
    user_email VARCHAR(255) NULL,               -- Email pour communication async

    -- État
    status VARCHAR(50) NOT NULL DEFAULT 'active',
    -- 'active'          : Conversation en cours avec l'IA
    -- 'escalated'       : Transférée au support, en attente
    -- 'human_handling'  : Un admin a pris en charge
    -- 'resolved'        : Résolu
    -- 'abandoned'       : Utilisateur parti sans résolution

    -- Escalade
    escalation_reason VARCHAR(50) NULL,
    -- 'low_confidence'  : Score RAG trop bas
    -- 'user_request'    : Utilisateur a demandé un humain
    -- 'ai_uncertainty'  : IA a signalé son incertitude
    -- 'negative_feedback' : Feedback négatif sur réponse IA

    escalated_at TIMESTAMP NULL,

    -- Prise en charge admin
    assigned_admin_id BIGINT REFERENCES users(id) NULL,
    assigned_at TIMESTAMP NULL,

    -- Résolution
    resolved_at TIMESTAMP NULL,
    resolution_type VARCHAR(50) NULL,
    -- 'answered'        : Question répondue
    -- 'redirected'      : Redirigé vers autre service
    -- 'out_of_scope'    : Hors périmètre
    -- 'duplicate'       : Question déjà traitée

    resolution_notes TEXT NULL,

    -- Entraînement IA
    training_status VARCHAR(50) DEFAULT 'pending',
    -- 'pending'         : À traiter pour entraînement
    -- 'approved'        : Validé pour learned_response
    -- 'rejected'        : Non pertinent pour entraînement
    -- 'indexed'         : Ajouté aux learned_responses
    -- 'indexed_full'    : Indexé via pipeline document complet

    learned_response_id BIGINT REFERENCES learned_responses(id) NULL,
    indexed_document_id BIGINT REFERENCES documents(id) NULL,

    -- Token pour accès email
    access_token VARCHAR(64) NULL,
    access_token_expires_at TIMESTAMP NULL,

    -- Métadonnées
    metadata JSONB DEFAULT '{}',
    -- {
    --   "max_rag_score": 0.45,
    --   "sources_count": 3,
    --   "user_agent": "...",
    --   "ip_address": "...",
    --   "category_detected": "FACTURATION"
    -- }

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Index pour les requêtes fréquentes
CREATE INDEX idx_support_conv_status ON support_conversations(status);
CREATE INDEX idx_support_conv_agent ON support_conversations(agent_id);
CREATE INDEX idx_support_conv_training ON support_conversations(training_status);
CREATE INDEX idx_support_conv_escalated ON support_conversations(escalated_at) WHERE status = 'escalated';
CREATE INDEX idx_support_conv_token ON support_conversations(access_token) WHERE access_token IS NOT NULL;
```

### 3.2 Table `support_messages`

```sql
CREATE TABLE support_messages (
    id BIGSERIAL PRIMARY KEY,
    conversation_id BIGINT REFERENCES support_conversations(id) ON DELETE CASCADE,

    -- Expéditeur
    sender_type VARCHAR(20) NOT NULL,
    -- 'user'  : Message utilisateur
    -- 'ai'    : Réponse IA
    -- 'admin' : Réponse admin
    -- 'system': Message système (escalade, assignation, etc.)

    admin_id BIGINT REFERENCES users(id) NULL,

    -- Canal de communication
    channel VARCHAR(20) NOT NULL DEFAULT 'chat',
    -- 'chat'  : Message via widget/interface web
    -- 'email' : Message reçu/envoyé par email
    -- 'api'   : Message via API externe

    -- Contenu
    content TEXT NOT NULL,

    -- Contexte IA (pour les messages IA)
    ai_context JSONB NULL,
    -- {
    --   "rag_results": [...],
    --   "max_score": 0.78,
    --   "category_detection": {...},
    --   "model_used": "mistral:7b",
    --   "tokens": 1234,
    --   "generation_time_ms": 5000
    -- }

    confidence_score FLOAT NULL,           -- Score de confiance (0-1)
    was_escalated BOOLEAN DEFAULT FALSE,   -- Ce message a déclenché l'escalade ?

    -- Métadonnées email (si channel = 'email')
    email_metadata JSONB NULL,
    -- {
    --   "message_id": "<xxx@mail.com>",
    --   "in_reply_to": "<yyy@mail.com>",
    --   "from": "user@example.com",
    --   "subject": "Re: Support #123"
    -- }

    -- Feedback
    feedback_rating INTEGER NULL,          -- 1-5 étoiles ou -1/0/1
    feedback_comment TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_support_msg_conv ON support_messages(conversation_id);
CREATE INDEX idx_support_msg_sender ON support_messages(sender_type);
CREATE INDEX idx_support_msg_channel ON support_messages(channel);
```

### 3.3 Table `support_email_threads`

```sql
CREATE TABLE support_email_threads (
    id BIGSERIAL PRIMARY KEY,
    conversation_id BIGINT REFERENCES support_conversations(id) ON DELETE CASCADE,

    -- Adresse email unique pour cette conversation
    thread_email VARCHAR(255) NOT NULL UNIQUE,
    -- Format: support+conv_{id}_{token}@domain.com

    -- Email de l'utilisateur
    user_email VARCHAR(255) NOT NULL,

    -- Threading email
    last_message_id VARCHAR(255) NULL,     -- Message-ID du dernier email

    -- État
    is_active BOOLEAN DEFAULT TRUE,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_email_thread_conv ON support_email_threads(conversation_id);
CREATE INDEX idx_email_thread_email ON support_email_threads(thread_email);
```

### 3.4 Table `admin_availability`

```sql
CREATE TABLE admin_availability (
    id BIGSERIAL PRIMARY KEY,
    admin_id BIGINT REFERENCES users(id) ON DELETE CASCADE,

    -- Statut temps réel
    status VARCHAR(20) NOT NULL DEFAULT 'offline',
    -- 'online'   : Disponible pour chat live
    -- 'busy'     : En conversation
    -- 'away'     : Absent temporairement
    -- 'offline'  : Déconnecté

    -- Capacité
    current_conversations INTEGER DEFAULT 0,
    max_conversations INTEGER DEFAULT 5,

    -- Agents gérés (NULL = tous)
    agent_ids JSONB NULL,  -- [1, 2, 3] ou null pour tous

    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX idx_admin_avail_admin ON admin_availability(admin_id);
```

---

## 4. Configuration par agent

### 4.1 Nouveaux champs dans `agents`

```php
// Migration
Schema::table('agents', function (Blueprint $table) {
    // Seuil de confiance pour escalade (0.0 - 1.0)
    $table->float('escalation_threshold')->default(0.60);

    // Activer le support humain
    $table->boolean('human_support_enabled')->default(false);

    // Message affiché lors de l'escalade
    $table->text('escalation_message')->nullable();
    // Default: "Je n'ai pas trouvé d'information fiable pour répondre..."

    // Message si aucun admin disponible
    $table->text('no_admin_message')->nullable();
    // Default: "Notre équipe n'est pas disponible actuellement..."

    // Email de notification pour les escalades
    $table->string('support_email')->nullable();

    // Horaires de support (JSON)
    $table->json('support_hours')->nullable();
    // {"monday": {"start": "09:00", "end": "18:00"}, ...}
});
```

### 4.2 Interface Filament

```
Agent Settings → Support Humain
├── [x] Activer le support humain
├── Seuil de confiance: [0.60] (slider 0.0 - 1.0)
├── Message d'escalade: [textarea]
├── Message hors horaires: [textarea]
├── Email notifications: [support@example.com]
└── Horaires de support:
    ├── Lundi: [09:00] - [18:00]
    ├── Mardi: [09:00] - [18:00]
    └── ...
```

---

## 5. Logique d'escalade

### 5.1 Service d'escalade

```php
<?php

namespace App\Services\Support;

class EscalationService
{
    private const DEFAULT_THRESHOLD = 0.60;

    /**
     * Détermine si une question doit être escaladée
     */
    public function shouldEscalate(Agent $agent, array $ragResults, ?string $userRequest = null): array
    {
        // 1. Escalade demandée par l'utilisateur
        if ($userRequest === 'human' || str_contains(strtolower($userRequest ?? ''), 'parler à un humain')) {
            return ['should_escalate' => true, 'reason' => 'user_request'];
        }

        // 2. Support humain désactivé pour cet agent
        if (!$agent->human_support_enabled) {
            return ['should_escalate' => false, 'reason' => 'disabled'];
        }

        // 3. Vérifier le score de confiance
        $maxScore = collect($ragResults)->max('score') ?? 0;
        $threshold = $agent->escalation_threshold ?? self::DEFAULT_THRESHOLD;

        if ($maxScore < $threshold) {
            return [
                'should_escalate' => true,
                'reason' => 'low_confidence',
                'details' => [
                    'max_score' => $maxScore,
                    'threshold' => $threshold,
                    'sources_count' => count($ragResults),
                ]
            ];
        }

        return ['should_escalate' => false, 'reason' => 'sufficient_confidence'];
    }

    /**
     * Vérifie si un admin est disponible
     */
    public function getAvailableAdmin(Agent $agent): ?User
    {
        return AdminAvailability::query()
            ->where('status', 'online')
            ->where('current_conversations', '<', DB::raw('max_conversations'))
            ->where(function ($q) use ($agent) {
                $q->whereNull('agent_ids')
                  ->orWhereJsonContains('agent_ids', $agent->id);
            })
            ->orderBy('current_conversations', 'asc')
            ->first()
            ?->admin;
    }

    /**
     * Vérifie si on est dans les horaires de support
     */
    public function isWithinSupportHours(Agent $agent): bool
    {
        $hours = $agent->support_hours;
        if (empty($hours)) {
            return true; // Pas de restriction
        }

        $now = now();
        $dayName = strtolower($now->englishDayOfWeek);

        if (!isset($hours[$dayName])) {
            return false;
        }

        $start = Carbon::parse($hours[$dayName]['start']);
        $end = Carbon::parse($hours[$dayName]['end']);

        return $now->between($start, $end);
    }

    /**
     * Effectue l'escalade
     */
    public function escalate(
        SupportConversation $conversation,
        string $reason,
        array $context = []
    ): EscalationResult {
        $agent = $conversation->agent;

        // Mettre à jour la conversation
        $conversation->update([
            'status' => 'escalated',
            'escalation_reason' => $reason,
            'escalated_at' => now(),
            'metadata' => array_merge($conversation->metadata ?? [], $context),
        ]);

        // Créer message système
        SupportMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'system',
            'content' => $this->getEscalationMessage($agent, $reason),
            'was_escalated' => true,
            'ai_context' => $context,
        ]);

        // Chercher un admin disponible
        $availableAdmin = $this->getAvailableAdmin($agent);
        $isWithinHours = $this->isWithinSupportHours($agent);

        if ($availableAdmin && $isWithinHours) {
            // Assignation automatique
            $this->assignToAdmin($conversation, $availableAdmin);

            // Notification temps réel
            event(new ConversationEscalated($conversation, $availableAdmin));

            return new EscalationResult(
                success: true,
                mode: 'live',
                admin: $availableAdmin,
                message: $agent->escalation_message ?? "Un conseiller va vous répondre dans quelques instants."
            );
        }

        // Mode différé avec email
        $this->createAsyncEmailThread($conversation, $reason);

        return new EscalationResult(
            success: true,
            mode: 'async_email',
            admin: null,
            message: $agent->no_admin_message ??
                "Notre équipe n'est pas disponible actuellement. " .
                "Nous avons enregistré votre demande et vous répondrons par email dès que possible."
        );
    }
}
```

### 5.2 Intégration dans RagService

```php
// Dans RagService::chat()
public function chat(Agent $agent, string $query, ...): LLMResponse
{
    $retrieval = $this->retrieveContext($query, $agent, ...);

    // Vérifier si escalade nécessaire
    $escalationCheck = $this->escalationService->shouldEscalate(
        $agent,
        $retrieval['results'],
        $query
    );

    if ($escalationCheck['should_escalate']) {
        return $this->handleEscalation(
            $agent,
            $query,
            $retrieval,
            $conversation,
            $escalationCheck['reason'],
            $escalationCheck['details'] ?? []
        );
    }

    // Continue avec réponse IA normale...
}

private function handleEscalation(...): LLMResponse
{
    $result = $this->escalationService->escalate(
        $conversation,
        $reason,
        [
            'query' => $query,
            'max_rag_score' => collect($retrieval['results'])->max('score') ?? 0,
            'sources_count' => count($retrieval['results']),
            'category_detection' => $retrieval['category_detection'] ?? null,
        ]
    );

    return new LLMResponse(
        content: $result->message,
        metadata: [
            'escalated' => true,
            'escalation_mode' => $result->mode,
            'conversation_id' => $conversation->id,
            'assigned_admin' => $result->admin?->name,
        ]
    );
}
```

---

## 6. Interface Admin temps réel

### 6.1 Dashboard Support

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  🎧 Support Live                                              [🟢 En ligne] │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  📊 En attente: 3    👤 Mes conversations: 2    ✅ Résolues aujourd'hui: 15 │
│                                                                              │
├────────────────────────┬────────────────────────────────────────────────────┤
│  CONVERSATIONS         │  CONVERSATION #4521                                 │
│                        │                                                     │
│  🔴 #4523 (2 min)     │  Agent: Support BTP                                 │
│     "problème facture" │  Utilisateur: jean@example.com                     │
│     📧 email           │  Escalade: Score RAG 45% (seuil: 60%)              │
│                        │                                                     │
│  🟡 #4522 (5 min)     │  ─────────────────────────────────────────────────  │
│     "devis bloqué"     │                                                     │
│     💬 chat            │  [User 💬] Comment annuler une facture validée ?    │
│                        │                                                     │
│  🟢 #4521 (en cours)  │  [AI] Je n'ai pas trouvé d'information fiable...    │
│     "annuler facture"  │  Score: 45% | Sources: 2                            │
│     💬 chat            │                                                     │
│                        │  [System] Conversation transférée au support        │
│                        │                                                     │
│                        │  ─────────────────────────────────────────────────  │
│                        │                                                     │
│                        │  📝 Votre réponse:                                  │
│                        │  ┌─────────────────────────────────────────────┐   │
│                        │  │ Pour annuler une facture validée, vous      │   │
│                        │  │ devez créer un avoir...                     │   │
│                        │  └─────────────────────────────────────────────┘   │
│                        │                                                     │
│                        │  [Envoyer] [💾 Sauver Q/R ▼] [Clôturer ▼]          │
│                        │                                                     │
│                        │  ─────────────────────────────────────────────────  │
│                        │                                                     │
│                        │  📚 Sources RAG trouvées:                           │
│                        │  • "Gestion des avoirs" (45%)                       │
│                        │  • "Facturation" (38%)                              │
│                        │                                                     │
└────────────────────────┴────────────────────────────────────────────────────┘
```

### 6.2 Fonctionnalités

| Fonctionnalité | Description |
|----------------|-------------|
| **Liste temps réel** | WebSocket/Pusher pour nouvelles conversations |
| **Indicateurs visuels** | Temps d'attente, priorité, agent source, canal (chat/email) |
| **Prise en charge** | Un clic pour s'assigner la conversation |
| **Chat live** | Messages instantanés avec l'utilisateur |
| **Suggestions IA** | L'IA propose des réponses basées sur les sources |
| **Contexte RAG** | Voir les sources trouvées et les scores |
| **Historique** | Voir les messages précédents avec l'IA |
| **Actions rapides** | Templates de réponses, redirection, etc. |
| **Sauver Q/R** | Bouton pour sauvegarder une Q/R pendant le chat (voir section 7) |

### 6.3 Actions de clôture

```
[Clôturer ▼]
├── ✅ Résolu - Question répondue
│   ├── [ ] Sauver Q/R atomique (learned_response)
│   └── [ ] Indexer conversation complète (pipeline document)
├── ↗️ Redirigé - Vers autre service
├── ⛔ Hors périmètre - Question non supportée
└── 🔄 Doublon - Déjà traité
```

---

## 7. Système d'apprentissage IA (double flux)

### 7.1 Vue d'ensemble

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    DOUBLE FLUX D'APPRENTISSAGE                               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  OPTION 1: Q/R Atomiques (PENDANT le chat)                                  │
│  ──────────────────────────────────────────                                 │
│  • Bouton "💾 Sauver Q/R" sur chaque échange                                │
│  • Popup pour éditer/corriger la question et réponse                        │
│  • Réutilise le composant de la page Sessions                               │
│  • Crée directement une learned_response                                    │
│  • Indexation immédiate dans Qdrant                                         │
│  → IDÉAL POUR: Réponses précises, FAQ, questions fréquentes                │
│                                                                              │
│  OPTION 2: Conversation complète (APRÈS clôture)                            │
│  ───────────────────────────────────────────────                            │
│  • Checkbox "📄 Indexer la conversation" à la clôture                       │
│  • Transforme le chat en document Markdown                                  │
│  • Envoi vers le pipeline existant (chunking → Q/R → Qdrant)                │
│  • Réutilise le prompt Q/R de QrAtomiqueSetting                             │
│  → IDÉAL POUR: Cas complexes, debugging, procédures multi-étapes           │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 7.2 Option 1 : Q/R atomique pendant le chat

#### Composant Blade partagé

Le même composant est utilisé sur la page Sessions et le Support Live pour éviter la duplication :

```blade
{{-- resources/views/components/support/qr-correction-form.blade.php --}}
@props([
    'messageId',
    'originalQuestion',
    'originalAnswer',
    'wireMethod' => 'saveAsLearnedResponse',
    'agentId' => null,
])

<div x-data="{
    showForm: false,
    question: @js($originalQuestion),
    answer: @js($originalAnswer)
}">
    <x-filament::button
        size="xs"
        color="primary"
        icon="heroicon-o-bookmark"
        x-on:click="showForm = !showForm"
    >
        💾 Sauver Q/R
    </x-filament::button>

    <div x-show="showForm" x-cloak class="mt-3 space-y-3 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border">
        <div>
            <label class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">
                Question (modifiable)
            </label>
            <textarea
                x-model="question"
                rows="2"
                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm mt-1"
                placeholder="La question de l'utilisateur..."
            ></textarea>
        </div>
        <div>
            <label class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">
                Réponse (modifiable)
            </label>
            <textarea
                x-model="answer"
                rows="4"
                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm mt-1"
                placeholder="La réponse validée..."
            ></textarea>
        </div>
        <div class="flex gap-2">
            <x-filament::button
                size="xs"
                color="success"
                icon="heroicon-o-check"
                x-on:click="$wire.{{ $wireMethod }}({{ $messageId }}, question, answer); showForm = false"
            >
                Enregistrer dans la base
            </x-filament::button>
            <x-filament::button
                size="xs"
                color="gray"
                x-on:click="showForm = false"
            >
                Annuler
            </x-filament::button>
        </div>
    </div>
</div>
```

#### Utilisation dans les différents contextes

```blade
{{-- Page Sessions (existant) --}}
<x-support.qr-correction-form
    :messageId="$message->id"
    :originalQuestion="$userQuestion"
    :originalAnswer="$message->content"
    wireMethod="learnFromMessage"
/>

{{-- Page Support Live (nouveau) --}}
<x-support.qr-correction-form
    :messageId="$message->id"
    :originalQuestion="$userQuestion"
    :originalAnswer="$message->content"
    wireMethod="saveAsLearnedResponse"
    :agentId="$conversation->agent_id"
/>
```

#### Service de sauvegarde

```php
class SupportTrainingService
{
    /**
     * Sauvegarde une Q/R atomique depuis le support
     */
    public function saveQrPair(
        SupportConversation $conversation,
        string $question,
        string $answer,
        ?int $messageId = null
    ): LearnedResponse {
        // Créer la learned_response
        $learned = LearnedResponse::create([
            'agent_id' => $conversation->agent_id,
            'question' => $question,
            'answer' => $answer,
            'source' => 'human_support',
            'support_conversation_id' => $conversation->id,
            'is_active' => true,
        ]);

        // Indexer immédiatement dans Qdrant
        dispatch(new IndexLearnedResponseJob($learned));

        // Mettre à jour la conversation
        $conversation->update([
            'learned_response_id' => $learned->id,
            'training_status' => 'indexed',
        ]);

        return $learned;
    }
}
```

### 7.3 Option 2 : Conversation complète via pipeline

#### Flux de traitement

```
┌────────────────────────────────────────────────────────────────────────────┐
│               CHAT → MARKDOWN → PIPELINE EXISTANT                           │
├────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  1. Admin clôture la conversation                                          │
│         ↓                                                                   │
│  2. Coche "📄 Indexer la conversation complète"                            │
│         ↓                                                                   │
│  3. ConversationToMarkdownService::convert($conversation)                   │
│         ↓                                                                   │
│  4. Crée un Document avec type = 'support_conversation'                    │
│         ↓                                                                   │
│  5. Lance ProcessDocumentPipeline (même que les autres docs)               │
│         ↓                                                                   │
│  6. Chunking (markdown_text_splitter)                                      │
│         ↓                                                                   │
│  7. QrGeneratorService::processChunk()                                     │
│     → Utilise le MÊME prompt que pour les documents                        │
│         ↓                                                                   │
│  8. LLM extrait les Q/R du chunk de conversation                           │
│         ↓                                                                   │
│  9. Indexation Qdrant (qa_pair + source_material)                          │
│                                                                             │
└────────────────────────────────────────────────────────────────────────────┘
```

#### Service de conversion Markdown

```php
class ConversationToMarkdownService
{
    /**
     * Convertit une conversation support en document Markdown
     * optimisé pour le prompt Q/R existant
     */
    public function convert(SupportConversation $conversation): string
    {
        $messages = $conversation->messages()
            ->whereIn('sender_type', ['user', 'admin'])
            ->orderBy('created_at')
            ->get();

        $title = $this->extractTitle($conversation);

        $markdown = "# Résolution Support: {$title}\n\n";
        $markdown .= "**Agent**: {$conversation->agent->name}\n";
        $markdown .= "**Date**: {$conversation->created_at->format('d/m/Y')}\n";
        $markdown .= "**Catégorie**: {$this->detectCategory($conversation)}\n\n";
        $markdown .= "---\n\n";

        // Format optimisé pour extraction Q/R
        foreach ($messages as $msg) {
            if ($msg->sender_type === 'user') {
                $markdown .= "## Question utilisateur\n\n";
                $markdown .= $msg->content . "\n\n";
            } else {
                $markdown .= "## Réponse support\n\n";
                $markdown .= $msg->content . "\n\n";
            }
        }

        return $markdown;
    }

    private function extractTitle(SupportConversation $conversation): string
    {
        $firstUserMessage = $conversation->messages()
            ->where('sender_type', 'user')
            ->first();

        if ($firstUserMessage) {
            return Str::limit($firstUserMessage->content, 50);
        }

        return "Conversation #{$conversation->id}";
    }

    private function detectCategory(SupportConversation $conversation): string
    {
        return $conversation->metadata['category_detected'] ?? 'Support';
    }
}
```

#### Exemple de conversion

**Conversation support originale :**
```
User: Comment annuler une facture validée ?
Admin: Pour annuler une facture validée, vous devez créer un avoir.
       Allez dans Facturation > Avoirs > Nouveau, sélectionnez la facture concernée.
User: Et si la facture a déjà été payée ?
Admin: Si la facture est payée, vous devez d'abord annuler le paiement,
       puis créer l'avoir. Le remboursement sera automatiquement déclenché.
```

**Markdown généré :**
```markdown
# Résolution Support: Comment annuler une facture validée ?

**Agent**: Support BTP
**Date**: 30/12/2024
**Catégorie**: Facturation

---

## Question utilisateur

Comment annuler une facture validée ?

## Réponse support

Pour annuler une facture validée, vous devez créer un avoir.
Allez dans Facturation > Avoirs > Nouveau, sélectionnez la facture concernée.

## Question utilisateur

Et si la facture a déjà été payée ?

## Réponse support

Si la facture est payée, vous devez d'abord annuler le paiement,
puis créer l'avoir. Le remboursement sera automatiquement déclenché.
```

**LLM extrait (via le prompt QrAtomiqueSetting existant) :**
```json
{
  "useful": true,
  "category": "Facturation",
  "knowledge_units": [
    {
      "question": "Comment annuler une facture validée ?",
      "answer": "Pour annuler une facture validée, vous devez créer un avoir. Allez dans Facturation > Avoirs > Nouveau, puis sélectionnez la facture concernée."
    },
    {
      "question": "Comment annuler une facture déjà payée ?",
      "answer": "Si la facture est déjà payée, vous devez d'abord annuler le paiement, puis créer l'avoir. Le remboursement sera automatiquement déclenché."
    }
  ],
  "summary": "Procédure d'annulation de factures validées et payées via création d'avoirs."
}
```

#### Job d'indexation

```php
class IndexConversationAsDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected SupportConversation $conversation
    ) {}

    public function handle(ConversationToMarkdownService $converter): void
    {
        $markdown = $converter->convert($this->conversation);

        // Créer un Document
        $document = Document::create([
            'name' => "Support: " . $this->extractTitle(),
            'type' => 'support_conversation',
            'content' => $markdown,
            'source_type' => 'support_conversation',
            'source_id' => $this->conversation->id,
            'agent_id' => $this->conversation->agent_id,
            'status' => 'pending',
        ]);

        // Lancer le pipeline d'indexation standard
        dispatch(new ProcessDocumentPipeline($document));

        // Mettre à jour la conversation
        $this->conversation->update([
            'indexed_document_id' => $document->id,
            'training_status' => 'indexed_full',
        ]);
    }

    private function extractTitle(): string
    {
        $firstMessage = $this->conversation->messages()
            ->where('sender_type', 'user')
            ->first();

        return Str::limit($firstMessage?->content ?? "Conversation #{$this->conversation->id}", 50);
    }
}
```

### 7.4 Comparaison des deux options

| Critère | Option 1: Q/R Atomique | Option 2: Pipeline complet |
|---------|------------------------|---------------------------|
| **Quand** | Pendant le chat | Après clôture |
| **Granularité** | Une Q/R précise | Toutes les Q/R de la conversation |
| **Contrôle** | Admin choisit et édite chaque Q/R | LLM extrait automatiquement |
| **Indexation** | Immédiate | Via pipeline (quelques minutes) |
| **Idéal pour** | FAQ, questions simples | Cas complexes, procédures |
| **Réutilisation code** | Composant Sessions | Pipeline documents |

---

## 8. Intégration Email bidirectionnelle

### 8.1 Vue d'ensemble

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    FLUX EMAIL BIDIRECTIONNEL                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  1. Escalade sans admin connecté                                            │
│         ↓                                                                    │
│  2. Création thread email unique: support+conv_123_abc@domain.com           │
│         ↓                                                                    │
│  3. Email envoyé à l'utilisateur:                                           │
│     "Votre demande #123 a été enregistrée"                                  │
│     + Bouton "💬 Continuer sur le chat"                                     │
│         ↓                                                                    │
│  4. Admin répond via dashboard                                              │
│         ↓                                                                    │
│  5. Email envoyé à l'utilisateur avec:                                      │
│     - La réponse de l'admin                                                 │
│     - Reply-To: support+conv_123_abc@domain.com                             │
│     - Bouton "💬 Continuer sur le chat"                                     │
│         ↓                                                                    │
│  6. Utilisateur répond par email                                            │
│         ↓                                                                    │
│  7. Job FetchIncomingEmailsJob récupère le mail                             │
│         ↓                                                                    │
│  8. EmailReplyParser extrait UNIQUEMENT le nouveau message                  │
│         ↓                                                                    │
│  9. Crée SupportMessage(sender_type: 'user', channel: 'email')              │
│         ↓                                                                    │
│  10. Notification temps réel à l'admin dans le dashboard                    │
│         ↓                                                                    │
│  (boucle 4-10 jusqu'à résolution)                                           │
│                                                                              │
│  ✅ AVANTAGE: Toute la communication est centralisée dans le back-office   │
│               pour l'apprentissage et l'historique                          │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 8.2 Service de parsing email

```php
<?php

namespace App\Services\Support;

class EmailReplyParser
{
    /**
     * Extrait uniquement le nouveau contenu d'un email de réponse
     * Supprime les citations, signatures, et historique
     */
    public function extractReply(string $emailBody): string
    {
        $lines = explode("\n", $emailBody);
        $replyLines = [];

        foreach ($lines as $line) {
            // Arrêter aux marqueurs de citation courants
            if ($this->isQuoteMarker($line)) {
                break;
            }

            // Ignorer les lignes citées (commençant par >)
            if (str_starts_with(trim($line), '>')) {
                continue;
            }

            // Arrêter à la signature
            if ($this->isSignatureMarker($line)) {
                break;
            }

            $replyLines[] = $line;
        }

        return trim(implode("\n", $replyLines));
    }

    protected function isQuoteMarker(string $line): bool
    {
        $markers = [
            '/^-{3,}\s*Original Message\s*-{3,}/i',
            '/^-{3,}\s*Message original\s*-{3,}/i',
            '/^Le \d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}.*a écrit\s*:/i',
            '/^On .* wrote:/i',
            '/^From:.*Sent:/is',
            '/^De\s*:.*Envoyé\s*:/is',
            '/^_{5,}/',
            '/^\*{5,}/',
        ];

        foreach ($markers as $pattern) {
            if (preg_match($pattern, trim($line))) {
                return true;
            }
        }

        return false;
    }

    protected function isSignatureMarker(string $line): bool
    {
        $markers = [
            '/^--\s*$/',           // Standard signature separator
            '/^_{3,}$/',           // Underscores
            '/^Cordialement/i',
            '/^Bien cordialement/i',
            '/^Best regards/i',
            '/^Envoyé depuis/i',   // "Envoyé depuis mon iPhone"
            '/^Sent from/i',
        ];

        foreach ($markers as $pattern) {
            if (preg_match($pattern, trim($line))) {
                return true;
            }
        }

        return false;
    }
}
```

### 8.3 Job de récupération des emails

```php
<?php

namespace App\Jobs\Support;

class FetchIncomingEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(
        MailboxService $mailbox,
        EmailReplyParser $parser
    ): void {
        // Récupérer les nouveaux emails (IMAP ou webhook Mailgun/SendGrid)
        $emails = $mailbox->fetchUnread();

        foreach ($emails as $email) {
            $this->processEmail($email, $parser);
        }
    }

    private function processEmail(IncomingEmail $email, EmailReplyParser $parser): void
    {
        // Parser l'adresse de destination pour trouver la conversation
        // Format: support+conv_{id}_{token}@domain.com
        if (!preg_match('/support\+conv_(\d+)_([a-z0-9]+)@/i', $email->to, $matches)) {
            Log::warning('Email reçu avec adresse non reconnue', ['to' => $email->to]);
            return;
        }

        $conversationId = (int) $matches[1];
        $token = $matches[2];

        // Vérifier la conversation
        $conversation = SupportConversation::find($conversationId);
        if (!$conversation) {
            Log::warning('Conversation non trouvée', ['id' => $conversationId]);
            return;
        }

        // Vérifier le token (sécurité)
        $thread = $conversation->emailThread;
        if (!$thread || !Str::contains($thread->thread_email, $token)) {
            Log::warning('Token email invalide', ['conversation_id' => $conversationId]);
            return;
        }

        // Extraire uniquement le nouveau contenu (pas les citations)
        $cleanContent = $parser->extractReply($email->textBody ?? $email->htmlBody);

        if (empty(trim($cleanContent))) {
            Log::info('Email vide après parsing', ['conversation_id' => $conversationId]);
            return;
        }

        // Créer le message
        SupportMessage::create([
            'conversation_id' => $conversationId,
            'sender_type' => 'user',
            'channel' => 'email',
            'content' => $cleanContent,
            'email_metadata' => [
                'message_id' => $email->messageId,
                'from' => $email->from,
                'subject' => $email->subject,
                'received_at' => now()->toIso8601String(),
            ],
        ]);

        // Mettre à jour le thread
        $thread->update(['last_message_id' => $email->messageId]);

        // Réactiver la conversation si elle était résolue
        if ($conversation->status === 'resolved') {
            $conversation->update(['status' => 'escalated']);
        }

        // Notifier les admins en temps réel
        event(new NewSupportMessage($conversation));

        Log::info('Email traité et rattaché à la conversation', [
            'conversation_id' => $conversationId,
            'content_length' => strlen($cleanContent),
        ]);
    }
}
```

### 8.4 Template email avec bouton retour chat

```blade
{{-- resources/views/emails/support/response.blade.php --}}
@component('mail::message')
# Réponse à votre demande #{{ $conversation->id }}

Bonjour,

Notre équipe a répondu à votre demande :

@component('mail::panel')
{{ $message->content }}
@endcomponent

---

**Vous pouvez répondre directement à cet email** pour continuer la conversation.

Ou si vous préférez, utilisez notre interface de chat :

@component('mail::button', ['url' => $chatUrl, 'color' => 'primary'])
💬 Continuer sur le chat
@endcomponent

@if($adminAvailable)
<small>Un conseiller est actuellement disponible pour vous répondre en direct.</small>
@else
<small>Notre équipe vous répondra dès que possible.</small>
@endif

Cordialement,<br>
{{ $conversation->agent->name }}
@endcomponent
```

### 8.5 Contrôleur de reprise de chat

```php
<?php

namespace App\Http\Controllers;

class SupportChatController extends Controller
{
    /**
     * Reprendre une conversation depuis un lien email
     */
    public function resumeFromEmail(Request $request, SupportConversation $conversation)
    {
        // Vérifier le token d'accès
        if (!$this->validateAccessToken($request->token, $conversation)) {
            abort(403, 'Lien expiré ou invalide');
        }

        // Vérifier si un admin est disponible
        $adminAvailable = AdminAvailability::where('status', 'online')
            ->where('current_conversations', '<', DB::raw('max_conversations'))
            ->where(function ($q) use ($conversation) {
                $q->whereNull('agent_ids')
                  ->orWhereJsonContains('agent_ids', $conversation->agent_id);
            })
            ->exists();

        // Charger les messages
        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->get();

        return view('support.chat-widget', [
            'conversation' => $conversation,
            'messages' => $messages,
            'adminAvailable' => $adminAvailable,
            'mode' => $adminAvailable ? 'live' : 'async',
        ]);
    }

    private function validateAccessToken(?string $token, SupportConversation $conversation): bool
    {
        if (!$token || !$conversation->access_token) {
            return false;
        }

        if ($conversation->access_token !== $token) {
            return false;
        }

        if ($conversation->access_token_expires_at &&
            $conversation->access_token_expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
```

---

## 9. Analytiques et reporting

### 9.1 Métriques à suivre

| Métrique | Description | Objectif |
|----------|-------------|----------|
| **Taux d'escalade** | % conversations escaladées | < 20% |
| **Temps de réponse** | Délai entre escalade et réponse | < 5 min (live) |
| **Taux de résolution** | % escalades résolues | > 95% |
| **CSAT** | Satisfaction après résolution | > 4/5 |
| **Réutilisation** | % questions similaires après training | Croissant |
| **Canal** | Répartition chat vs email | - |

### 9.2 Dashboard analytique

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  📈 Analytiques Support                                      [Cette semaine]│
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  Conversations totales: 1,234                                               │
│  ├── Gérées par IA: 1,012 (82%)                                            │
│  └── Escaladées: 222 (18%)                                                 │
│      ├── Résolues: 210 (95%)                                               │
│      ├── En cours: 8                                                        │
│      └── Abandonnées: 4                                                     │
│                                                                              │
│  Par canal:                                                                  │
│  ├── Chat live: 156 (70%)                                                  │
│  └── Email async: 66 (30%)                                                 │
│                                                                              │
│  Temps de réponse moyen: 3 min 24 sec                                       │
│  Satisfaction moyenne: 4.2/5                                                │
│                                                                              │
│  Apprentissage IA:                                                          │
│  ├── Q/R atomiques créées: 45                                              │
│  └── Conversations indexées: 12                                            │
│                                                                              │
│  Top 5 questions escaladées:                                                │
│  1. "Comment annuler une facture ?" (15 fois)                               │
│  2. "Problème connexion API" (12 fois)                                      │
│  3. "Export comptable" (10 fois)                                            │
│  4. "Modifier un devis signé" (8 fois)                                      │
│  5. "Erreur E-4521" (7 fois)                                                │
│                                                                              │
│  💡 Suggestion: Créer une FAQ pour "Annulation facture" (15 escalades)      │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 10. Plan d'implémentation

### Phase 1 : Base
- [ ] Migrations (tables support_conversations, support_messages, admin_availability, support_email_threads)
- [ ] Models Eloquent + relations
- [ ] EscalationService (logique de base)
- [ ] Intégration RagService (détection escalade)
- [ ] Message utilisateur lors de l'escalade

### Phase 2 : Interface Admin
- [ ] Page Filament "Support Live"
- [ ] Liste des conversations escaladées (avec indicateur canal chat/email)
- [ ] Vue conversation avec historique
- [ ] Formulaire de réponse
- [ ] Actions de clôture

### Phase 3 : Temps réel
- [ ] Configuration Laravel Echo + Pusher/Soketi
- [ ] Events (ConversationEscalated, NewMessage, etc.)
- [ ] Listeners côté admin
- [ ] Notifications sonores

### Phase 4 : Email bidirectionnel
- [ ] Configuration boîte mail (IMAP ou webhook Mailgun/SendGrid)
- [ ] EmailReplyParser pour extraire les réponses
- [ ] FetchIncomingEmailsJob (scheduler toutes les minutes)
- [ ] Templates email avec bouton retour chat
- [ ] Contrôleur de reprise de conversation

### Phase 5 : Apprentissage IA (double flux)
- [ ] Composant Blade partagé `<x-support.qr-correction-form>`
- [ ] Intégration dans page Sessions (refactor existant)
- [ ] Intégration dans page Support Live
- [ ] ConversationToMarkdownService
- [ ] IndexConversationAsDocumentJob
- [ ] Options de clôture avec checkboxes apprentissage

### Phase 6 : Analytiques
- [ ] Dashboard métriques
- [ ] Export rapports
- [ ] Alertes (taux escalade élevé, temps réponse long)
- [ ] Suggestions automatiques (FAQ à créer)

---

## 11. Technologies recommandées

| Composant | Technologie | Raison |
|-----------|-------------|--------|
| **Temps réel** | Laravel Echo + Pusher/Soketi | Intégration native Laravel |
| **UI Admin** | Filament | Déjà utilisé dans le projet |
| **Notifications** | Laravel Notifications | Email + Push + Slack |
| **Queue** | Redis + Horizon | Performance et monitoring |
| **Cache** | Redis | Sessions, availability |
| **Email entrant** | Mailgun/SendGrid webhooks ou IMAP | Parsing des réponses |

---

## 12. Questions ouvertes

1. **Authentification utilisateur** : Obligatoire ou optionnel pour le chat ?
2. **Multi-langue** : Support messages en plusieurs langues ?
3. **Pièces jointes** : Permettre upload de fichiers/screenshots ?
4. **Chatbot widget** : Intégrer sur sites externes ou uniquement backoffice ?
5. **SLA** : Définir des niveaux de service avec alertes ?
6. **Escalade en chaîne** : Permettre escalade admin → admin senior ?
7. **Fournisseur email** : Mailgun, SendGrid, ou IMAP direct ?

---

## 13. Fichiers à créer

```
app/
├── Models/
│   ├── SupportConversation.php
│   ├── SupportMessage.php
│   ├── SupportEmailThread.php
│   └── AdminAvailability.php
├── Services/
│   └── Support/
│       ├── EscalationService.php
│       ├── ConversationService.php
│       ├── SupportTrainingService.php
│       ├── ConversationToMarkdownService.php
│       └── EmailReplyParser.php
├── Events/
│   ├── ConversationEscalated.php
│   ├── ConversationAssigned.php
│   ├── NewSupportMessage.php
│   └── ConversationResolved.php
├── Listeners/
│   └── Support/
│       └── NotifyAdminsListener.php
├── Jobs/
│   └── Support/
│       ├── NotifyAdminsOfEscalation.php
│       ├── NotifyUserOfResponse.php
│       ├── FetchIncomingEmailsJob.php
│       ├── IndexLearnedResponseJob.php
│       └── IndexConversationAsDocumentJob.php
├── Mail/
│   ├── NewEscalatedConversation.php
│   ├── SupportResponseReceived.php
│   └── ConversationConfirmation.php
├── Filament/
│   └── Pages/
│       ├── LiveSupport.php
│       └── SupportAnalytics.php
├── Http/
│   └── Controllers/
│       ├── SupportChatController.php
│       └── Api/
│           └── SupportWebhookController.php
database/
└── migrations/
    ├── xxxx_create_support_conversations_table.php
    ├── xxxx_create_support_messages_table.php
    ├── xxxx_create_support_email_threads_table.php
    ├── xxxx_create_admin_availability_table.php
    └── xxxx_add_support_fields_to_agents_table.php
resources/
└── views/
    ├── components/
    │   └── support/
    │       └── qr-correction-form.blade.php
    ├── emails/
    │   └── support/
    │       ├── escalation-confirmation.blade.php
    │       └── response.blade.php
    ├── filament/
    │   └── pages/
    │       ├── live-support.blade.php
    │       └── support-analytics.blade.php
    └── support/
        └── chat-widget.blade.php
```

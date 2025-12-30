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
│         │           6a. Chat live   6b. Ticket différé                      │
│         │                   │              ↓                                 │
│         │                   │       7. Email notification                    │
│         │                   │              ↓                                 │
│         │                   │       8. Admin répond plus tard                │
│         │                   ↓              ↓                                 │
│         │           9. Résolution (live ou différée)                        │
│         │                      ↓                                             │
│         │              10. Marquer comme résolu                              │
│         │                      ↓                                             │
│         │              11. Proposer création learned_response                │
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

    learned_response_id BIGINT REFERENCES learned_responses(id) NULL,

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

    -- Feedback
    feedback_rating INTEGER NULL,          -- 1-5 étoiles ou -1/0/1
    feedback_comment TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_support_msg_conv ON support_messages(conversation_id);
CREATE INDEX idx_support_msg_sender ON support_messages(sender_type);
```

### 3.3 Table `admin_availability`

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

        // Mode différé
        $this->createDeferredTicket($conversation, $reason);

        return new EscalationResult(
            success: true,
            mode: 'deferred',
            admin: null,
            message: $agent->no_admin_message ??
                "Notre équipe n'est pas disponible actuellement. " .
                "Nous avons enregistré votre demande et vous répondrons dès que possible."
        );
    }

    /**
     * Crée un ticket différé
     */
    private function createDeferredTicket(SupportConversation $conversation, string $reason): void
    {
        // Envoyer email de notification
        if ($email = $conversation->agent->support_email) {
            Mail::to($email)->queue(new NewEscalatedConversation($conversation));
        }

        // Log pour monitoring
        Log::info('Support conversation escalated (deferred)', [
            'conversation_id' => $conversation->id,
            'agent_id' => $conversation->agent_id,
            'reason' => $reason,
        ]);
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
│                        │  Escalade: Score RAG 45% (seuil: 60%)              │
│  🟡 #4522 (5 min)     │                                                     │
│     "devis bloqué"     │  ─────────────────────────────────────────────────  │
│                        │                                                     │
│  🟢 #4521 (en cours)  │  [User] Comment annuler une facture validée ?       │
│     "annuler facture"  │                                                     │
│                        │  [AI] Je n'ai pas trouvé d'information fiable...    │
│                        │  Score: 45% | Sources: 2                            │
│                        │                                                     │
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
│                        │  [Envoyer] [Suggestions IA ▼] [Clôturer ▼]         │
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
| **Indicateurs visuels** | Temps d'attente, priorité, agent source |
| **Prise en charge** | Un clic pour s'assigner la conversation |
| **Chat live** | Messages instantanés avec l'utilisateur |
| **Suggestions IA** | L'IA propose des réponses basées sur les sources |
| **Contexte RAG** | Voir les sources trouvées et les scores |
| **Historique** | Voir les messages précédents avec l'IA |
| **Actions rapides** | Templates de réponses, redirection, etc. |

### 6.3 Actions de clôture

```
[Clôturer ▼]
├── ✅ Résolu - Question répondue
│   └── [x] Créer une learned_response avec cette réponse
├── ↗️ Redirigé - Vers autre service
├── ⛔ Hors périmètre - Question non supportée
└── 🔄 Doublon - Déjà traité
```

---

## 7. Système de feedback et entraînement

### 7.1 Flux de feedback

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        CYCLE D'AMÉLIORATION                                  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  1. Conversation résolue par admin                                          │
│         ↓                                                                    │
│  2. Admin marque: "Créer learned_response" ?                                │
│         │                                                                    │
│     OUI │                    NON                                             │
│         ↓                      ↓                                             │
│  3. training_status = 'approved'    training_status = 'rejected'            │
│         ↓                                                                    │
│  4. Job: CreateLearnedResponseJob                                           │
│         ↓                                                                    │
│  5. Créer learned_response avec:                                            │
│     - question = question utilisateur                                        │
│     - answer = réponse admin                                                 │
│     - agent_id = agent source                                                │
│     - source = 'human_support'                                               │
│     - support_conversation_id = conversation                                 │
│         ↓                                                                    │
│  6. training_status = 'indexed'                                             │
│         ↓                                                                    │
│  7. Prochaine question similaire → IA répond directement                    │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 7.2 Dashboard d'entraînement

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  🎓 Entraînement IA - Résolutions humaines                                  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  📊 Statistiques                                                             │
│  ├── En attente de validation: 12                                           │
│  ├── Validées (à indexer): 5                                                │
│  ├── Indexées ce mois: 45                                                   │
│  └── Rejetées: 8                                                            │
│                                                                              │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  📝 Résolutions en attente                                                   │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │ #4521 | Agent: Support BTP | Admin: Marie | 2024-12-30                  ││
│  │                                                                          ││
│  │ Q: Comment annuler une facture validée ?                                 ││
│  │                                                                          ││
│  │ R: Pour annuler une facture validée, vous devez créer un avoir.         ││
│  │    Allez dans Facturation > Avoirs > Nouveau, sélectionnez la facture   ││
│  │    concernée et validez l'avoir.                                         ││
│  │                                                                          ││
│  │ [✅ Valider pour entraînement] [✏️ Modifier] [❌ Rejeter]                ││
│  └─────────────────────────────────────────────────────────────────────────┘│
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │ #4518 | Agent: FAQ Produit | Admin: Pierre | 2024-12-30                 ││
│  │ ...                                                                      ││
│  └─────────────────────────────────────────────────────────────────────────┘│
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 7.3 Ajout à learned_responses

```php
// Migration: ajouter champs source à learned_responses
Schema::table('learned_responses', function (Blueprint $table) {
    $table->string('source')->default('manual');
    // 'manual'         : Créé manuellement par admin
    // 'human_support'  : Créé depuis résolution support
    // 'feedback'       : Créé depuis feedback positif
    // 'import'         : Importé depuis fichier

    $table->foreignId('support_conversation_id')
          ->nullable()
          ->constrained('support_conversations')
          ->nullOnDelete();
});
```

---

## 8. Cas admin non connecté

### 8.1 Mode différé

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     MODE DIFFÉRÉ (Admin absent)                              │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  1. Escalade déclenchée                                                      │
│         ↓                                                                    │
│  2. Aucun admin disponible OU hors horaires                                 │
│         ↓                                                                    │
│  3. Afficher message personnalisé:                                          │
│     "Notre équipe n'est pas disponible actuellement.                        │
│      Nous avons enregistré votre demande et vous répondrons                 │
│      dès que possible par email."                                           │
│         ↓                                                                    │
│  4. Demander email (si pas connecté):                                       │
│     [Votre email: ________________] [Envoyer]                               │
│         ↓                                                                    │
│  5. Créer ticket avec status = 'escalated'                                  │
│         ↓                                                                    │
│  6. Envoyer notification par email aux admins                               │
│         ↓                                                                    │
│  7. Admin répond via dashboard (quand disponible)                           │
│         ↓                                                                    │
│  8. Email envoyé à l'utilisateur avec la réponse                            │
│         ↓                                                                    │
│  9. Si utilisateur revient sur le chat → voir l'historique                  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 8.2 Notifications

```php
// Jobs de notification
class NotifyAdminsOfEscalation implements ShouldQueue
{
    public function handle(): void
    {
        // Email aux admins
        $admins = User::permission('support.handle')->get();

        foreach ($admins as $admin) {
            Mail::to($admin)->queue(new NewEscalatedConversation($this->conversation));
        }

        // Notification push (si configuré)
        // Slack/Discord webhook (si configuré)
    }
}

class NotifyUserOfResponse implements ShouldQueue
{
    public function handle(): void
    {
        if ($email = $this->conversation->getUserEmail()) {
            Mail::to($email)->queue(new SupportResponseReceived(
                $this->conversation,
                $this->message
            ));
        }
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
│  Temps de réponse moyen: 3 min 24 sec                                       │
│  Satisfaction moyenne: 4.2/5                                                │
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

### Phase 1 : Base (1-2 semaines)
- [ ] Migrations (tables support_conversations, support_messages, admin_availability)
- [ ] Models Eloquent + relations
- [ ] EscalationService (logique de base)
- [ ] Intégration RagService (détection escalade)
- [ ] Message utilisateur lors de l'escalade

### Phase 2 : Interface Admin (1-2 semaines)
- [ ] Page Filament "Support Live"
- [ ] Liste des conversations escaladées
- [ ] Vue conversation avec historique
- [ ] Formulaire de réponse
- [ ] Actions de clôture

### Phase 3 : Temps réel (1 semaine)
- [ ] Configuration Laravel Echo + Pusher/Soketi
- [ ] Events (ConversationEscalated, NewMessage, etc.)
- [ ] Listeners côté admin
- [ ] Notifications sonores

### Phase 4 : Mode différé (1 semaine)
- [ ] Gestion horaires de support
- [ ] Emails de notification (admins + utilisateurs)
- [ ] Reprise conversation par email
- [ ] File d'attente des tickets

### Phase 5 : Entraînement IA (1 semaine)
- [ ] Interface validation des résolutions
- [ ] Job CreateLearnedResponseJob
- [ ] Lien learned_responses ↔ support_conversations
- [ ] Dashboard analytique entraînement

### Phase 6 : Analytiques (1 semaine)
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

---

## 12. Questions ouvertes

1. **Authentification utilisateur** : Obligatoire ou optionnel pour le chat ?
2. **Multi-langue** : Support messages en plusieurs langues ?
3. **Pièces jointes** : Permettre upload de fichiers/screenshots ?
4. **Chatbot widget** : Intégrer sur sites externes ou uniquement backoffice ?
5. **SLA** : Définir des niveaux de service avec alertes ?
6. **Escalade en chaîne** : Permettre escalade admin → admin senior ?

---

## 13. Fichiers à créer

```
app/
├── Models/
│   ├── SupportConversation.php
│   ├── SupportMessage.php
│   └── AdminAvailability.php
├── Services/
│   └── Support/
│       ├── EscalationService.php
│       ├── ConversationService.php
│       └── TrainingService.php
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
│       └── CreateLearnedResponseJob.php
├── Mail/
│   ├── NewEscalatedConversation.php
│   └── SupportResponseReceived.php
├── Filament/
│   └── Pages/
│       ├── LiveSupport.php
│       └── SupportTraining.php
├── Http/
│   └── Controllers/
│       └── Api/
│           └── SupportChatController.php
database/
└── migrations/
    ├── xxxx_create_support_conversations_table.php
    ├── xxxx_create_support_messages_table.php
    ├── xxxx_create_admin_availability_table.php
    └── xxxx_add_support_fields_to_agents_table.php
resources/
└── views/
    └── filament/
        └── pages/
            ├── live-support.blade.php
            └── support-training.blade.php
```

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

### 2.1 Collecte de l'email utilisateur

Quand l'escalade est déclenchée sans admin connecté, le widget de chat demande l'email :

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  COLLECTE EMAIL (mode asynchrone)                                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  [AI] Je n'ai pas trouvé d'information fiable pour répondre à votre        │
│       question avec certitude.                                               │
│                                                                              │
│  [System] Aucun conseiller n'est disponible pour le moment.                 │
│                                                                              │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │  📧 Laissez-nous votre email pour recevoir une réponse :               │ │
│  │                                                                         │ │
│  │  ┌───────────────────────────────────────────────────────────────────┐ │ │
│  │  │ votre@email.com                                                   │ │ │
│  │  └───────────────────────────────────────────────────────────────────┘ │ │
│  │                                                                         │ │
│  │  📎 Ajouter une pièce jointe (optionnel)                               │ │
│  │  [Choisir un fichier] capture_ecran.png (téléchargé)                   │ │
│  │                                                                         │ │
│  │  [Envoyer ma demande]                                                  │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│                                                                              │
│  → Fonctionnalité incluse dans le module "Agents IA" (widget de chat)       │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Gestion des pièces jointes

Les utilisateurs peuvent joindre des fichiers via le chat ou par email.

#### Sécurité des fichiers

| Mesure | Configuration |
|--------|---------------|
| **Extensions autorisées** | `.pdf`, `.jpg`, `.jpeg`, `.png`, `.gif`, `.webp`, `.doc`, `.docx`, `.xls`, `.xlsx`, `.txt`, `.csv` |
| **Extensions bloquées** | `.exe`, `.js`, `.php`, `.bat`, `.sh`, `.ps1`, `.vbs`, `.msi`, `.dll`, `.scr`, `.cmd`, `.jar` |
| **Taille max par fichier** | 10 Mo |
| **Taille max totale** | 25 Mo par conversation |
| **Scan antivirus** | ClamAV (open source, gratuit) |
| **Stockage** | `storage/app/support-attachments/` (hors public) |
| **Accès** | Via URL signée avec expiration |

#### Table `support_attachments`

```sql
CREATE TABLE support_attachments (
    id BIGSERIAL PRIMARY KEY,
    message_id BIGINT REFERENCES support_messages(id) ON DELETE CASCADE,
    conversation_id BIGINT REFERENCES support_conversations(id) ON DELETE CASCADE,

    -- Fichier
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,      -- UUID.extension
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INTEGER NOT NULL,

    -- Sécurité
    scan_status VARCHAR(20) DEFAULT 'pending',
    -- 'pending'  : En attente de scan
    -- 'clean'    : Scanné, aucun virus
    -- 'infected' : Virus détecté (fichier supprimé)
    -- 'error'    : Erreur de scan

    scanned_at TIMESTAMP NULL,

    -- Source
    source VARCHAR(20) NOT NULL DEFAULT 'chat',
    -- 'chat'  : Upload via widget
    -- 'email' : Pièce jointe email

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_attach_message ON support_attachments(message_id);
CREATE INDEX idx_attach_conv ON support_attachments(conversation_id);
CREATE INDEX idx_attach_scan ON support_attachments(scan_status) WHERE scan_status = 'pending';
```

#### Service de scan antivirus

```php
class AttachmentSecurityService
{
    private const ALLOWED_EXTENSIONS = [
        'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp',
        'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv'
    ];

    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 Mo

    public function validateAndStore(UploadedFile $file, SupportConversation $conversation): SupportAttachment
    {
        // 1. Vérifier l'extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            throw new InvalidAttachmentException("Type de fichier non autorisé: .{$extension}");
        }

        // 2. Vérifier la taille
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new InvalidAttachmentException("Fichier trop volumineux (max 10 Mo)");
        }

        // 3. Vérifier le MIME type réel (pas juste l'extension)
        $mimeType = $file->getMimeType();
        if (!$this->isAllowedMimeType($mimeType)) {
            throw new InvalidAttachmentException("Type de contenu non autorisé");
        }

        // 4. Stocker avec nom unique
        $storedName = Str::uuid() . '.' . $extension;
        $path = $file->storeAs('support-attachments', $storedName, 'local');

        // 5. Créer l'enregistrement
        $attachment = SupportAttachment::create([
            'conversation_id' => $conversation->id,
            'original_name' => $file->getClientOriginalName(),
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'size_bytes' => $file->getSize(),
            'scan_status' => 'pending',
        ]);

        // 6. Lancer le scan en arrière-plan
        dispatch(new ScanAttachmentJob($attachment));

        return $attachment;
    }

    public function scanWithClamAV(SupportAttachment $attachment): bool
    {
        $filePath = storage_path("app/support-attachments/{$attachment->stored_name}");

        // Utiliser ClamAV via clamscan ou clamd socket
        $result = Process::run("clamscan --no-summary {$filePath}");

        if ($result->exitCode() === 0) {
            $attachment->update([
                'scan_status' => 'clean',
                'scanned_at' => now(),
            ]);
            return true;
        } elseif ($result->exitCode() === 1) {
            // Virus détecté - supprimer le fichier
            Storage::disk('local')->delete("support-attachments/{$attachment->stored_name}");
            $attachment->update([
                'scan_status' => 'infected',
                'scanned_at' => now(),
            ]);
            Log::warning('Virus détecté dans pièce jointe', [
                'attachment_id' => $attachment->id,
                'original_name' => $attachment->original_name,
            ]);
            return false;
        }

        $attachment->update(['scan_status' => 'error']);
        return false;
    }
}
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

### 3.5 Rôle "Agent de support" et assignation aux agents IA

#### Nouveau rôle

```sql
-- Ajouter le rôle "Agent de support" (seed ou migration)
INSERT INTO roles (name, slug, description, is_system, created_at, updated_at)
VALUES (
    'Agent de support',
    'support-agent',
    'Peut répondre aux conversations escaladées sur les agents IA assignés',
    true,
    NOW(),
    NOW()
);
```

#### Table pivot `agent_support_users`

Permet d'assigner des utilisateurs ayant le rôle "support-agent" à des agents IA spécifiques.

```sql
CREATE TABLE agent_support_users (
    id BIGSERIAL PRIMARY KEY,
    agent_id BIGINT REFERENCES agents(id) ON DELETE CASCADE,
    user_id BIGINT REFERENCES users(id) ON DELETE CASCADE,

    -- Permissions spécifiques (optionnel)
    can_close_conversations BOOLEAN DEFAULT TRUE,
    can_train_ai BOOLEAN DEFAULT TRUE,           -- Peut sauver Q/R et indexer
    can_view_analytics BOOLEAN DEFAULT FALSE,    -- Accès aux stats

    -- Notifications
    notify_on_escalation BOOLEAN DEFAULT TRUE,   -- Notifier par email/push

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE(agent_id, user_id)
);

CREATE INDEX idx_agent_support_agent ON agent_support_users(agent_id);
CREATE INDEX idx_agent_support_user ON agent_support_users(user_id);
```

#### Logique d'accès

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    HIÉRARCHIE D'ACCÈS AU SUPPORT                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  super-admin / admin                                                        │
│  └── Accès à TOUS les agents (pas besoin d'assignation)                    │
│                                                                              │
│  support-agent                                                              │
│  └── Accès UNIQUEMENT aux agents où il est assigné                         │
│      (via agent_support_users)                                             │
│                                                                              │
│  Autres rôles (artisan, editeur, fabricant...)                             │
│  └── Pas d'accès à l'interface Support Live                                │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

#### Modification du modèle Agent

```php
// app/Models/Agent.php

/**
 * Utilisateurs assignés au support de cet agent
 */
public function supportUsers(): BelongsToMany
{
    return $this->belongsToMany(User::class, 'agent_support_users')
        ->withPivot(['can_close_conversations', 'can_train_ai', 'can_view_analytics', 'notify_on_escalation'])
        ->withTimestamps();
}

/**
 * Vérifie si un utilisateur peut gérer le support de cet agent
 */
public function userCanHandleSupport(User $user): bool
{
    // Super-admin et admin ont accès à tout
    if ($user->hasRole('super-admin') || $user->hasRole('admin')) {
        return true;
    }

    // Vérifier si l'utilisateur est assigné à cet agent
    if ($user->hasRole('support-agent')) {
        return $this->supportUsers()->where('user_id', $user->id)->exists();
    }

    return false;
}
```

#### Modification du modèle User

```php
// app/Models/User.php

public function isSupportAgent(): bool
{
    return $this->hasRole('support-agent');
}

/**
 * Agents IA pour lesquels cet utilisateur peut faire du support
 */
public function supportAgents(): BelongsToMany
{
    return $this->belongsToMany(Agent::class, 'agent_support_users')
        ->withPivot(['can_close_conversations', 'can_train_ai', 'can_view_analytics', 'notify_on_escalation'])
        ->withTimestamps();
}

/**
 * Retourne les agents accessibles pour le support
 */
public function getAccessibleSupportAgents(): Collection
{
    if ($this->hasRole('super-admin') || $this->hasRole('admin')) {
        return Agent::where('human_support_enabled', true)->get();
    }

    if ($this->hasRole('support-agent')) {
        return $this->supportAgents()->where('human_support_enabled', true)->get();
    }

    return collect();
}
```

#### Interface Filament - Configuration agent

```
Agent Settings → Support Humain
├── [x] Activer le support humain
├── Seuil de confiance: [0.60]
├── ...
│
└── 👥 Agents de support assignés:
    ┌────────────────────────────────────────────────────────────────────┐
    │  Nom                │ Clôturer │ Former IA │ Stats │ Notifier     │
    ├─────────────────────┼──────────┼───────────┼───────┼──────────────┤
    │  Marie Dupont       │    ✓     │     ✓     │   ✗   │      ✓       │
    │  Jean Martin        │    ✓     │     ✓     │   ✓   │      ✓       │
    │  Sophie Bernard     │    ✓     │     ✗     │   ✗   │      ✓       │
    └─────────────────────┴──────────┴───────────┴───────┴──────────────┘

    [+ Ajouter un agent de support]

    💡 Seuls les utilisateurs ayant le rôle "Agent de support" apparaissent ici.
    💡 Les super-admin et admin ont automatiquement accès à tous les agents.
```

#### Mise à jour de `admin_availability`

La table `admin_availability` reste mais `agent_ids` devient redondant avec `agent_support_users`. On peut soit :
- **Option A** : Garder `agent_ids` pour la rétro-compatibilité (déprécié)
- **Option B** : Supprimer `agent_ids` et utiliser uniquement `agent_support_users`

**Recommandation** : Option B - supprimer `agent_ids` de `admin_availability` et utiliser la relation `agent_support_users`.

```sql
-- Migration pour supprimer agent_ids (optionnel, après migration des données)
ALTER TABLE admin_availability DROP COLUMN agent_ids;
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

    // Configuration email bidirectionnel
    $table->json('email_config')->nullable();
    // {
    //   "enabled": true,
    //   "provider": "mailgun",  // "mailgun", "sendgrid", "imap"
    //   "from_address": "support@domain.com",
    //   "from_name": "Support BTP",
    //   "reply_domain": "reply.domain.com",  // pour support+conv_123@reply.domain.com
    //   // Si IMAP:
    //   "imap_host": "imap.example.com",
    //   "imap_port": 993,
    //   "imap_username": "...",
    //   "imap_password": "...",  // Encrypted
    //   "imap_poll_interval": 60  // secondes
    // }
});
```

### 4.2 Interface Filament

```
Agent Settings → Support Humain
├── [x] Activer le support humain
├── Seuil de confiance: [0.60] (slider 0.0 - 1.0)
├── Message d'escalade: [textarea]
├── Message hors horaires: [textarea]
├── Email notifications admins: [support@example.com]
└── Horaires de support:
    ├── Lundi: [09:00] - [18:00]
    ├── Mardi: [09:00] - [18:00]
    └── ...

Agent Settings → Email Bidirectionnel (module Déploiement Agent IA)
├── [x] Activer la réception email
├── Fournisseur: [Mailgun ▼] (Mailgun, SendGrid, IMAP)
├── Adresse d'envoi: [support@domain.com]
├── Nom expéditeur: [Support BTP]
├── Domaine de réponse: [reply.domain.com]
│   → Les utilisateurs répondront à: support+conv_{id}@reply.domain.com
│
├── Si IMAP sélectionné:
│   ├── Serveur IMAP: [imap.example.com]
│   ├── Port: [993]
│   ├── Utilisateur: [...]
│   ├── Mot de passe: [***]
│   └── Fréquence de polling: [60] secondes
│
└── [Tester la connexion]
```

### 4.3 Intégration dans les modules

Le système de support humain s'intègre dans les modules existants :

| Module | Fonctionnalités concernées |
|--------|---------------------------|
| **Agents IA** | Configuration escalade (seuil, messages, horaires), apprentissage Q/R |
| **Déploiement Agent IA** | Configuration email (fournisseur, IMAP/webhooks, domaine réponse) |
| **Dashboard Admin** | Interface support live, analytiques, gestion conversations |

```
┌────────────────────────────────────────────────────────────────────────────┐
│                    INTÉGRATION MODULES                                      │
├────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Module "Agents IA"                                                        │
│  ├── Configuration agent                                                   │
│  │   ├── Support humain (on/off)                                          │
│  │   ├── Seuil d'escalade                                                 │
│  │   ├── Messages personnalisés                                           │
│  │   └── Horaires de support                                              │
│  └── Apprentissage                                                         │
│      ├── Validation Q/R depuis sessions                                   │
│      └── Validation Q/R depuis support (nouveau)                          │
│                                                                             │
│  Module "Déploiement Agent IA"                                             │
│  ├── Configuration email sortant (déjà existant)                          │
│  └── Configuration email entrant (nouveau)                                │
│      ├── Choix fournisseur (Mailgun/SendGrid/IMAP)                       │
│      ├── Webhooks ou polling IMAP                                         │
│      └── Test de connexion                                                │
│                                                                             │
└────────────────────────────────────────────────────────────────────────────┘
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

        // Notifier tous les agents de support assignés à cet agent IA
        $this->notifySupportAgentsOfEscalation($conversation, $context);

        return new EscalationResult(
            success: true,
            mode: 'async_email',
            admin: null,
            message: $agent->no_admin_message ??
                "Notre équipe n'est pas disponible actuellement. " .
                "Nous avons enregistré votre demande et vous répondrons par email dès que possible."
        );
    }

    /**
     * Notifie par email tous les agents de support assignés
     * quand aucun n'est connecté
     */
    private function notifySupportAgentsOfEscalation(
        SupportConversation $conversation,
        array $context
    ): void {
        $agent = $conversation->agent;

        // Récupérer les agents de support avec notifications activées
        $supportUsers = $agent->supportUsers()
            ->wherePivot('notify_on_escalation', true)
            ->get();

        // Ajouter les super-admin/admin si configuré
        if ($agent->support_email) {
            // Email générique de l'agent (en plus des users)
        }

        // Récupérer le contenu de la demande
        $userMessage = $conversation->messages()
            ->where('sender_type', 'user')
            ->latest()
            ->first();

        foreach ($supportUsers as $supportUser) {
            Mail::to($supportUser->email)
                ->queue(new EscalationNotificationMail(
                    conversation: $conversation,
                    supportUser: $supportUser,
                    userQuestion: $userMessage?->content ?? '',
                    context: $context
                ));
        }

        Log::info('Notification escalade envoyée aux agents de support', [
            'conversation_id' => $conversation->id,
            'agent_id' => $agent->id,
            'notified_users' => $supportUsers->pluck('email')->toArray(),
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

### 6.4 Assistance IA pour l'agent de support

L'IA assiste l'agent humain à plusieurs niveaux pour garantir des réponses de qualité.

#### Vue d'ensemble

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    ASSISTANCE IA POUR L'AGENT                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  1. SUGGESTIONS AUTOMATIQUES (panneau latéral)                              │
│     ├── Recherche RAG en temps réel sur la question                        │
│     ├── Affiche les sources pertinentes avec extraits                       │
│     └── Bouton "Utiliser cette source" → pré-remplit la réponse            │
│                                                                              │
│  2. GÉNÉRATION DE BROUILLON (optionnel)                                     │
│     ├── Bouton "🤖 Générer une suggestion"                                  │
│     ├── L'IA génère une réponse basée sur les sources trouvées             │
│     └── L'agent peut modifier avant envoi                                   │
│                                                                              │
│  3. RELECTURE AVANT ENVOI (chat ET email)                                   │
│     ├── Bouton "✨ Améliorer" à côté du textarea                           │
│     ├── Mode chat: amélioration inline (remplace le texte)                 │
│     ├── Mode email: popup de confirmation avec preview                     │
│     ├── Corrections appliquées:                                            │
│     │   ├── Orthographe/grammaire                                          │
│     │   ├── Reformulation plus claire                                       │
│     │   └── Formules de politesse (configurable par agent)                 │
│     └── Diff avant/après pour validation (Ctrl+Z pour annuler)             │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

#### Interface utilisateur enrichie

```
┌────────────────────────────────────────────────────────────────────────────┐
│  CONVERSATION #4521                          [Utilisateur: 📧 Hors ligne]  │
├────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  [User] Comment annuler une facture validée ?                              │
│                                                                             │
│  [AI] Je n'ai pas trouvé d'information fiable... (Score: 45%)              │
│                                                                             │
│  ───────────────────────────────────────────────────────────────────────── │
│                                                                             │
│  📝 Votre réponse:                                        [✨ Améliorer]   │
│  ┌───────────────────────────────────────────────────────────────────────┐ │
│  │ Pour annuler une facture validée, vous devez créer un avoir.         │ │
│  │ Allez dans Facturation > Avoirs > Nouveau...                         │ │
│  └───────────────────────────────────────────────────────────────────────┘ │
│                                                                             │
│  [Envoyer 📧] [🤖 Générer suggestion] [💾 Sauver Q/R ▼] [Clôturer ▼]      │
│                                                                             │
│  💡 Le bouton "Améliorer" corrige et reformule votre texte avant envoi    │
│                                                                             │
│  ───────────────────────────────────────────────────────────────────────── │
│                                                                             │
│  🤖 ASSISTANCE IA                                                          │
│  ┌───────────────────────────────────────────────────────────────────────┐ │
│  │ 📚 Sources trouvées:                                                  │ │
│  │                                                                        │ │
│  │ 1. "Gestion des avoirs" (Score: 67%)                                  │ │
│  │    > Pour annuler une facture, créez un avoir depuis le menu         │ │
│  │    > Facturation. L'avoir vient en déduction du solde client...      │ │
│  │    [📋 Copier] [✏️ Utiliser comme base]                               │ │
│  │                                                                        │ │
│  │ 2. "Facturation - FAQ" (Score: 52%)                                   │ │
│  │    > Une facture validée ne peut pas être supprimée pour des         │ │
│  │    > raisons légales. Seul un avoir permet de l'annuler...           │ │
│  │    [📋 Copier] [✏️ Utiliser comme base]                               │ │
│  │                                                                        │ │
│  └───────────────────────────────────────────────────────────────────────┘ │
│                                                                             │
└────────────────────────────────────────────────────────────────────────────┘
```

#### Modal de confirmation (mode email / utilisateur offline)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  📧 Confirmation d'envoi par email                                     [X] │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  L'utilisateur est hors ligne. Votre réponse sera envoyée par email.       │
│                                                                              │
│  ─────────────────────────────────────────────────────────────────────────  │
│                                                                              │
│  📄 Aperçu de votre réponse:                                                │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ Pour annuler une facture validée, vous devez créer un avoir.       │   │
│  │ Allez dans Facturation > Avoirs > Nouveau, selectionnez la         │   │
│  │ facture concerné.                                                   │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  [✨ Améliorer avec l'IA]                                                   │
│                                                                              │
│  ─────────────────────────────────────────────────────────────────────────  │
│                                                                              │
│  ✨ Suggestion de l'IA:                                                     │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │ Bonjour,                                                            │   │
│  │                                                                      │   │
│  │ Pour annuler une facture validée, vous devez créer un avoir.       │   │
│  │ Voici la procédure :                                                │   │
│  │                                                                      │   │
│  │ 1. Allez dans **Facturation > Avoirs > Nouveau**                   │   │
│  │ 2. Sélectionnez la facture concernée                               │   │
│  │ 3. Validez l'avoir                                                  │   │
│  │                                                                      │   │
│  │ L'avoir viendra en déduction du solde client.                       │   │
│  │                                                                      │
│  │ N'hésitez pas si vous avez d'autres questions.                      │   │
│  │                                                                      │   │
│  │ Cordialement,                                                       │   │
│  │ L'équipe Support                                                    │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  Corrections effectuées:                                                    │
│  • ✓ Ajout formule de politesse (Bonjour/Cordialement)                     │
│  • ✓ Mise en forme avec liste numérotée                                    │
│  • ✓ Correction: "selectionnez" → "Sélectionnez"                           │
│  • ✓ Correction: "concerné" → "concernée"                                  │
│                                                                              │
│  [Utiliser la version IA] [Garder ma version] [Modifier manuellement]      │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

#### Service d'assistance IA

```php
<?php

namespace App\Services\Support;

class AgentAssistanceService
{
    public function __construct(
        private RagService $ragService,
        private LLMService $llmService
    ) {}

    /**
     * Recherche des sources pertinentes pour la question
     */
    public function findRelevantSources(SupportConversation $conversation): array
    {
        $userQuestion = $conversation->messages()
            ->where('sender_type', 'user')
            ->latest()
            ->first()
            ?->content;

        if (!$userQuestion) {
            return [];
        }

        // Recherche RAG avec seuil bas pour trouver plus de sources
        $results = $this->ragService->search(
            query: $userQuestion,
            agent: $conversation->agent,
            limit: 5,
            minScore: 0.30 // Seuil bas pour suggestions
        );

        return collect($results)->map(fn($r) => [
            'title' => $r['document_name'] ?? 'Source',
            'score' => round($r['score'] * 100),
            'excerpt' => Str::limit($r['content'], 200),
            'full_content' => $r['content'],
        ])->toArray();
    }

    /**
     * Génère une suggestion de réponse basée sur les sources
     */
    public function generateSuggestion(SupportConversation $conversation): string
    {
        $sources = $this->findRelevantSources($conversation);
        $userQuestion = $conversation->getLastUserMessage();

        $prompt = <<<PROMPT
        Tu es un assistant de support. Génère une réponse professionnelle et utile.

        Question de l'utilisateur:
        {$userQuestion}

        Sources disponibles:
        {$this->formatSources($sources)}

        Consignes:
        - Réponds de manière claire et concise
        - Utilise les informations des sources si pertinentes
        - Si aucune source n'est pertinente, indique-le
        - Ne fabrique pas d'informations
        - Garde un ton professionnel mais accessible
        PROMPT;

        return $this->llmService->generate($prompt);
    }

    /**
     * Améliore une réponse avant envoi (chat ou email)
     *
     * @param string $draftResponse Le brouillon de l'agent
     * @param SupportConversation $conversation
     * @param string $mode 'chat' ou 'email'
     * @return array ['original', 'improved', 'corrections']
     */
    public function improveResponse(
        string $draftResponse,
        SupportConversation $conversation,
        string $mode = 'chat'
    ): array {
        $agent = $conversation->agent;
        $config = $agent->ai_assistance_config ?? [];

        // Formules de politesse uniquement si configuré ou mode email
        $addPoliteness = $mode === 'email' || ($config['add_politeness'] ?? false);

        $prompt = <<<PROMPT
        Améliore cette réponse de support.

        Réponse originale:
        {$draftResponse}

        Mode: {$mode}

        Améliorations à faire:
        1. Corriger les fautes d'orthographe et de grammaire
        2. Améliorer la clarté et la mise en forme si nécessaire
        3. Garder le sens et les informations originales
        PROMPT;

        if ($addPoliteness) {
            $prompt .= "\n4. Ajouter une formule de politesse appropriée (Bonjour/Cordialement)";
        }

        $prompt .= <<<PROMPT

        Réponds en JSON:
        {
            "improved_text": "...",
            "corrections": [
                {"type": "spelling", "original": "...", "fixed": "..."},
                {"type": "formatting", "description": "..."},
                {"type": "politeness", "description": "..."}
            ]
        }
        PROMPT;

        $result = $this->llmService->generateJson($prompt);

        return [
            'original' => $draftResponse,
            'improved' => $result['improved_text'],
            'corrections' => $result['corrections'],
            'mode' => $mode,
        ];
    }
}
```

#### Configuration par agent

```php
// Nouveaux champs dans la table agents
$table->json('ai_assistance_config')->nullable();
// {
//   "suggestions_enabled": true,      // Afficher le panneau de sources RAG
//   "auto_generate_enabled": false,   // Bouton "Générer suggestion"
//   "improve_enabled": true,          // Bouton "Améliorer" (chat + email)
//   "add_politeness": false,          // Ajouter formules de politesse en mode chat
//                                     // (toujours actif en mode email)
//   "email_confirm_required": true,   // Popup de confirmation pour emails
//   "improvement_prompt": "..."       // Prompt personnalisé (optionnel)
// }
```

#### Ajout à l'estimation

| Tâche | Durée |
|-------|-------|
| Panneau sources latéral | 0.5 jour |
| Bouton "Générer suggestion" | 0.5 jour |
| Modal confirmation email | 0.5 jour |
| AgentAssistanceService (3 méthodes) | 1 jour |
| Tests et intégration | 0.5 jour |
| **Total assistance IA** | **3 jours** |

> Cette fonctionnalité s'ajoute à la Phase 2 (Interface Admin), portant son total à **10-11 jours**.

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

### 8.4 Templates email

#### Premier email (confirmation d'escalade) - avec instructions anti-spam

```blade
{{-- resources/views/emails/support/escalation-confirmation.blade.php --}}
@component('mail::message')
# Votre demande #{{ $conversation->id }} a été enregistrée

Bonjour,

Nous avons bien reçu votre demande et notre équipe vous répondra dans les plus brefs délais.

@component('mail::panel')
**Votre question :**
{{ $userQuestion }}
@endcomponent

---

## 📧 Important : Assurez-vous de recevoir nos réponses

Pour être certain de recevoir nos emails de réponse, nous vous recommandons de :

1. **Ajouter notre adresse à vos contacts** : `{{ $fromAddress }}`
2. **Vérifier vos courriers indésirables** (spam) - si vous y trouvez notre email, marquez-le comme "Non spam"
3. **Autoriser notre domaine** : `{{ $replyDomain }}`

@component('mail::subcopy')
💡 **Astuce** : Sur Gmail, cliquez sur les 3 points → "Filtrer les messages similaires" → "Ne jamais envoyer dans le spam"
@endcomponent

---

**Vous pouvez répondre directement à cet email** pour ajouter des informations à votre demande.

Ou suivre votre demande en ligne :

@component('mail::button', ['url' => $chatUrl, 'color' => 'primary'])
💬 Voir ma demande
@endcomponent

Cordialement,<br>
{{ $conversation->agent->name }}
@endcomponent
```

#### Email de réponse admin

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

---

@component('mail::subcopy')
📧 Vous ne recevez pas nos emails ? [Consultez notre guide]({{ $whitelistGuideUrl }}) pour ajouter notre adresse à vos contacts.
@endcomponent

Cordialement,<br>
{{ $conversation->agent->name }}
@endcomponent
```

#### Email de notification aux agents de support (aucun connecté)

```blade
{{-- resources/views/emails/support/escalation-notification.blade.php --}}
@component('mail::message')
# 🚨 Nouvelle demande de support - {{ $conversation->agent->name }}

Bonjour {{ $supportUser->name }},

Une nouvelle demande de support a été escaladée et **aucun agent n'est actuellement connecté**.

@component('mail::panel')
**Demande #{{ $conversation->id }}**

**Question de l'utilisateur :**
{{ $userQuestion }}

@if($conversation->user_email)
**Email utilisateur :** {{ $conversation->user_email }}
@endif

**Raison de l'escalade :** {{ $escalationReason }}

@if(isset($context['max_rag_score']))
**Score IA :** {{ round($context['max_rag_score'] * 100) }}% (seuil : {{ round($context['threshold'] * 100) }}%)
@endif
@endcomponent

---

@component('mail::button', ['url' => $dashboardUrl, 'color' => 'primary'])
📋 Voir la demande dans le dashboard
@endcomponent

@component('mail::button', ['url' => $takeChargeUrl, 'color' => 'success'])
✋ Prendre en charge
@endcomponent

---

<small>
Vous recevez cet email car vous êtes assigné comme agent de support pour **{{ $conversation->agent->name }}**.
Pour modifier vos préférences de notification, contactez votre administrateur.
</small>

Cordialement,<br>
{{ config('app.name') }}
@endcomponent
```

#### Mailable pour la notification

```php
<?php

namespace App\Mail\Support;

class EscalationNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public SupportConversation $conversation,
        public User $supportUser,
        public string $userQuestion,
        public array $context = []
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "🚨 Nouvelle demande de support #{$this->conversation->id} - {$this->conversation->agent->name}",
        );
    }

    public function content(): Content
    {
        $escalationReasons = [
            'low_confidence' => 'Score IA insuffisant',
            'user_request' => 'Demandé par l\'utilisateur',
            'negative_feedback' => 'Feedback négatif',
        ];

        return new Content(
            markdown: 'emails.support.escalation-notification',
            with: [
                'dashboardUrl' => route('filament.admin.pages.live-support', [
                    'conversation' => $this->conversation->id
                ]),
                'takeChargeUrl' => route('support.take-charge', [
                    'conversation' => $this->conversation->id,
                    'token' => $this->generateTakeChargeToken()
                ]),
                'escalationReason' => $escalationReasons[$this->conversation->escalation_reason]
                    ?? $this->conversation->escalation_reason,
            ],
        );
    }

    private function generateTakeChargeToken(): string
    {
        return Crypt::encryptString(json_encode([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->supportUser->id,
            'expires_at' => now()->addHours(24)->timestamp,
        ]));
    }
}
```

### 8.5 Configuration des fournisseurs email

#### Comparaison des options

| Fournisseur | Réception | Envoi | Coût | Délai |
|-------------|-----------|-------|------|-------|
| **IMAP** ⭐ | Polling (1 min) | Via SMTP existant | **Gratuit** | ~1 min |
| **Mailgun** | Webhook (temps réel) | 5000 gratuits/mois puis 0.80€/1000 | ~10-30€/mois | Instantané |
| **SendGrid** | Webhook (temps réel) | 100/jour gratuits | ~15-25€/mois | Instantané |

**Recommandation** : Commencer avec **IMAP** (gratuit), migrer vers webhooks si le volume justifie le coût.

#### Option A : IMAP Polling (recommandé - GRATUIT)

Utilise une boîte mail existante (OVH, Gandi, Gmail, etc.) :

```
┌────────────────────────────────────────────────────────────────────────────┐
│  CONFIGURATION IMAP (0€)                                                    │
├────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  1. Créer une boîte mail dédiée: support@votredomaine.com                  │
│                                                                             │
│  2. Configurer dans l'agent:                                               │
│     • Fournisseur: IMAP                                                    │
│     • Serveur: imap.votrehebergeur.com                                     │
│     • Port: 993 (SSL)                                                      │
│     • Utilisateur: support@votredomaine.com                                │
│     • Mot de passe: ***                                                    │
│     • Polling: 60 secondes                                                 │
│                                                                             │
│  3. L'envoi utilise le SMTP Laravel existant (config/mail.php)            │
│                                                                             │
│  Coût total: 0€ (utilise l'hébergement email existant)                    │
│                                                                             │
└────────────────────────────────────────────────────────────────────────────┘
```

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    // Polling IMAP toutes les minutes
    $schedule->job(new FetchImapEmailsJob())
        ->everyMinute()
        ->withoutOverlapping()
        ->runInBackground();
}
```

```php
// app/Jobs/Support/FetchImapEmailsJob.php
class FetchImapEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(EmailReplyParser $parser): void
    {
        // Récupérer tous les agents avec IMAP configuré
        $agents = Agent::where('human_support_enabled', true)
            ->whereJsonContains('email_config->provider', 'imap')
            ->whereJsonContains('email_config->enabled', true)
            ->get();

        foreach ($agents as $agent) {
            $this->fetchEmailsForAgent($agent, $parser);
        }
    }

    private function fetchEmailsForAgent(Agent $agent, EmailReplyParser $parser): void
    {
        $config = $agent->email_config;

        try {
            $mailbox = new \PhpImap\Mailbox(
                '{' . $config['imap_host'] . ':' . $config['imap_port'] . '/imap/ssl}INBOX',
                $config['imap_username'],
                decrypt($config['imap_password']),
                storage_path('app/temp-attachments'),
                'UTF-8'
            );

            // Récupérer les emails non lus
            $mailIds = $mailbox->searchMailbox('UNSEEN');

            foreach ($mailIds as $mailId) {
                $email = $mailbox->getMail($mailId);

                // Traiter l'email
                dispatch(new ProcessIncomingEmailJob(
                    agentId: $agent->id,
                    to: $email->toString ?? '',
                    from: $email->fromAddress,
                    subject: $email->subject,
                    body: $email->textPlain ?? strip_tags($email->textHtml ?? ''),
                    messageId: $email->messageId,
                    attachments: $email->getAttachments(),
                ));

                // Marquer comme lu
                $mailbox->markMailAsRead($mailId);
            }

            $mailbox->disconnect();
        } catch (\Exception $e) {
            Log::error('Erreur IMAP', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

#### Option B : Webhooks (premium - temps réel)

Pour les volumes importants ou besoin de temps réel :

```php
// config/services.php
'mailgun' => [
    'domain' => env('MAILGUN_DOMAIN'),
    'secret' => env('MAILGUN_SECRET'),
    'webhook_signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY'),
],

'sendgrid' => [
    'api_key' => env('SENDGRID_API_KEY'),
    'webhook_signing_key' => env('SENDGRID_WEBHOOK_SIGNING_KEY'),
],
```

```php
// routes/api.php
Route::post('/webhooks/mailgun/inbound', [SupportWebhookController::class, 'mailgunInbound'])
    ->name('webhooks.mailgun.inbound');

Route::post('/webhooks/sendgrid/inbound', [SupportWebhookController::class, 'sendgridInbound'])
    ->name('webhooks.sendgrid.inbound');
```

```php
// app/Http/Controllers/Api/SupportWebhookController.php
class SupportWebhookController extends Controller
{
    public function mailgunInbound(Request $request)
    {
        // Vérifier la signature Mailgun
        if (!$this->verifyMailgunSignature($request)) {
            abort(401);
        }

        // Traiter l'email entrant
        dispatch(new ProcessIncomingEmailJob(
            to: $request->input('recipient'),
            from: $request->input('from'),
            subject: $request->input('subject'),
            body: $request->input('body-plain') ?? $request->input('stripped-text'),
            messageId: $request->input('Message-Id'),
            attachments: $request->file('attachments') ?? [],
        ));

        return response('OK', 200);
    }
}
```

### 8.6 Contrôleur de reprise de chat

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

### Estimation globale

| Phase | Description | Durée estimée |
|-------|-------------|---------------|
| **Phase 1** | Base (modèles, services, migrations) | **6-7 jours** |
| **Phase 2** | Interface Admin Filament + Assistance IA | **10-11 jours** |
| **Phase 3** | Temps réel (WebSocket) | **4-5 jours** |
| **Phase 4** | Email bidirectionnel + pièces jointes | **7-8 jours** |
| **Phase 5** | Apprentissage IA (double flux) | **5-6 jours** |
| **Phase 6** | Analytiques et reporting | **5-6 jours** |
| | **Sous-total développement** | **37-43 jours** |
| | Tests d'intégration + corrections (+20%) | **7-9 jours** |
| | **TOTAL** | **44-52 jours** |

> **Estimation pour 1 développeur senior** : 9 à 11 semaines de travail effectif
>
> **Prérequis** : Stack Laravel/Filament maîtrisée, expérience WebSocket et queues

---

### Phase 1 : Base (6-7 jours)

| Tâche | Détail | Durée |
|-------|--------|-------|
| Migrations | 6 tables (support_*, admin_availability, agent_support_users) + alter agents | 2 jours |
| Rôle + permissions | Seed rôle "support-agent", modifier canAccessPanel() | 0.5 jour |
| Models Eloquent | 5 models + relations, casts, scopes | 1 jour |
| EscalationService | shouldEscalate(), getAvailableAdmin(), isWithinSupportHours(), escalate() | 2 jours |
| Intégration RagService | Modifier chat() pour détecter et gérer l'escalade | 1 jour |
| Message utilisateur | Affichage message d'escalade dans le widget | 0.5 jour |

- [ ] Migrations (tables support_*, admin_availability, agent_support_users)
- [ ] Seed rôle "support-agent" + modifier User::canAccessPanel()
- [ ] Models Eloquent + relations (dont Agent::supportUsers(), User::supportAgents())
- [ ] EscalationService (logique de base)
- [ ] Intégration RagService (détection escalade)
- [ ] Message utilisateur lors de l'escalade

### Phase 2 : Interface Admin + Assistance IA (10-11 jours)

| Tâche | Détail | Durée |
|-------|--------|-------|
| Page Filament "Support Live" | Layout de base, routing, permissions | 2 jours |
| Liste conversations | Filtres, tri, indicateurs visuels (canal, temps d'attente) | 1.5 jours |
| Vue conversation | Historique messages, contexte RAG, affichage pièces jointes | 2 jours |
| Formulaire réponse | Textarea, envoi, templates rapides | 1 jour |
| Actions de clôture | Menu dropdown avec types de résolution | 1 jour |
| Panneau sources IA | Recherche RAG, affichage sources, boutons copier/utiliser | 0.5 jour |
| Génération suggestion | Bouton "Générer suggestion" avec LLM | 0.5 jour |
| Modal confirmation email | Preview, bouton "Améliorer avec IA", diff | 0.5 jour |
| AgentAssistanceService | findRelevantSources(), generateSuggestion(), improveForEmail() | 1 jour |
| Tests assistance IA | Tests unitaires et intégration | 0.5 jour |

- [ ] Page Filament "Support Live" (filtrage par agents accessibles)
- [ ] Liste des conversations escaladées (avec indicateur canal chat/email)
- [ ] Vue conversation avec historique
- [ ] Formulaire de réponse
- [ ] Actions de clôture
- [ ] Panneau d'assistance IA (sources + suggestions)
- [ ] Modal de confirmation email avec amélioration IA
- [ ] AgentAssistanceService
- [ ] Interface assignation agents de support (dans config agent)

### Phase 3 : Temps réel (4-5 jours)

| Tâche | Détail | Durée |
|-------|--------|-------|
| Configuration Echo | Installation Pusher/Soketi, config broadcasting | 1.5 jours |
| Events | ConversationEscalated, NewMessage, ConversationResolved, AdminStatusChanged | 1 jour |
| Listeners côté admin | Mise à jour temps réel de l'interface, compteurs | 1 jour |
| Notifications sonores | Son lors de nouvelle conversation/message | 0.5 jour |
| Widget utilisateur live | Réception des messages admin en temps réel | 1 jour |

- [ ] Configuration Laravel Echo + Pusher/Soketi
- [ ] Events (ConversationEscalated, NewMessage, etc.)
- [ ] Listeners côté admin
- [ ] Notifications sonores

### Phase 4 : Email bidirectionnel + pièces jointes (7-8 jours)

| Tâche | Détail | Durée |
|-------|--------|-------|
| Configuration IMAP | Interface Filament, connexion boîte mail, test | 1.5 jours |
| EmailReplyParser | Extraction contenu sans citations/signatures | 1 jour |
| FetchImapEmailsJob | Scheduler, lecture IMAP, gestion erreurs | 1 jour |
| Templates email | Confirmation escalade (avec anti-spam), réponse admin | 1 jour |
| Contrôleur reprise chat | URL signée, vérification token, widget | 1 jour |
| AttachmentSecurityService | Validation, stockage sécurisé, intégration ClamAV | 1.5 jours |
| ProcessIncomingEmailJob | Attachements email → SupportAttachment | 1 jour |

- [ ] Configuration boîte mail (IMAP ou webhook Mailgun/SendGrid)
- [ ] EmailReplyParser pour extraire les réponses
- [ ] FetchIncomingEmailsJob (scheduler toutes les minutes)
- [ ] Templates email avec bouton retour chat
- [ ] Contrôleur de reprise de conversation
- [ ] AttachmentSecurityService + ScanAttachmentJob

### Phase 5 : Apprentissage IA (5-6 jours)

| Tâche | Détail | Durée |
|-------|--------|-------|
| Composant Blade partagé | `<x-support.qr-correction-form>` avec Alpine.js | 1 jour |
| Refactor page Sessions | Remplacer code existant par composant partagé | 1 jour |
| Intégration Support Live | Bouton "Sauver Q/R" sur chaque échange | 0.5 jour |
| SupportTrainingService | saveQrPair(), création learned_response | 0.5 jour |
| ConversationToMarkdownService | Conversion chat → Markdown optimisé | 1 jour |
| IndexConversationAsDocumentJob | Création Document + dispatch pipeline | 1 jour |
| UI options clôture | Checkboxes apprentissage dans modal clôture | 0.5 jour |

- [ ] Composant Blade partagé `<x-support.qr-correction-form>`
- [ ] Intégration dans page Sessions (refactor existant)
- [ ] Intégration dans page Support Live
- [ ] ConversationToMarkdownService
- [ ] IndexConversationAsDocumentJob
- [ ] Options de clôture avec checkboxes apprentissage

### Phase 6 : Analytiques (5-6 jours)

| Tâche | Détail | Durée |
|-------|--------|-------|
| Dashboard métriques | Widgets Filament : taux escalade, temps réponse, satisfaction | 2 jours |
| Graphiques temporels | Chart.js : évolution par jour/semaine | 1 jour |
| Export rapports | CSV/Excel des conversations | 1 jour |
| Alertes | Notifications si seuils dépassés (email + dashboard) | 1 jour |
| Suggestions automatiques | Détection questions fréquentes → suggestion FAQ | 1 jour |

- [ ] Dashboard métriques
- [ ] Export rapports
- [ ] Alertes (taux escalade élevé, temps réponse long)
- [ ] Suggestions automatiques (FAQ à créer)

---

### Ordre de dépendances

```
Phase 1 (Base)
    ↓
Phase 2 (Interface Admin)
    ↓
    ├──→ Phase 3 (Temps réel)
    │
    └──→ Phase 4 (Email)
              ↓
         Phase 5 (Apprentissage)
              ↓
         Phase 6 (Analytiques)
```

> **Parallélisation possible** : Les phases 3 et 4 peuvent être développées en parallèle par 2 développeurs, réduisant le temps total à ~6-7 semaines.

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
3. **Chatbot widget** : Intégrer sur sites externes ou uniquement backoffice ?
4. **SLA** : Définir des niveaux de service avec alertes ?
5. **Escalade en chaîne** : Permettre escalade admin → admin senior ?

### Questions résolues

| Question | Décision |
|----------|----------|
| **Fournisseur email** | IMAP recommandé (gratuit), Mailgun/SendGrid en option premium |
| **Coût fournisseur** | IMAP = 0€, Mailgun ~10-30€/mois, SendGrid ~15-25€/mois |
| **Connexion boîte mail** | IMAP polling toutes les minutes, webhooks pour temps réel si besoin |
| **Instructions anti-spam** | Incluses dans le premier email de confirmation avec guide de whitelist |
| **Intégration modules** | Support humain dans "Agents IA", email config dans "Déploiement Agent IA" |
| **Collecte email utilisateur** | Formulaire dans le widget de chat lors de l'escalade asynchrone |
| **Pièces jointes** | Oui, avec sécurité : extensions limitées, 10 Mo max, scan ClamAV |

---

## 13. Fichiers à créer

```
app/
├── Models/
│   ├── SupportConversation.php
│   ├── SupportMessage.php
│   ├── SupportAttachment.php          # NOUVEAU
│   ├── SupportEmailThread.php
│   └── AdminAvailability.php
├── Services/
│   └── Support/
│       ├── EscalationService.php
│       ├── ConversationService.php
│       ├── SupportTrainingService.php
│       ├── ConversationToMarkdownService.php
│       ├── EmailReplyParser.php
│       ├── AttachmentSecurityService.php
│       └── AgentAssistanceService.php       # Assistance IA pour l'agent
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
│       ├── FetchImapEmailsJob.php           # Renommé (IMAP spécifique)
│       ├── ProcessIncomingEmailJob.php      # NOUVEAU
│       ├── ScanAttachmentJob.php            # NOUVEAU
│       ├── IndexLearnedResponseJob.php
│       └── IndexConversationAsDocumentJob.php
├── Mail/
│   └── Support/
│       ├── EscalationNotificationMail.php      # Notification aux agents de support
│       ├── EscalationConfirmationMail.php      # Confirmation à l'utilisateur
│       └── SupportResponseMail.php             # Réponse admin à l'utilisateur
├── Filament/
│   └── Pages/
│       ├── LiveSupport.php
│       └── SupportAnalytics.php
├── Http/
│   └── Controllers/
│       ├── SupportChatController.php
│       └── Api/
│           └── SupportWebhookController.php
├── Exceptions/
│   └── InvalidAttachmentException.php       # NOUVEAU
database/
├── migrations/
│   ├── xxxx_create_support_conversations_table.php
│   ├── xxxx_create_support_messages_table.php
│   ├── xxxx_create_support_attachments_table.php
│   ├── xxxx_create_support_email_threads_table.php
│   ├── xxxx_create_admin_availability_table.php
│   ├── xxxx_create_agent_support_users_table.php   # Pivot agents ↔ support users
│   └── xxxx_add_support_fields_to_agents_table.php
└── seeders/
    └── SupportAgentRoleSeeder.php                  # Rôle "support-agent"
resources/
└── views/
    ├── components/
    │   └── support/
    │       └── qr-correction-form.blade.php
    ├── emails/
    │   └── support/
    │       ├── escalation-confirmation.blade.php   # Email à l'utilisateur
    │       ├── escalation-notification.blade.php   # Email aux agents de support
    │       └── response.blade.php                  # Réponse admin à l'utilisateur
    ├── filament/
    │   └── pages/
    │       ├── live-support.blade.php
    │       └── support-analytics.blade.php
    └── support/
        └── chat-widget.blade.php
```

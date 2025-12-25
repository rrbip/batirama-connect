# Schéma de Base de Données

> **Référence** : [00_index.md](./00_index.md)
> **Statut** : Spécifications validées

---

## Vue d'Ensemble

Le système utilise deux types de stockage :
- **PostgreSQL 17** : Données relationnelles (agents, sessions, utilisateurs, ouvrages)
- **Qdrant 1.16** : Données vectorielles (embeddings pour la recherche sémantique)

---

## Diagramme Entité-Relation (PostgreSQL)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              AUTHENTIFICATION                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐     ┌──────────────┐     ┌──────────────────────────┐     │
│  │    users     │────▶│  user_roles  │◀────│         roles            │     │
│  └──────────────┘     └──────────────┘     └──────────────────────────┘     │
│         │                                             │                      │
│         │                                             ▼                      │
│         │                                  ┌──────────────────────────┐     │
│         │                                  │    role_permissions      │     │
│         │                                  └──────────────────────────┘     │
│         │                                             │                      │
│         │                                             ▼                      │
│         │                                  ┌──────────────────────────┐     │
│         │                                  │      permissions         │     │
│         │                                  └──────────────────────────┘     │
│         │                                                                    │
│         ▼                                                                    │
│  ┌──────────────┐     ┌──────────────┐                                      │
│  │ api_tokens   │     │   tenants    │◀──── Multi-tenant (futur)            │
│  └──────────────┘     └──────────────┘                                      │
│                              │                                               │
└──────────────────────────────┼───────────────────────────────────────────────┘
                               │
┌──────────────────────────────┼───────────────────────────────────────────────┐
│                              │        AGENTS IA                              │
├──────────────────────────────┼───────────────────────────────────────────────┤
│                              │                                               │
│                              ▼                                               │
│  ┌───────────────────────────────────────────────────────────────────────┐  │
│  │                            agents                                      │  │
│  │  - id, name, slug, system_prompt                                       │  │
│  │  - qdrant_collection, retrieval_mode, hydration_config                 │  │
│  │  - ollama_host, ollama_port, model                                     │  │
│  └───────────────────────────────────────────────────────────────────────┘  │
│         │                                                                    │
│         │                    ┌──────────────────────────────┐               │
│         ▼                    │     system_prompt_versions   │               │
│  ┌──────────────┐            │  (historique des prompts)    │               │
│  │ ai_sessions  │            └──────────────────────────────┘               │
│  └──────────────┘                                                            │
│         │                                                                    │
│         ▼                                                                    │
│  ┌──────────────┐     ┌──────────────┐                                      │
│  │ ai_messages  │────▶│ai_feedbacks  │                                      │
│  └──────────────┘     └──────────────┘                                      │
│                                                                              │
└──────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                              MÉTIER BTP                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐                                                           │
│  │   ouvrages   │◀──┐ (self-reference : parent_id)                          │
│  │              │───┘                                                       │
│  └──────────────┘                                                           │
│         │                                                                    │
│         ├──────────────────────────────────┐                                │
│         ▼                                  ▼                                │
│  ┌──────────────┐                   ┌──────────────┐                        │
│  │ fournitures  │                   │ main_oeuvres │                        │
│  └──────────────┘                   └──────────────┘                        │
│                                                                              │
│  ┌──────────────┐     ┌──────────────┐                                      │
│  │ dynamic_tables│    │ import_logs  │                                      │
│  │ (métadonnées) │    │              │                                      │
│  └──────────────┘     └──────────────┘                                      │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                          PARTENAIRES & INTÉGRATIONS                          │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐                                                           │
│  │   partners   │ ◀──── ZOOMBAT, EBP, Batigest, etc.                        │
│  │  - api_key   │                                                           │
│  │  - data_access│                                                          │
│  └──────────────┘                                                           │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                              WEBHOOKS & API                                  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐     ┌──────────────┐     ┌──────────────┐                 │
│  │  webhooks    │────▶│webhook_logs  │     │  audit_logs  │                 │
│  └──────────────┘     └──────────────┘     └──────────────┘                 │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Tables PostgreSQL - Détail

### Authentification & Autorisation

#### Table : `users`

```sql
CREATE TABLE users (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,
    tenant_id       BIGINT REFERENCES tenants(id) ON DELETE SET NULL,

    name            VARCHAR(255) NOT NULL,
    email           VARCHAR(255) UNIQUE NOT NULL,
    email_verified_at TIMESTAMP NULL,
    password        VARCHAR(255) NOT NULL,

    remember_token  VARCHAR(100) NULL,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at      TIMESTAMP NULL
);

CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_tenant ON users(tenant_id);
CREATE INDEX idx_users_uuid ON users(uuid);
```

#### Table : `roles`

```sql
CREATE TABLE roles (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(50) UNIQUE NOT NULL,
    slug            VARCHAR(50) UNIQUE NOT NULL,
    description     TEXT NULL,
    is_system       BOOLEAN DEFAULT FALSE,  -- Rôles non supprimables

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Rôles par défaut
INSERT INTO roles (name, slug, description, is_system) VALUES
    ('Super Admin', 'super-admin', 'Accès complet au système', TRUE),
    ('Admin', 'admin', 'Administration des agents et utilisateurs', TRUE),
    ('Métreur', 'metreur', 'Validation et correction des devis IA, gestion des ouvrages', TRUE),
    ('Validateur', 'validator', 'Validation des réponses IA', TRUE),
    ('Utilisateur', 'user', 'Utilisation des agents IA', TRUE),
    ('API Client', 'api-client', 'Accès API uniquement (marque blanche)', TRUE);
```

#### Table : `permissions`

```sql
CREATE TABLE permissions (
    id              BIGSERIAL PRIMARY KEY,
    name            VARCHAR(100) UNIQUE NOT NULL,
    slug            VARCHAR(100) UNIQUE NOT NULL,
    group_name      VARCHAR(50) NOT NULL,  -- agents, users, ouvrages, etc.
    description     TEXT NULL,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Permissions par défaut
INSERT INTO permissions (name, slug, group_name) VALUES
    -- Agents
    ('Voir les agents', 'agents.view', 'agents'),
    ('Créer un agent', 'agents.create', 'agents'),
    ('Modifier un agent', 'agents.update', 'agents'),
    ('Supprimer un agent', 'agents.delete', 'agents'),

    -- Sessions IA
    ('Voir les sessions', 'ai-sessions.view', 'ai'),
    ('Valider les réponses', 'ai-sessions.validate', 'ai'),
    ('Déclencher l''apprentissage', 'ai-sessions.learn', 'ai'),

    -- Ouvrages
    ('Voir les ouvrages', 'ouvrages.view', 'ouvrages'),
    ('Importer des ouvrages', 'ouvrages.import', 'ouvrages'),
    ('Indexer dans Qdrant', 'ouvrages.index', 'ouvrages'),

    -- Utilisateurs
    ('Gérer les utilisateurs', 'users.manage', 'users'),
    ('Gérer les rôles', 'roles.manage', 'users'),

    -- API
    ('Accès API', 'api.access', 'api'),
    ('Gérer les webhooks', 'webhooks.manage', 'api');
```

#### Table : `user_roles`

```sql
CREATE TABLE user_roles (
    id              BIGSERIAL PRIMARY KEY,
    user_id         BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id         BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE(user_id, role_id)
);
```

#### Table : `role_permissions`

```sql
CREATE TABLE role_permissions (
    id              BIGSERIAL PRIMARY KEY,
    role_id         BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id   BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,

    UNIQUE(role_id, permission_id)
);
```

#### Table : `api_tokens`

```sql
CREATE TABLE api_tokens (
    id              BIGSERIAL PRIMARY KEY,
    user_id         BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,

    name            VARCHAR(255) NOT NULL,
    token           VARCHAR(64) UNIQUE NOT NULL,  -- Hash SHA-256
    abilities       JSONB DEFAULT '["*"]',

    last_used_at    TIMESTAMP NULL,
    expires_at      TIMESTAMP NULL,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_api_tokens_token ON api_tokens(token);
CREATE INDEX idx_api_tokens_user ON api_tokens(user_id);
```

#### Table : `public_access_tokens`

Permet de générer des liens publics pour accéder à un agent IA sans authentification.
Utilisé pour envoyer un lien au client final (ex: demande de devis).

```sql
CREATE TABLE public_access_tokens (
    id              BIGSERIAL PRIMARY KEY,
    token           VARCHAR(64) UNIQUE NOT NULL,  -- Token unique dans l'URL

    -- Liaison agent
    agent_id        BIGINT NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
    created_by      BIGINT NOT NULL REFERENCES users(id),  -- Artisan/utilisateur qui génère le lien
    tenant_id       BIGINT REFERENCES tenants(id) ON DELETE SET NULL,

    -- Liaison application tierce (pour retrouver le dossier)
    external_app    VARCHAR(100) NULL,      -- "batigest", "ebp", "sage", "custom", etc.
    external_ref    VARCHAR(255) NULL,      -- ID du dossier/client dans l'app tierce
    external_meta   JSONB NULL,             -- Métadonnées supplémentaires
    -- Exemple : {"client_name": "Dupont", "project": "Rénovation SDB", "dossier_id": "D-2025-001"}

    -- Session créée (rempli quand le client utilise le lien)
    session_id      BIGINT REFERENCES ai_sessions(id) ON DELETE SET NULL,

    -- Infos client (collectées pendant la conversation ou pré-remplies)
    client_info     JSONB NULL,
    -- Structure : {"name": "...", "email": "...", "phone": "...", "address": "..."}

    -- Validité et sécurité
    expires_at      TIMESTAMP NOT NULL,     -- Date d'expiration obligatoire
    max_uses        INTEGER DEFAULT 1,      -- Nombre d'utilisations max (1 = usage unique)
    use_count       INTEGER DEFAULT 0,      -- Compteur d'utilisations

    -- Statut
    status          VARCHAR(20) DEFAULT 'active',
    -- Valeurs : 'active', 'used', 'expired', 'revoked'

    -- Tracking
    first_used_at   TIMESTAMP NULL,
    last_used_at    TIMESTAMP NULL,
    last_ip         INET NULL,              -- IP du dernier accès
    last_user_agent TEXT NULL,              -- User-Agent du dernier accès

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_public_tokens_token ON public_access_tokens(token);
CREATE INDEX idx_public_tokens_agent ON public_access_tokens(agent_id);
CREATE INDEX idx_public_tokens_external ON public_access_tokens(external_app, external_ref);
CREATE INDEX idx_public_tokens_status ON public_access_tokens(status) WHERE status = 'active';
CREATE INDEX idx_public_tokens_session ON public_access_tokens(session_id);

-- Contrainte sur le statut
ALTER TABLE public_access_tokens ADD CONSTRAINT chk_token_status
    CHECK (status IN ('active', 'used', 'expired', 'revoked'));
```

**URL générée** : `https://monsite.com/c/{token}`

**Exemple d'utilisation** :
```php
// Génération d'un lien depuis l'API (appelée par le logiciel tiers)
POST /api/public-tokens
{
    "agent_slug": "expert-btp",
    "external_app": "batigest",
    "external_ref": "DOSSIER-2025-001",
    "external_meta": {
        "client_name": "M. Dupont",
        "project_type": "renovation_sdb"
    },
    "expires_in_hours": 168  // Optionnel, sinon default de l'agent
}

// Réponse
{
    "token": "abc123xyz789...",
    "url": "https://monsite.com/c/abc123xyz789...",
    "expires_at": "2025-01-05T10:30:00Z"
}
```

#### Table : `tenants` (Multi-tenant futur)

```sql
CREATE TABLE tenants (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,

    name            VARCHAR(255) NOT NULL,
    slug            VARCHAR(100) UNIQUE NOT NULL,
    domain          VARCHAR(255) UNIQUE NULL,  -- Domaine personnalisé

    settings        JSONB DEFAULT '{}',
    is_active       BOOLEAN DEFAULT TRUE,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at      TIMESTAMP NULL
);
```

---

### Agents IA

#### Table : `agents`

```sql
CREATE TABLE agents (
    id                  BIGSERIAL PRIMARY KEY,
    tenant_id           BIGINT REFERENCES tenants(id) ON DELETE SET NULL,

    -- Identification
    name                VARCHAR(255) NOT NULL,
    slug                VARCHAR(100) UNIQUE NOT NULL,
    description         TEXT NULL,
    icon                VARCHAR(50) DEFAULT 'robot',  -- Icône Heroicons
    color               VARCHAR(7) DEFAULT '#3B82F6', -- Couleur hex

    -- Configuration IA
    system_prompt       TEXT NOT NULL,

    -- Configuration Qdrant
    qdrant_collection   VARCHAR(100) NOT NULL,

    -- Mode de récupération
    retrieval_mode      VARCHAR(20) NOT NULL DEFAULT 'TEXT_ONLY',
    -- Valeurs : 'TEXT_ONLY', 'SQL_HYDRATION'

    hydration_config    JSONB NULL,
    -- Exemple : {"table": "ouvrages", "key": "db_id", "fields": ["*"]}

    -- Configuration Ollama (override global)
    ollama_host         VARCHAR(255) NULL,  -- NULL = utilise config globale
    ollama_port         INTEGER NULL,
    model               VARCHAR(100) NULL,  -- NULL = utilise config globale
    fallback_model      VARCHAR(100) NULL,

    -- Paramètres de contexte
    context_window_size INTEGER DEFAULT 10,  -- Nb messages historique
    max_tokens          INTEGER DEFAULT 2048,
    temperature         DECIMAL(3,2) DEFAULT 0.7,

    -- Configuration RAG avancée
    max_rag_results     INTEGER DEFAULT 5,          -- Nb résultats RAG (BTP: 50, Support: 5)
    allow_iterative_search BOOLEAN DEFAULT FALSE,   -- Permet recherches multiples
    response_format     VARCHAR(20) DEFAULT 'text', -- 'text', 'json', 'markdown'
    allow_attachments   BOOLEAN DEFAULT TRUE,       -- Photos/docs dans le chat

    -- Configuration accès public
    allow_public_access BOOLEAN DEFAULT FALSE,      -- Autorise les liens publics
    default_token_expiry_hours INTEGER DEFAULT 168, -- 7 jours par défaut

    -- Statut
    is_active           BOOLEAN DEFAULT TRUE,

    -- Métadonnées
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at          TIMESTAMP NULL
);

CREATE INDEX idx_agents_slug ON agents(slug);
CREATE INDEX idx_agents_tenant ON agents(tenant_id);
CREATE INDEX idx_agents_active ON agents(is_active) WHERE is_active = TRUE;

-- Contrainte sur retrieval_mode
ALTER TABLE agents ADD CONSTRAINT chk_retrieval_mode
    CHECK (retrieval_mode IN ('TEXT_ONLY', 'SQL_HYDRATION'));
```

**Exemples de configuration `hydration_config` :**

```json
// Agent BTP - Hydratation depuis la table ouvrages
{
    "table": "ouvrages",
    "key": "db_id",
    "fields": ["*"],
    "relations": ["fournitures", "main_oeuvres", "children"]
}

// Agent Support - Pas d'hydratation (TEXT_ONLY)
null

// Agent Produits - Hydratation depuis une table personnalisée
{
    "table": "produits_marketplace",
    "key": "product_id",
    "fields": ["nom", "prix", "description", "stock"],
    "relations": []
}
```

#### Table : `system_prompt_versions`

```sql
CREATE TABLE system_prompt_versions (
    id              BIGSERIAL PRIMARY KEY,
    agent_id        BIGINT NOT NULL REFERENCES agents(id) ON DELETE CASCADE,

    version         INTEGER NOT NULL,
    system_prompt   TEXT NOT NULL,
    change_note     TEXT NULL,

    created_by      BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE(agent_id, version)
);

CREATE INDEX idx_prompt_versions_agent ON system_prompt_versions(agent_id);
```

---

### Sessions et Messages IA

#### Table : `ai_sessions`

```sql
CREATE TABLE ai_sessions (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,

    agent_id        BIGINT NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
    user_id         BIGINT REFERENCES users(id) ON DELETE SET NULL,
    tenant_id       BIGINT REFERENCES tenants(id) ON DELETE SET NULL,

    -- Accès public (si créé via lien public)
    public_token_id BIGINT REFERENCES public_access_tokens(id) ON DELETE SET NULL,

    -- Contexte externe (pour intégration écosystème)
    external_session_id VARCHAR(255) NULL,  -- ID session logiciel tiers
    external_context    JSONB NULL,         -- Données contextuelles

    -- Métadonnées
    title           VARCHAR(255) NULL,      -- Titre auto-généré ou manuel

    -- Statistiques
    message_count   INTEGER DEFAULT 0,

    -- Statut
    status          VARCHAR(20) DEFAULT 'active',
    -- Valeurs : 'active', 'archived', 'deleted'

    closed_at       TIMESTAMP NULL,

    -- Conversion (rempli par callback du partenaire)
    conversion_status   VARCHAR(20) DEFAULT NULL,
    -- Valeurs : 'quoted', 'accepted', 'rejected', 'completed'
    conversion_amount   DECIMAL(12,2) DEFAULT NULL,  -- Montant final du devis
    conversion_at       TIMESTAMP DEFAULT NULL,

    -- Commission (pour leads marketplace)
    commission_rate     DECIMAL(5,2) DEFAULT NULL,   -- NULL = pas de commission (scénario 1)
    commission_amount   DECIMAL(12,2) DEFAULT NULL,  -- Montant calculé
    commission_status   VARCHAR(20) DEFAULT NULL,
    -- Valeurs : 'pending', 'invoiced', 'paid'

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_ai_sessions_uuid ON ai_sessions(uuid);
CREATE INDEX idx_ai_sessions_agent ON ai_sessions(agent_id);
CREATE INDEX idx_ai_sessions_user ON ai_sessions(user_id);
CREATE INDEX idx_ai_sessions_external ON ai_sessions(external_session_id);
CREATE INDEX idx_ai_sessions_created ON ai_sessions(created_at DESC);
```

#### Table : `ai_messages`

```sql
CREATE TABLE ai_messages (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,
    session_id      BIGINT NOT NULL REFERENCES ai_sessions(id) ON DELETE CASCADE,

    -- Type de message
    role            VARCHAR(20) NOT NULL,
    -- Valeurs : 'user', 'assistant', 'system'

    -- Contenu
    content         TEXT NOT NULL,

    -- Pièces jointes (photos, documents envoyés dans le chat)
    attachments     JSONB NULL,
    -- Structure : [
    --   {"document_id": 123, "type": "image", "name": "photo_sdb.jpg", "mime": "image/jpeg"},
    --   {"document_id": 124, "type": "pdf", "name": "plan.pdf", "mime": "application/pdf"}
    -- ]

    -- Métadonnées RAG (pour les réponses assistant)
    rag_context     JSONB NULL,
    -- Structure : {
    --   "sources": [{"id": "...", "score": 0.85, "content": "..."}],
    --   "hydrated_data": {...},
    --   "retrieval_mode": "SQL_HYDRATION"
    -- }

    -- Métadonnées de génération
    model_used      VARCHAR(100) NULL,
    used_fallback_model BOOLEAN DEFAULT FALSE,  -- Indique si le modèle de fallback a été utilisé
    tokens_prompt   INTEGER NULL,
    tokens_completion INTEGER NULL,
    generation_time_ms INTEGER NULL,

    -- Validation humaine
    validation_status VARCHAR(20) DEFAULT 'pending',
    -- Valeurs : 'pending', 'validated', 'rejected', 'learned'

    validated_by    BIGINT REFERENCES users(id) ON DELETE SET NULL,
    validated_at    TIMESTAMP NULL,

    -- Réponse corrigée (pour apprentissage)
    corrected_content TEXT NULL,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_ai_messages_session ON ai_messages(session_id);
CREATE INDEX idx_ai_messages_role ON ai_messages(role);
CREATE INDEX idx_ai_messages_validation ON ai_messages(validation_status);
CREATE INDEX idx_ai_messages_created ON ai_messages(created_at DESC);

-- Index pour la recherche des messages à valider
CREATE INDEX idx_ai_messages_pending ON ai_messages(validation_status, created_at)
    WHERE validation_status = 'pending' AND role = 'assistant';
```

#### Table : `ai_feedbacks`

```sql
CREATE TABLE ai_feedbacks (
    id              BIGSERIAL PRIMARY KEY,
    message_id      BIGINT NOT NULL REFERENCES ai_messages(id) ON DELETE CASCADE,
    user_id         BIGINT REFERENCES users(id) ON DELETE SET NULL,

    -- Feedback
    rating          SMALLINT NULL CHECK (rating BETWEEN 1 AND 5),
    is_helpful      BOOLEAN NULL,  -- Thumbs up/down
    comment         TEXT NULL,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_ai_feedbacks_message ON ai_feedbacks(message_id);
CREATE INDEX idx_ai_feedbacks_rating ON ai_feedbacks(rating);
```

---

### Métier BTP

#### Table : `ouvrages`

```sql
CREATE TABLE ouvrages (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT REFERENCES tenants(id) ON DELETE SET NULL,

    -- Hiérarchie
    parent_id       BIGINT REFERENCES ouvrages(id) ON DELETE SET NULL,
    path            LTREE NULL,  -- Pour requêtes hiérarchiques efficaces
    depth           INTEGER DEFAULT 0,

    -- Identification
    code            VARCHAR(50) NOT NULL,
    name            VARCHAR(255) NOT NULL,
    description     TEXT NULL,

    -- Classification
    type            VARCHAR(50) NOT NULL,
    -- Valeurs : 'compose', 'simple', 'fourniture', 'main_oeuvre'

    category        VARCHAR(100) NULL,
    subcategory     VARCHAR(100) NULL,

    -- Prix
    unit            VARCHAR(20) NOT NULL,  -- m², ml, U, h, kg, etc.
    unit_price      DECIMAL(12, 4) NULL,
    currency        VARCHAR(3) DEFAULT 'EUR',

    -- Quantités (pour ouvrages composés)
    default_quantity DECIMAL(10, 4) DEFAULT 1,

    -- Métadonnées techniques
    technical_specs JSONB DEFAULT '{}',
    -- Exemple : {"epaisseur": "13mm", "resistance": "M1"}

    -- Indexation Qdrant
    is_indexed      BOOLEAN DEFAULT FALSE,
    indexed_at      TIMESTAMP NULL,
    qdrant_point_id VARCHAR(100) NULL,  -- ID du point dans Qdrant

    -- Source import
    import_source   VARCHAR(50) NULL,
    import_id       VARCHAR(100) NULL,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at      TIMESTAMP NULL
);

-- Extension pour LTREE (chemins hiérarchiques)
CREATE EXTENSION IF NOT EXISTS ltree;

CREATE INDEX idx_ouvrages_parent ON ouvrages(parent_id);
CREATE INDEX idx_ouvrages_path ON ouvrages USING GIST(path);
CREATE INDEX idx_ouvrages_code ON ouvrages(code);
CREATE INDEX idx_ouvrages_type ON ouvrages(type);
CREATE INDEX idx_ouvrages_indexed ON ouvrages(is_indexed);
CREATE INDEX idx_ouvrages_tenant ON ouvrages(tenant_id);

-- Index pour recherche full-text
CREATE INDEX idx_ouvrages_search ON ouvrages
    USING GIN(to_tsvector('french', name || ' ' || COALESCE(description, '')));
```

#### Table : `ouvrage_components` (Relation N:M pour composition)

```sql
CREATE TABLE ouvrage_components (
    id              BIGSERIAL PRIMARY KEY,
    parent_id       BIGINT NOT NULL REFERENCES ouvrages(id) ON DELETE CASCADE,
    component_id    BIGINT NOT NULL REFERENCES ouvrages(id) ON DELETE CASCADE,

    quantity        DECIMAL(10, 4) NOT NULL DEFAULT 1,
    unit            VARCHAR(20) NULL,  -- Peut différer de l'unité du composant

    sort_order      INTEGER DEFAULT 0,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE(parent_id, component_id)
);

CREATE INDEX idx_ouvrage_components_parent ON ouvrage_components(parent_id);
CREATE INDEX idx_ouvrage_components_component ON ouvrage_components(component_id);
```

---

### Ingestion de Documents Multi-Formats

Le système permet d'ingérer différents types de documents pour l'apprentissage IA :
- **Texte** : TXT, MD, HTML
- **Documents** : PDF, DOC, DOCX, ODT
- **Tableurs** : CSV, XLS, XLSX
- **Multimédia** : MP3, WAV, MP4, WEBM (transcription via Whisper)
- **Images** : PNG, JPG (OCR optionnel)

#### Table : `documents`

```sql
CREATE TABLE documents (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,
    tenant_id       BIGINT REFERENCES tenants(id) ON DELETE SET NULL,
    agent_id        BIGINT REFERENCES agents(id) ON DELETE SET NULL,

    -- Fichier original
    original_name   VARCHAR(255) NOT NULL,
    storage_path    VARCHAR(500) NOT NULL,
    mime_type       VARCHAR(100) NOT NULL,
    file_size       BIGINT NOT NULL,  -- En bytes
    file_hash       VARCHAR(64) NULL, -- SHA-256 pour déduplication

    -- Classification
    document_type   VARCHAR(50) NOT NULL,
    -- Valeurs : 'text', 'pdf', 'document', 'spreadsheet', 'audio', 'video', 'image'

    category        VARCHAR(100) NULL,
    tags            VARCHAR(255)[] DEFAULT '{}',

    -- Extraction
    extraction_status VARCHAR(20) DEFAULT 'pending',
    -- Valeurs : 'pending', 'processing', 'completed', 'failed'

    extracted_text  TEXT NULL,          -- Texte extrait (brut)
    extraction_metadata JSONB NULL,     -- Métadonnées d'extraction
    -- Structure : {
    --   "pages": 5,
    --   "duration_seconds": 120,
    --   "language": "fr",
    --   "confidence": 0.95,
    --   "extractor": "pdftotext"
    -- }

    extraction_error TEXT NULL,
    extracted_at    TIMESTAMP NULL,

    -- Chunking (découpage pour RAG)
    chunk_count     INTEGER DEFAULT 0,
    chunk_strategy  VARCHAR(50) DEFAULT 'paragraph',
    -- Valeurs : 'paragraph', 'sentence', 'fixed_size', 'semantic'

    -- Indexation Qdrant
    is_indexed      BOOLEAN DEFAULT FALSE,
    indexed_at      TIMESTAMP NULL,

    -- Métadonnées utilisateur
    title           VARCHAR(255) NULL,
    description     TEXT NULL,
    source_url      VARCHAR(2048) NULL,

    -- Upload
    uploaded_by     BIGINT REFERENCES users(id) ON DELETE SET NULL,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at      TIMESTAMP NULL
);

CREATE INDEX idx_documents_tenant ON documents(tenant_id);
CREATE INDEX idx_documents_agent ON documents(agent_id);
CREATE INDEX idx_documents_type ON documents(document_type);
CREATE INDEX idx_documents_status ON documents(extraction_status);
CREATE INDEX idx_documents_indexed ON documents(is_indexed);
CREATE INDEX idx_documents_hash ON documents(file_hash);

-- Recherche full-text sur le contenu extrait
CREATE INDEX idx_documents_content_search ON documents
    USING GIN(to_tsvector('french', COALESCE(title, '') || ' ' || COALESCE(extracted_text, '')));
```

#### Table : `document_chunks`

Stocke les morceaux de texte découpés pour une indexation RAG optimale.

```sql
CREATE TABLE document_chunks (
    id              BIGSERIAL PRIMARY KEY,
    document_id     BIGINT NOT NULL REFERENCES documents(id) ON DELETE CASCADE,

    -- Position dans le document
    chunk_index     INTEGER NOT NULL,
    start_offset    INTEGER NULL,   -- Position caractère début
    end_offset      INTEGER NULL,   -- Position caractère fin
    page_number     INTEGER NULL,   -- Pour PDF

    -- Contenu
    content         TEXT NOT NULL,
    content_hash    VARCHAR(64) NOT NULL,  -- Pour déduplication
    token_count     INTEGER NULL,   -- Estimation tokens

    -- Métadonnées contextuelles
    context_before  TEXT NULL,      -- Contexte précédent (optionnel)
    context_after   TEXT NULL,      -- Contexte suivant (optionnel)
    metadata        JSONB NULL,
    -- Structure : {"heading": "Section 2.1", "timestamp": "00:02:30"}

    -- Indexation Qdrant
    qdrant_point_id VARCHAR(100) NULL,
    is_indexed      BOOLEAN DEFAULT FALSE,
    indexed_at      TIMESTAMP NULL,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_chunks_document ON document_chunks(document_id);
CREATE INDEX idx_chunks_indexed ON document_chunks(is_indexed);
CREATE INDEX idx_chunks_hash ON document_chunks(content_hash);
CREATE UNIQUE INDEX idx_chunks_document_index ON document_chunks(document_id, chunk_index);
```

#### Types MIME Supportés

```php
// config/documents.php
return [
    'allowed_types' => [
        // Texte
        'text/plain' => ['extensions' => ['txt'], 'extractor' => 'text'],
        'text/markdown' => ['extensions' => ['md'], 'extractor' => 'text'],
        'text/html' => ['extensions' => ['html', 'htm'], 'extractor' => 'html'],

        // Documents
        'application/pdf' => ['extensions' => ['pdf'], 'extractor' => 'pdf'],
        'application/msword' => ['extensions' => ['doc'], 'extractor' => 'office'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' =>
            ['extensions' => ['docx'], 'extractor' => 'office'],
        'application/vnd.oasis.opendocument.text' =>
            ['extensions' => ['odt'], 'extractor' => 'office'],

        // Tableurs
        'text/csv' => ['extensions' => ['csv'], 'extractor' => 'csv'],
        'application/vnd.ms-excel' => ['extensions' => ['xls'], 'extractor' => 'spreadsheet'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' =>
            ['extensions' => ['xlsx'], 'extractor' => 'spreadsheet'],

        // Audio (transcription Whisper)
        'audio/mpeg' => ['extensions' => ['mp3'], 'extractor' => 'whisper'],
        'audio/wav' => ['extensions' => ['wav'], 'extractor' => 'whisper'],
        'audio/ogg' => ['extensions' => ['ogg'], 'extractor' => 'whisper'],
        'audio/webm' => ['extensions' => ['weba'], 'extractor' => 'whisper'],

        // Vidéo (extraction audio + transcription)
        'video/mp4' => ['extensions' => ['mp4'], 'extractor' => 'whisper'],
        'video/webm' => ['extensions' => ['webm'], 'extractor' => 'whisper'],
        'video/x-msvideo' => ['extensions' => ['avi'], 'extractor' => 'whisper'],

        // Images (OCR optionnel)
        'image/png' => ['extensions' => ['png'], 'extractor' => 'ocr'],
        'image/jpeg' => ['extensions' => ['jpg', 'jpeg'], 'extractor' => 'ocr'],
    ],

    'max_file_size' => env('DOCUMENT_MAX_SIZE', 104857600), // 100 MB

    'chunk_settings' => [
        'default_strategy' => 'paragraph',
        'max_chunk_size' => 1000,    // Tokens approximatifs
        'chunk_overlap' => 100,       // Chevauchement entre chunks
    ],
];
```

---

### Tables Dynamiques

#### Table : `dynamic_tables` (Métadonnées)

```sql
CREATE TABLE dynamic_tables (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT REFERENCES tenants(id) ON DELETE CASCADE,

    -- Identification
    name            VARCHAR(100) NOT NULL,
    table_name      VARCHAR(100) UNIQUE NOT NULL,  -- Nom SQL réel
    description     TEXT NULL,

    -- Schéma
    schema_definition JSONB NOT NULL,
    -- Structure : {
    --   "columns": [
    --     {"name": "code", "type": "string", "nullable": false, "indexed": true},
    --     {"name": "prix", "type": "decimal", "precision": 10, "scale": 2}
    --   ],
    --   "primary_key": "id",
    --   "indexes": [["code"], ["category", "name"]]
    -- }

    -- Configuration Qdrant
    qdrant_collection VARCHAR(100) NULL,
    embedding_template TEXT NULL,  -- Template pour générer le texte à vectoriser
    -- Exemple : "{{name}} - {{description}}. Prix: {{prix}}€"

    -- Statistiques
    row_count       INTEGER DEFAULT 0,
    indexed_count   INTEGER DEFAULT 0,

    created_by      BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_dynamic_tables_tenant ON dynamic_tables(tenant_id);
CREATE INDEX idx_dynamic_tables_name ON dynamic_tables(table_name);
```

#### Table : `import_logs`

```sql
CREATE TABLE import_logs (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT REFERENCES tenants(id) ON DELETE SET NULL,

    -- Source
    source_type     VARCHAR(50) NOT NULL,
    -- Valeurs : 'csv', 'json', 'excel', 'api', 'database'

    source_name     VARCHAR(255) NULL,  -- Nom du fichier ou endpoint

    -- Cible
    target_table    VARCHAR(100) NOT NULL,

    -- Résultat
    status          VARCHAR(20) NOT NULL,
    -- Valeurs : 'pending', 'processing', 'completed', 'failed'

    total_rows      INTEGER DEFAULT 0,
    imported_rows   INTEGER DEFAULT 0,
    failed_rows     INTEGER DEFAULT 0,

    errors          JSONB NULL,
    -- Structure : [{"row": 5, "error": "Invalid format", "data": {...}}]

    started_at      TIMESTAMP NULL,
    completed_at    TIMESTAMP NULL,

    created_by      BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_import_logs_tenant ON import_logs(tenant_id);
CREATE INDEX idx_import_logs_status ON import_logs(status);
CREATE INDEX idx_import_logs_created ON import_logs(created_at DESC);
```

---

### Partenaires (Intégrations Logiciels BTP)

Permet l'intégration avec des logiciels tiers (ZOOMBAT, EBP, Batigest, etc.) via API.

#### Table : `partners`

```sql
CREATE TABLE partners (
    id              BIGSERIAL PRIMARY KEY,

    -- Identification
    name            VARCHAR(100) NOT NULL,      -- "ZOOMBAT", "EBP Bâtiment", etc.
    slug            VARCHAR(50) UNIQUE NOT NULL, -- "zoombat", "ebp"
    description     TEXT NULL,
    logo_url        VARCHAR(500) NULL,

    -- Authentification API
    api_key         VARCHAR(64) UNIQUE NOT NULL, -- Clé API unique
    api_key_prefix  VARCHAR(10) NOT NULL,        -- Préfixe visible (ex: "zb_")

    -- Configuration
    webhook_url     VARCHAR(255) NULL,           -- URL callback du partenaire
    default_agent   VARCHAR(50) DEFAULT 'expert-btp',

    -- Niveau d'accès aux données de session
    data_access     VARCHAR(20) DEFAULT 'summary',
    -- Valeurs : 'summary', 'full', 'custom'
    -- summary : Résumé + pré-devis + pièces jointes (défaut partenaires)
    -- full    : Conversation complète + métadonnées (ZOOMBAT interne)
    -- custom  : Champs spécifiques définis dans data_fields

    data_fields     JSONB DEFAULT NULL,
    -- Si data_access = 'custom', liste des champs autorisés
    -- Exemple : ["summary", "quote", "conversation", "attachments"]

    -- Commission (pour marketplace)
    commission_rate DECIMAL(5,2) DEFAULT 5.00,   -- % de commission sur leads

    -- Notifications
    notify_on_session_complete BOOLEAN DEFAULT TRUE,
    notify_on_conversion       BOOLEAN DEFAULT TRUE,

    -- Statistiques
    sessions_count    INTEGER DEFAULT 0,
    conversions_count INTEGER DEFAULT 0,
    total_commission  DECIMAL(12,2) DEFAULT 0,

    -- Statut
    status          VARCHAR(20) DEFAULT 'active',
    -- Valeurs : 'active', 'inactive', 'suspended'

    -- Contact
    contact_email   VARCHAR(255) NULL,
    contact_name    VARCHAR(100) NULL,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_partners_slug ON partners(slug);
CREATE INDEX idx_partners_api_key ON partners(api_key);
CREATE INDEX idx_partners_status ON partners(status) WHERE status = 'active';

-- Contrainte sur data_access
ALTER TABLE partners ADD CONSTRAINT chk_partner_data_access
    CHECK (data_access IN ('summary', 'full', 'custom'));
```

**Niveaux d'accès aux données :**

| Niveau | Données exposées | Cas d'usage |
|--------|------------------|-------------|
| `summary` | Résumé, pré-devis JSON, pièces jointes, infos client | Partenaires externes (EBP, Batigest) |
| `full` | Tout + conversation complète + métadonnées | ZOOMBAT (interne) |
| `custom` | Champs spécifiés dans `data_fields` | Besoins particuliers |

**Exemples de configuration :**

```sql
-- ZOOMBAT : accès complet (logiciel interne)
INSERT INTO partners (name, slug, api_key, api_key_prefix, data_access, commission_rate) VALUES
('ZOOMBAT', 'zoombat', 'zb_a1b2c3d4e5f6...', 'zb_', 'full', 5.00);

-- EBP : résumé seulement
INSERT INTO partners (name, slug, api_key, api_key_prefix, data_access, commission_rate) VALUES
('EBP Bâtiment', 'ebp', 'ebp_x9y8z7w6v5...', 'ebp_', 'summary', 5.00);

-- Partenaire custom
INSERT INTO partners (name, slug, api_key, api_key_prefix, data_access, data_fields) VALUES
('Autre Logiciel', 'autre', 'alt_...', 'alt_', 'custom', '["summary", "quote", "conversation"]');
```

---

### Webhooks

#### Table : `webhooks`

```sql
CREATE TABLE webhooks (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT REFERENCES tenants(id) ON DELETE CASCADE,

    -- Configuration
    name            VARCHAR(255) NOT NULL,
    url             VARCHAR(2048) NOT NULL,
    secret          VARCHAR(255) NOT NULL,  -- Pour signature HMAC

    -- Événements
    events          JSONB NOT NULL DEFAULT '[]',
    -- Valeurs : ["product.created", "product.updated", "order.created", ...]

    -- Options
    is_active       BOOLEAN DEFAULT TRUE,
    retry_count     INTEGER DEFAULT 3,
    timeout_seconds INTEGER DEFAULT 30,

    -- Statistiques
    last_triggered_at TIMESTAMP NULL,
    success_count   INTEGER DEFAULT 0,
    failure_count   INTEGER DEFAULT 0,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_webhooks_tenant ON webhooks(tenant_id);
CREATE INDEX idx_webhooks_active ON webhooks(is_active) WHERE is_active = TRUE;
```

#### Table : `webhook_logs`

```sql
CREATE TABLE webhook_logs (
    id              BIGSERIAL PRIMARY KEY,
    webhook_id      BIGINT NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,

    -- Requête
    event           VARCHAR(100) NOT NULL,
    payload         JSONB NOT NULL,

    -- Réponse
    status_code     INTEGER NULL,
    response_body   TEXT NULL,
    response_time_ms INTEGER NULL,

    -- Résultat
    status          VARCHAR(20) NOT NULL,
    -- Valeurs : 'success', 'failed', 'pending'

    error_message   TEXT NULL,
    attempt_number  INTEGER DEFAULT 1,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_webhook_logs_webhook ON webhook_logs(webhook_id);
CREATE INDEX idx_webhook_logs_status ON webhook_logs(status);
CREATE INDEX idx_webhook_logs_created ON webhook_logs(created_at DESC);

-- Partitionnement par date pour les logs volumineux
-- (À activer en production si nécessaire)
```

---

### Audit

#### Table : `audit_logs`

```sql
CREATE TABLE audit_logs (
    id              BIGSERIAL PRIMARY KEY,

    -- Acteur
    user_id         BIGINT REFERENCES users(id) ON DELETE SET NULL,
    user_email      VARCHAR(255) NULL,  -- Copie pour historique
    ip_address      INET NULL,
    user_agent      TEXT NULL,

    -- Action
    action          VARCHAR(50) NOT NULL,
    -- Valeurs : 'create', 'update', 'delete', 'login', 'logout', 'export', etc.

    -- Cible
    auditable_type  VARCHAR(100) NOT NULL,  -- Nom du modèle
    auditable_id    BIGINT NULL,

    -- Données
    old_values      JSONB NULL,
    new_values      JSONB NULL,

    -- Contexte
    tags            VARCHAR(255)[] DEFAULT '{}',

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_audit_logs_user ON audit_logs(user_id);
CREATE INDEX idx_audit_logs_auditable ON audit_logs(auditable_type, auditable_id);
CREATE INDEX idx_audit_logs_action ON audit_logs(action);
CREATE INDEX idx_audit_logs_created ON audit_logs(created_at DESC);

-- Partitionnement recommandé en production
```

---

## Collections Qdrant

### Vue d'Ensemble

```
┌─────────────────────────────────────────────────────────────┐
│                    QDRANT COLLECTIONS                        │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌─────────────────────────────────────────────────────┐    │
│  │  agent_btp_ouvrages                                  │    │
│  │  - Embeddings des ouvrages BTP                       │    │
│  │  - Payload: db_id, type, category, content           │    │
│  │  - Mode: SQL_HYDRATION                               │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                              │
│  ┌─────────────────────────────────────────────────────┐    │
│  │  agent_support_docs                                  │    │
│  │  - Embeddings de la documentation support            │    │
│  │  - Payload: title, content, source                   │    │
│  │  - Mode: TEXT_ONLY                                   │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                              │
│  ┌─────────────────────────────────────────────────────┐    │
│  │  agent_litige_jurisprudence                          │    │
│  │  - Embeddings des cas juridiques                     │    │
│  │  - Payload: db_id, case_type, date, content          │    │
│  │  - Mode: SQL_HYDRATION                               │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                              │
│  ┌─────────────────────────────────────────────────────┐    │
│  │  learned_responses                                   │    │
│  │  - Couples Question/Réponse validés                  │    │
│  │  - Payload: agent_id, question, answer, message_id   │    │
│  │  - Enrichissement continu via feedback humain        │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### Configuration des Collections

#### Collection : `agent_btp_ouvrages`

```json
{
    "name": "agent_btp_ouvrages",
    "vectors": {
        "size": 768,
        "distance": "Cosine",
        "on_disk": false
    },
    "optimizers_config": {
        "memmap_threshold": 20000,
        "indexing_threshold": 10000
    },
    "replication_factor": 1,
    "write_consistency_factor": 1
}
```

**Structure d'un Point :**

```json
{
    "id": "ouvrage_12345",
    "vector": [0.123, -0.456, ...],  // 768 dimensions
    "payload": {
        "db_id": 12345,
        "type": "compose",
        "code": "CLO-PLA-001",
        "category": "Cloisons",
        "subcategory": "Plaques de plâtre",
        "content": "Cloison en plaques de plâtre BA13 sur ossature métallique. Cette cloison inclut: 2 rails R48 au sol et plafond, 4 montants M48 espacés de 60cm, isolation laine de verre 45mm, 2 plaques BA13 de chaque côté. Épaisseur totale: 98mm. Affaiblissement acoustique: 39dB.",
        "unit": "m²",
        "unit_price": 45.50,
        "tenant_id": 1,
        "indexed_at": "2025-12-22T10:30:00Z"
    }
}
```

#### Collection : `agent_support_docs`

```json
{
    "name": "agent_support_docs",
    "vectors": {
        "size": 768,
        "distance": "Cosine"
    }
}
```

**Structure d'un Point :**

```json
{
    "id": "doc_faq_001",
    "vector": [0.789, -0.012, ...],
    "payload": {
        "title": "Comment créer un devis ?",
        "content": "Pour créer un devis, accédez au menu Devis > Nouveau devis. Sélectionnez le client, ajoutez les ouvrages souhaités depuis la bibliothèque, ajustez les quantités et validez. Le devis sera automatiquement numéroté.",
        "source": "documentation",
        "category": "devis",
        "url": "/docs/devis/creation",
        "tenant_id": 1
    }
}
```

#### Collection : `learned_responses`

```json
{
    "name": "learned_responses",
    "vectors": {
        "size": 768,
        "distance": "Cosine"
    },
    "hnsw_config": {
        "m": 16,
        "ef_construct": 100
    }
}
```

**Structure d'un Point (réponse apprise) :**

```json
{
    "id": "learned_msg_98765",
    "vector": [0.345, 0.678, ...],  // Embedding de la question
    "payload": {
        "agent_id": 1,
        "agent_slug": "expert-btp",
        "message_id": 98765,
        "question": "Quelle est l'épaisseur d'une cloison BA13 double peau ?",
        "answer": "Une cloison BA13 double peau standard a une épaisseur totale de 98mm, composée de : 2 plaques BA13 de 13mm de chaque côté (52mm total) et une ossature métallique M48 de 48mm. Cette configuration offre un affaiblissement acoustique d'environ 39dB.",
        "validated_by": 5,
        "validated_at": "2025-12-22T14:30:00Z",
        "tenant_id": 1
    }
}
```

---

## Migrations Laravel

### Ordre d'Exécution

```
1. 2025_01_01_000001_create_tenants_table.php
2. 2025_01_01_000002_create_users_table.php
3. 2025_01_01_000003_create_roles_permissions_tables.php
4. 2025_01_01_000004_create_api_tokens_table.php
5. 2025_01_01_000010_create_agents_table.php
6. 2025_01_01_000011_create_system_prompt_versions_table.php
7. 2025_01_01_000020_create_ai_sessions_table.php
8. 2025_01_01_000021_create_ai_messages_table.php
9. 2025_01_01_000022_create_ai_feedbacks_table.php
10. 2025_01_01_000030_create_ouvrages_table.php
11. 2025_01_01_000031_create_ouvrage_components_table.php
12. 2025_01_01_000040_create_dynamic_tables_table.php
13. 2025_01_01_000041_create_import_logs_table.php
14. 2025_01_01_000050_create_webhooks_table.php
15. 2025_01_01_000051_create_webhook_logs_table.php
16. 2025_01_01_000060_create_audit_logs_table.php
```

### Exemple de Migration

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();

            // Identification
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->string('icon', 50)->default('robot');
            $table->string('color', 7)->default('#3B82F6');

            // Configuration IA
            $table->text('system_prompt');

            // Configuration Qdrant
            $table->string('qdrant_collection', 100);

            // Mode de récupération
            $table->string('retrieval_mode', 20)->default('TEXT_ONLY');
            $table->jsonb('hydration_config')->nullable();

            // Configuration Ollama (override)
            $table->string('ollama_host')->nullable();
            $table->integer('ollama_port')->nullable();
            $table->string('model', 100)->nullable();
            $table->string('fallback_model', 100)->nullable();

            // Paramètres de contexte
            $table->integer('context_window_size')->default(10);
            $table->integer('max_tokens')->default(2048);
            $table->decimal('temperature', 3, 2)->default(0.7);

            // Statut
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Index
            $table->index('slug');
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
```

---

## Commandes Artisan

```bash
# Initialisation des collections Qdrant
php artisan qdrant:init

# Initialisation avec données de test
php artisan qdrant:init --with-test-data

# Indexation des ouvrages dans Qdrant
php artisan ouvrages:index --chunk=100

# Réindexation complète d'un agent
php artisan agent:reindex {slug}

# Création d'une table dynamique
php artisan dynamic-table:create {name} --schema=schema.json

# Import de données
php artisan import:csv {file} --table={table} --mapping=mapping.json
php artisan import:json {file} --table={table}

# Purge des logs anciens
php artisan logs:purge --days=90

# Statistiques des collections
php artisan qdrant:stats
```

---

## Seeders (Données Initiales)

Les seeders s'exécutent automatiquement au premier démarrage via l'entrypoint Docker.
Ils créent les données nécessaires pour que l'application soit fonctionnelle immédiatement.

### Ordre d'Exécution

```
1. TenantSeeder           → Tenant par défaut
2. RolePermissionSeeder   → Rôles et permissions
3. UserSeeder             → Utilisateur admin
4. AgentSeeder            → Agents IA de test (TEXT_ONLY + SQL_HYDRATION)
5. OuvrageSeeder          → Ouvrages BTP de test
6. SupportDocSeeder       → Documents support de test
```

### Fichier : `database/seeders/DatabaseSeeder.php`

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TenantSeeder::class,
            RolePermissionSeeder::class,
            UserSeeder::class,
            AgentSeeder::class,
            OuvrageSeeder::class,
            SupportDocSeeder::class,
        ]);
    }
}
```

---

### Seeder : `TenantSeeder`

```php
<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::firstOrCreate(
            ['slug' => 'default'],
            [
                'name' => 'AI-Manager CMS',
                'domain' => 'localhost',
                'settings' => [
                    'theme' => 'light',
                    'locale' => 'fr',
                ],
                'is_active' => true,
            ]
        );
    }
}
```

---

### Seeder : `RolePermissionSeeder`

```php
<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Création des permissions
        $permissions = [
            // Agents
            ['name' => 'Voir les agents', 'slug' => 'agents.view', 'group_name' => 'agents'],
            ['name' => 'Créer un agent', 'slug' => 'agents.create', 'group_name' => 'agents'],
            ['name' => 'Modifier un agent', 'slug' => 'agents.update', 'group_name' => 'agents'],
            ['name' => 'Supprimer un agent', 'slug' => 'agents.delete', 'group_name' => 'agents'],

            // Sessions IA
            ['name' => 'Voir les sessions', 'slug' => 'ai-sessions.view', 'group_name' => 'ai'],
            ['name' => 'Valider les réponses', 'slug' => 'ai-sessions.validate', 'group_name' => 'ai'],
            ['name' => 'Déclencher l\'apprentissage', 'slug' => 'ai-sessions.learn', 'group_name' => 'ai'],

            // Ouvrages
            ['name' => 'Voir les ouvrages', 'slug' => 'ouvrages.view', 'group_name' => 'ouvrages'],
            ['name' => 'Créer un ouvrage', 'slug' => 'ouvrages.create', 'group_name' => 'ouvrages'],
            ['name' => 'Modifier un ouvrage', 'slug' => 'ouvrages.update', 'group_name' => 'ouvrages'],
            ['name' => 'Supprimer un ouvrage', 'slug' => 'ouvrages.delete', 'group_name' => 'ouvrages'],
            ['name' => 'Importer des ouvrages', 'slug' => 'ouvrages.import', 'group_name' => 'ouvrages'],
            ['name' => 'Indexer dans Qdrant', 'slug' => 'ouvrages.index', 'group_name' => 'ouvrages'],

            // Utilisateurs
            ['name' => 'Gérer les utilisateurs', 'slug' => 'users.manage', 'group_name' => 'users'],
            ['name' => 'Gérer les rôles', 'slug' => 'roles.manage', 'group_name' => 'users'],

            // API
            ['name' => 'Accès API', 'slug' => 'api.access', 'group_name' => 'api'],
            ['name' => 'Gérer les webhooks', 'slug' => 'webhooks.manage', 'group_name' => 'api'],
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['slug' => $perm['slug']], $perm);
        }

        // Création des rôles
        $roles = [
            [
                'name' => 'Super Admin',
                'slug' => 'super-admin',
                'description' => 'Accès complet au système',
                'is_system' => true,
                'permissions' => ['*'], // Toutes les permissions
            ],
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'Administration des agents et utilisateurs',
                'is_system' => true,
                'permissions' => [
                    'agents.*', 'ai-sessions.*', 'ouvrages.*', 'users.manage',
                ],
            ],
            [
                'name' => 'Validateur',
                'slug' => 'validator',
                'description' => 'Validation des réponses IA',
                'is_system' => true,
                'permissions' => [
                    'agents.view', 'ai-sessions.view', 'ai-sessions.validate', 'ai-sessions.learn',
                ],
            ],
            [
                'name' => 'Utilisateur',
                'slug' => 'user',
                'description' => 'Utilisation des agents IA',
                'is_system' => true,
                'permissions' => [
                    'agents.view', 'ai-sessions.view',
                ],
            ],
            [
                'name' => 'API Client',
                'slug' => 'api-client',
                'description' => 'Accès API uniquement (marque blanche)',
                'is_system' => true,
                'permissions' => [
                    'api.access',
                ],
            ],
        ];

        foreach ($roles as $roleData) {
            $permissions = $roleData['permissions'];
            unset($roleData['permissions']);

            $role = Role::firstOrCreate(['slug' => $roleData['slug']], $roleData);

            // Attacher les permissions
            if ($permissions === ['*']) {
                $role->permissions()->sync(Permission::pluck('id'));
            } else {
                $permissionIds = Permission::whereIn('slug', $permissions)
                    ->orWhere(function ($query) use ($permissions) {
                        foreach ($permissions as $perm) {
                            if (str_ends_with($perm, '.*')) {
                                $group = str_replace('.*', '', $perm);
                                $query->orWhere('group_name', $group);
                            }
                        }
                    })
                    ->pluck('id');
                $role->permissions()->sync($permissionIds);
            }
        }
    }
}
```

---

### Seeder : `UserSeeder`

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'default')->first();
        $superAdminRole = Role::where('slug', 'super-admin')->first();
        $validatorRole = Role::where('slug', 'validator')->first();

        // Utilisateur Super Admin
        $admin = User::firstOrCreate(
            ['email' => 'admin@ai-manager.local'],
            [
                'name' => 'Administrateur',
                'password' => Hash::make('password'),
                'tenant_id' => $tenant?->id,
                'email_verified_at' => now(),
            ]
        );
        $admin->roles()->syncWithoutDetaching([$superAdminRole->id]);

        // Utilisateur Validateur (pour tester l'apprentissage)
        $validator = User::firstOrCreate(
            ['email' => 'validateur@ai-manager.local'],
            [
                'name' => 'Validateur IA',
                'password' => Hash::make('password'),
                'tenant_id' => $tenant?->id,
                'email_verified_at' => now(),
            ]
        );
        $validator->roles()->syncWithoutDetaching([$validatorRole->id]);

        $this->command->info('👤 Utilisateurs créés:');
        $this->command->info('   - admin@ai-manager.local / password (Super Admin)');
        $this->command->info('   - validateur@ai-manager.local / password (Validateur)');
    }
}
```

---

### Seeder : `AgentSeeder` (Agents de Test)

```php
<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class AgentSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'default')->first();

        // =====================================================
        // AGENT 1: Expert BTP (Mode SQL_HYDRATION)
        // Pour tester l'hydratation SQL avec les ouvrages
        // =====================================================
        Agent::firstOrCreate(
            ['slug' => 'expert-btp'],
            [
                'tenant_id' => $tenant?->id,
                'name' => 'Expert BTP',
                'description' => 'Agent spécialisé dans les ouvrages et prix du bâtiment. Utilise le mode SQL_HYDRATION pour enrichir les réponses avec les données des ouvrages.',
                'icon' => 'building-office',
                'color' => '#F59E0B',

                'system_prompt' => <<<'PROMPT'
Tu es un expert en bâtiment et travaux publics (BTP). Tu aides les professionnels à :
- Trouver des informations sur les ouvrages (cloisons, plafonds, menuiseries, etc.)
- Comprendre les prix unitaires et la composition des ouvrages
- Conseiller sur les choix techniques

RÈGLES IMPORTANTES :
1. Base toujours tes réponses sur les données fournies dans le contexte
2. Si tu ne trouves pas l'information, dis-le clairement
3. Donne des prix indicatifs en précisant qu'ils peuvent varier
4. Utilise un vocabulaire technique mais accessible

FORMAT DE RÉPONSE :
- Commence par répondre directement à la question
- Cite les références des ouvrages concernés
- Donne des détails techniques si pertinent
PROMPT,

                'qdrant_collection' => 'agent_btp_ouvrages',
                'retrieval_mode' => 'SQL_HYDRATION',
                'hydration_config' => [
                    'table' => 'ouvrages',
                    'key' => 'db_id',
                    'fields' => ['*'],
                    'relations' => ['children'],
                ],

                'model' => null, // Utilise le modèle par défaut
                'context_window_size' => 10,
                'max_tokens' => 2048,
                'temperature' => 0.7,
                'is_active' => true,
            ]
        );

        // =====================================================
        // AGENT 2: Support Client (Mode TEXT_ONLY)
        // Pour tester le mode texte simple sans hydratation
        // =====================================================
        Agent::firstOrCreate(
            ['slug' => 'support-client'],
            [
                'tenant_id' => $tenant?->id,
                'name' => 'Support Client',
                'description' => 'Agent de support technique pour répondre aux questions fréquentes. Utilise le mode TEXT_ONLY avec des documents pré-formatés.',
                'icon' => 'chat-bubble-left-right',
                'color' => '#3B82F6',

                'system_prompt' => <<<'PROMPT'
Tu es un assistant de support client pour une application de devis/facturation BTP.
Tu aides les utilisateurs à :
- Comprendre comment utiliser l'application
- Résoudre les problèmes techniques courants
- Trouver les bonnes fonctionnalités

RÈGLES IMPORTANTES :
1. Sois amical et patient
2. Donne des instructions étape par étape
3. Si tu ne connais pas la réponse, propose de contacter le support humain
4. Utilise un langage simple et clair

FORMAT DE RÉPONSE :
- Réponds de manière concise
- Utilise des listes numérotées pour les étapes
- Propose des actions concrètes
PROMPT,

                'qdrant_collection' => 'agent_support_docs',
                'retrieval_mode' => 'TEXT_ONLY',
                'hydration_config' => null,

                'model' => null,
                'context_window_size' => 8,
                'max_tokens' => 1024,
                'temperature' => 0.5,
                'is_active' => true,
            ]
        );

        $this->command->info('🤖 Agents IA créés:');
        $this->command->info('   - expert-btp (SQL_HYDRATION) → Ouvrages BTP');
        $this->command->info('   - support-client (TEXT_ONLY) → FAQ Support');
    }
}
```

---

### Seeder : `OuvrageSeeder` (Données BTP de Test)

```php
<?php

namespace Database\Seeders;

use App\Models\Ouvrage;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class OuvrageSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'default')->first();

        $ouvrages = [
            // =====================================================
            // CLOISONS
            // =====================================================
            [
                'code' => 'CLO-BA13-001',
                'name' => 'Cloison BA13 simple peau sur ossature 48mm',
                'description' => 'Cloison en plaques de plâtre BA13 simple peau. Ossature métallique 48mm avec montants espacés de 60cm. Épaisseur totale 61mm.',
                'type' => 'simple',
                'category' => 'Cloisons',
                'subcategory' => 'Plaques de plâtre',
                'unit' => 'm²',
                'unit_price' => 28.50,
                'technical_specs' => [
                    'epaisseur_totale' => '61mm',
                    'ossature' => 'M48',
                    'entraxe' => '60cm',
                    'nb_plaques' => 1,
                    'affaiblissement_acoustique' => '34dB',
                ],
            ],
            [
                'code' => 'CLO-BA13-002',
                'name' => 'Cloison BA13 double peau sur ossature 48mm',
                'description' => 'Cloison en plaques de plâtre BA13 double peau. Ossature métallique 48mm. 2 plaques de chaque côté. Épaisseur totale 98mm. Excellent affaiblissement acoustique.',
                'type' => 'simple',
                'category' => 'Cloisons',
                'subcategory' => 'Plaques de plâtre',
                'unit' => 'm²',
                'unit_price' => 45.00,
                'technical_specs' => [
                    'epaisseur_totale' => '98mm',
                    'ossature' => 'M48',
                    'entraxe' => '60cm',
                    'nb_plaques' => 2,
                    'affaiblissement_acoustique' => '42dB',
                ],
            ],
            [
                'code' => 'CLO-BA13-003',
                'name' => 'Cloison BA13 hydrofuge pour pièces humides',
                'description' => 'Cloison en plaques de plâtre hydrofuges (vertes) pour salles de bains et cuisines. Ossature 48mm. Simple peau.',
                'type' => 'simple',
                'category' => 'Cloisons',
                'subcategory' => 'Plaques de plâtre',
                'unit' => 'm²',
                'unit_price' => 35.00,
                'technical_specs' => [
                    'epaisseur_totale' => '61mm',
                    'ossature' => 'M48',
                    'type_plaque' => 'Hydrofuge H1',
                    'usage' => 'Pièces humides',
                ],
            ],

            // =====================================================
            // PLAFONDS
            // =====================================================
            [
                'code' => 'PLF-SUSP-001',
                'name' => 'Plafond suspendu BA13 sur ossature primaire/secondaire',
                'description' => 'Plafond suspendu en plaques BA13. Ossature métallique avec fourrures et suspentes. Plénum standard 20cm.',
                'type' => 'simple',
                'category' => 'Plafonds',
                'subcategory' => 'Suspendus',
                'unit' => 'm²',
                'unit_price' => 42.00,
                'technical_specs' => [
                    'plenum' => '20cm',
                    'ossature' => 'F530 + suspentes',
                    'entraxe_fourrures' => '50cm',
                    'entraxe_suspentes' => '120cm',
                ],
            ],
            [
                'code' => 'PLF-SUSP-002',
                'name' => 'Plafond suspendu acoustique avec laine minérale',
                'description' => 'Plafond suspendu BA13 avec isolation acoustique en laine de roche 60mm. Performances acoustiques renforcées.',
                'type' => 'compose',
                'category' => 'Plafonds',
                'subcategory' => 'Suspendus',
                'unit' => 'm²',
                'unit_price' => 58.00,
                'technical_specs' => [
                    'plenum' => '25cm',
                    'isolation' => 'Laine de roche 60mm',
                    'affaiblissement_acoustique' => '45dB',
                ],
            ],

            // =====================================================
            // MENUISERIES
            // =====================================================
            [
                'code' => 'MEN-PORTE-001',
                'name' => 'Bloc-porte âme alvéolaire 83x204cm',
                'description' => 'Bloc-porte intérieur standard. Huisserie métallique, porte âme alvéolaire. Serrure bec-de-cane.',
                'type' => 'simple',
                'category' => 'Menuiseries',
                'subcategory' => 'Portes intérieures',
                'unit' => 'U',
                'unit_price' => 185.00,
                'technical_specs' => [
                    'dimensions' => '83x204cm',
                    'huisserie' => 'Métallique',
                    'ame' => 'Alvéolaire',
                    'serrure' => 'Bec-de-cane',
                ],
            ],
            [
                'code' => 'MEN-PORTE-002',
                'name' => 'Bloc-porte acoustique 38dB',
                'description' => 'Bloc-porte acoustique haute performance. Huisserie bois, joint périphérique, seuil automatique.',
                'type' => 'simple',
                'category' => 'Menuiseries',
                'subcategory' => 'Portes intérieures',
                'unit' => 'U',
                'unit_price' => 450.00,
                'technical_specs' => [
                    'dimensions' => '83x204cm',
                    'affaiblissement_acoustique' => '38dB',
                    'huisserie' => 'Bois',
                    'seuil' => 'Automatique',
                ],
            ],

            // =====================================================
            // ISOLATION
            // =====================================================
            [
                'code' => 'ISO-LDV-001',
                'name' => 'Isolation laine de verre 100mm R=2.50',
                'description' => 'Panneau de laine de verre semi-rigide pour isolation des murs et cloisons. Résistance thermique R=2.50.',
                'type' => 'simple',
                'category' => 'Isolation',
                'subcategory' => 'Thermique',
                'unit' => 'm²',
                'unit_price' => 12.50,
                'technical_specs' => [
                    'epaisseur' => '100mm',
                    'resistance_thermique' => 'R=2.50',
                    'lambda' => '0.040',
                    'conditionnement' => 'Rouleau',
                ],
            ],
            [
                'code' => 'ISO-LDR-001',
                'name' => 'Isolation laine de roche 60mm acoustique',
                'description' => 'Panneau de laine de roche pour isolation acoustique. Idéal pour cloisons et plafonds.',
                'type' => 'simple',
                'category' => 'Isolation',
                'subcategory' => 'Acoustique',
                'unit' => 'm²',
                'unit_price' => 15.00,
                'technical_specs' => [
                    'epaisseur' => '60mm',
                    'densite' => '40kg/m³',
                    'usage' => 'Acoustique',
                ],
            ],

            // =====================================================
            // PEINTURE
            // =====================================================
            [
                'code' => 'PEI-MAT-001',
                'name' => 'Peinture acrylique mate blanche - 2 couches',
                'description' => 'Application de peinture acrylique mate blanche en 2 couches sur murs et plafonds. Impression comprise.',
                'type' => 'simple',
                'category' => 'Peinture',
                'subcategory' => 'Murs et plafonds',
                'unit' => 'm²',
                'unit_price' => 14.00,
                'technical_specs' => [
                    'type' => 'Acrylique mat',
                    'nb_couches' => 2,
                    'impression' => 'Incluse',
                    'rendement' => '10m²/L',
                ],
            ],
        ];

        foreach ($ouvrages as $data) {
            Ouvrage::firstOrCreate(
                ['code' => $data['code']],
                array_merge($data, [
                    'tenant_id' => $tenant?->id,
                    'is_indexed' => false,
                ])
            );
        }

        $this->command->info('🏗️ ' . count($ouvrages) . ' ouvrages BTP créés');
    }
}
```

---

### Seeder : `SupportDocSeeder` (Documents FAQ)

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupportDocSeeder extends Seeder
{
    /**
     * Ces documents sont directement insérés dans Qdrant par la commande qdrant:init.
     * Ce seeder crée une table temporaire pour stocker les docs avant indexation.
     */
    public function run(): void
    {
        $docs = $this->getSupportDocuments();

        // Stocker dans une table support_docs si elle existe
        // Sinon, ces docs seront utilisés directement par QdrantInitCommand
        if (config('database.seed_support_docs_to_db', false)) {
            foreach ($docs as $doc) {
                DB::table('support_docs')->updateOrInsert(
                    ['slug' => $doc['slug']],
                    $doc
                );
            }
        }

        // Stocker dans un fichier JSON pour la commande qdrant:init
        $path = storage_path('app/seed-data/support-docs.json');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode($docs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->command->info('📚 ' . count($docs) . ' documents support préparés pour indexation');
    }

    private function getSupportDocuments(): array
    {
        return [
            [
                'slug' => 'creer-devis',
                'title' => 'Comment créer un devis ?',
                'content' => "Pour créer un nouveau devis, suivez ces étapes :\n\n1. Cliquez sur le menu 'Devis' dans la barre latérale\n2. Cliquez sur le bouton 'Nouveau devis'\n3. Sélectionnez ou créez un client\n4. Ajoutez les ouvrages depuis la bibliothèque en utilisant la recherche\n5. Ajustez les quantités pour chaque ligne\n6. Vérifiez le total et les remises éventuelles\n7. Cliquez sur 'Enregistrer' ou 'Envoyer au client'\n\nLe devis sera automatiquement numéroté selon votre paramétrage.",
                'category' => 'devis',
            ],
            [
                'slug' => 'modifier-devis',
                'title' => 'Comment modifier un devis existant ?',
                'content' => "Pour modifier un devis existant :\n\n1. Allez dans 'Devis' > 'Liste des devis'\n2. Recherchez le devis par numéro ou client\n3. Cliquez sur le devis pour l'ouvrir\n4. Cliquez sur 'Modifier'\n5. Effectuez vos modifications\n6. Enregistrez\n\nNote : Un devis déjà accepté ne peut plus être modifié. Vous devez créer un avenant.",
                'category' => 'devis',
            ],
            [
                'slug' => 'transformer-devis-facture',
                'title' => 'Comment transformer un devis en facture ?',
                'content' => "Une fois le devis accepté par le client, vous pouvez le transformer en facture :\n\n1. Ouvrez le devis accepté\n2. Cliquez sur 'Actions' > 'Transformer en facture'\n3. Choisissez si vous facturez la totalité ou une partie (situation)\n4. Vérifiez les informations\n5. Validez la création de la facture\n\nLa facture sera liée au devis d'origine pour la traçabilité.",
                'category' => 'facturation',
            ],
            [
                'slug' => 'ajouter-ouvrage-bibliotheque',
                'title' => 'Comment ajouter un ouvrage à la bibliothèque ?',
                'content' => "Pour enrichir votre bibliothèque d'ouvrages :\n\n1. Allez dans 'Bibliothèque' > 'Ouvrages'\n2. Cliquez sur 'Nouvel ouvrage'\n3. Renseignez :\n   - Code de l'ouvrage\n   - Désignation\n   - Unité (m², ml, U, etc.)\n   - Prix unitaire HT\n   - Description technique (optionnel)\n4. Choisissez la catégorie\n5. Enregistrez\n\nL'ouvrage sera disponible dans tous vos devis.",
                'category' => 'bibliotheque',
            ],
            [
                'slug' => 'importer-ouvrages',
                'title' => 'Comment importer des ouvrages depuis un fichier ?',
                'content' => "Pour importer en masse des ouvrages :\n\n1. Préparez votre fichier Excel ou CSV avec les colonnes : Code, Nom, Unité, Prix\n2. Allez dans 'Bibliothèque' > 'Import'\n3. Téléchargez le modèle de fichier si besoin\n4. Sélectionnez votre fichier\n5. Mappez les colonnes si nécessaire\n6. Lancez l'import\n\nUn rapport d'import vous indiquera les succès et erreurs éventuelles.",
                'category' => 'bibliotheque',
            ],
            [
                'slug' => 'gerer-clients',
                'title' => 'Comment gérer les fiches clients ?',
                'content' => "Pour gérer vos clients :\n\n1. Menu 'Clients' > 'Liste des clients'\n2. Pour ajouter : cliquez sur 'Nouveau client'\n3. Renseignez les informations :\n   - Raison sociale ou nom\n   - Adresse complète\n   - Email et téléphone\n   - SIRET (si professionnel)\n4. Enregistrez\n\nVous pouvez voir l'historique des devis et factures depuis la fiche client.",
                'category' => 'clients',
            ],
            [
                'slug' => 'exporter-comptabilite',
                'title' => 'Comment exporter les données pour la comptabilité ?',
                'content' => "Pour exporter vos écritures comptables :\n\n1. Allez dans 'Paramètres' > 'Exports comptables'\n2. Sélectionnez la période (mois, trimestre, année)\n3. Choisissez le format d'export selon votre logiciel :\n   - FEC (Fichier des Écritures Comptables)\n   - CSV standard\n   - Format spécifique (Sage, EBP, etc.)\n4. Cliquez sur 'Exporter'\n\nLe fichier sera téléchargé automatiquement.",
                'category' => 'comptabilite',
            ],
            [
                'slug' => 'probleme-connexion',
                'title' => 'Je n\'arrive pas à me connecter',
                'content' => "Si vous rencontrez des difficultés de connexion :\n\n1. Vérifiez votre adresse email (attention aux fautes de frappe)\n2. Cliquez sur 'Mot de passe oublié' pour réinitialiser\n3. Vérifiez que les majuscules ne sont pas activées\n4. Videz le cache de votre navigateur\n5. Essayez un autre navigateur (Chrome, Firefox, Edge)\n\nSi le problème persiste, contactez le support avec :\n- Votre adresse email\n- Une capture d'écran de l'erreur\n- Le navigateur utilisé",
                'category' => 'technique',
            ],
            [
                'slug' => 'personnaliser-modele-pdf',
                'title' => 'Comment personnaliser les modèles PDF ?',
                'content' => "Pour personnaliser vos documents PDF (devis, factures) :\n\n1. Allez dans 'Paramètres' > 'Modèles de documents'\n2. Sélectionnez le type de document à personnaliser\n3. Vous pouvez modifier :\n   - Le logo (formats PNG, JPG)\n   - Les couleurs de l'entête\n   - Les mentions légales\n   - Le pied de page\n   - La mise en page des lignes\n4. Prévisualisez avant d'enregistrer\n\nLes modifications s'appliqueront aux nouveaux documents.",
                'category' => 'parametrage',
            ],
            [
                'slug' => 'situation-travaux',
                'title' => 'Comment faire une situation de travaux ?',
                'content' => "Pour créer une situation de travaux (facturation partielle) :\n\n1. Ouvrez le devis concerné\n2. Cliquez sur 'Actions' > 'Nouvelle situation'\n3. Pour chaque ligne, indiquez le pourcentage ou montant réalisé\n4. Le système calcule automatiquement :\n   - Le montant de la situation\n   - Le cumul des situations précédentes\n   - Le reste à facturer\n5. Validez pour créer la facture de situation\n\nVous pouvez faire autant de situations que nécessaire jusqu'à atteindre 100%.",
                'category' => 'facturation',
            ],
        ];
    }
}
```

---

## Récapitulatif des Données de Test

Après le démarrage, l'application contient :

### Utilisateurs

| Email | Mot de passe | Rôle |
|-------|--------------|------|
| admin@ai-manager.local | password | Super Admin |
| validateur@ai-manager.local | password | Validateur |

### Agents IA

| Slug | Mode | Collection Qdrant | Usage |
|------|------|-------------------|-------|
| expert-btp | SQL_HYDRATION | agent_btp_ouvrages | Test hydratation avec ouvrages |
| support-client | TEXT_ONLY | agent_support_docs | Test mode texte avec FAQ |

### Ouvrages BTP

10 ouvrages de test répartis en catégories :
- Cloisons (3) : BA13 simple, double, hydrofuge
- Plafonds (2) : Suspendu standard, acoustique
- Menuiseries (2) : Porte standard, acoustique
- Isolation (2) : Laine de verre, laine de roche
- Peinture (1) : Acrylique mate

### Documents Support

10 articles FAQ couvrant :
- Création et modification de devis
- Transformation devis → facture
- Gestion de la bibliothèque
- Gestion des clients
- Export comptable
- Résolution de problèmes

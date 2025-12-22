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

    -- Métadonnées RAG (pour les réponses assistant)
    rag_context     JSONB NULL,
    -- Structure : {
    --   "sources": [{"id": "...", "score": 0.85, "content": "..."}],
    --   "hydrated_data": {...},
    --   "retrieval_mode": "SQL_HYDRATION"
    -- }

    -- Métadonnées de génération
    model_used      VARCHAR(100) NULL,
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

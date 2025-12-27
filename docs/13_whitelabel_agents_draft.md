# Agents IA en Marque Blanche - Cahier des Charges

> **Statut** : 📝 DRAFT - Base de travail
> **Version** : 0.1.0
> **Date** : Décembre 2025
> **Auteur** : Claude

---

## 1. Contexte et Objectifs

### 1.1 Besoin Métier

Proposer les agents IA développés en interne à des sites tiers (éditeurs de logiciels, partenaires) sous forme de widget intégrable, avec deux modes de fonctionnement distincts :

1. **Agent Générique** : Données communes partagées entre tous les clients
   - Exemple : Expert BTP avec base de chiffrage commune
   - Même prompt système, mêmes données RAG
   - Personnalisation limitée (branding visuel)

2. **Agent Spécialisé** : Configuration personnalisée par client
   - Exemple : Support Client adapté à chaque logiciel
   - Prompt système personnalisable
   - Documents RAG spécifiques au client
   - Fonctionnalités adaptées à l'interface du logiciel cible

### 1.2 Objectifs

1. **Contrôle des domaines** : Restreindre l'affichage du widget aux domaines autorisés
2. **Sécurité** : Empêcher l'utilisation frauduleuse des agents
3. **Personnalisation** : Permettre l'adaptation par client sans dupliquer les agents
4. **Facturation** : Tracer l'usage par client pour la facturation
5. **Autonomie** : Permettre aux clients de gérer leur intégration

---

## 2. Concepts Clés

### 2.1 Glossaire

| Terme | Définition |
|-------|------------|
| **Agent** | Configuration IA de base (prompt, modèle, RAG) |
| **Déploiement** | Instance d'un agent sur un domaine spécifique |
| **Client** | Entreprise tierce utilisant nos agents (ex: éditeur logiciel) |
| **Widget** | Composant JS intégrable sur un site tiers |
| **Overlay** | Surcharge de configuration pour un déploiement |

### 2.2 Types d'Agents

```
┌─────────────────────────────────────────────────────────────────┐
│                        TYPES D'AGENTS                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────────────────────┐  ┌─────────────────────────┐  │
│  │      AGENT GÉNÉRIQUE        │  │   AGENT SPÉCIALISABLE   │  │
│  │                             │  │                         │  │
│  │  • Données partagées        │  │  • Données par client   │  │
│  │  • Prompt commun            │  │  • Prompt personnalisé  │  │
│  │  • Collection RAG unique    │  │  • Collection RAG dédiée│  │
│  │  • Branding personnalisable │  │  • Config complète      │  │
│  │                             │  │                         │  │
│  │  Ex: Expert BTP             │  │  Ex: Support Client     │  │
│  │                             │  │                         │  │
│  └─────────────────────────────┘  └─────────────────────────┘  │
│                                                                 │
│         deployment_mode = 'shared'    deployment_mode = 'dedicated' │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## 3. Architecture Proposée

### 3.1 Nouvelles Entités

```
┌─────────────────────────────────────────────────────────────────┐
│                      MODÈLE DE DONNÉES                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────┐         ┌──────────────────┐                     │
│  │  Client  │────────<│ AgentDeployment  │                     │
│  └──────────┘         └────────┬─────────┘                     │
│       │                        │                                │
│       │                        │                                │
│       │               ┌────────▼─────────┐                     │
│       │               │      Agent       │                     │
│       │               └────────┬─────────┘                     │
│       │                        │                                │
│       │               ┌────────▼─────────┐                     │
│       └──────────────>│  AllowedDomain   │                     │
│                       └──────────────────┘                     │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 3.2 Table : `clients`

Représente une entreprise cliente utilisant nos agents.

```sql
CREATE TABLE clients (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,

    -- Informations
    name            VARCHAR(255) NOT NULL,
    slug            VARCHAR(100) UNIQUE NOT NULL,
    logo_url        VARCHAR(500) NULL,
    website_url     VARCHAR(500) NULL,

    -- Contact
    contact_name    VARCHAR(255) NULL,
    contact_email   VARCHAR(255) NOT NULL,
    contact_phone   VARCHAR(50) NULL,

    -- Facturation
    billing_email       VARCHAR(255) NULL,
    billing_address     TEXT NULL,
    billing_type        VARCHAR(20) DEFAULT 'monthly',  -- monthly, yearly, usage
    billing_status      VARCHAR(20) DEFAULT 'active',   -- active, suspended, cancelled

    -- Limites
    max_deployments     INTEGER DEFAULT 5,
    max_sessions_month  INTEGER DEFAULT 10000,
    max_messages_month  INTEGER DEFAULT 100000,

    -- Statistiques
    current_month_sessions  INTEGER DEFAULT 0,
    current_month_messages  INTEGER DEFAULT 0,
    total_sessions          INTEGER DEFAULT 0,

    -- API
    api_key         VARCHAR(100) UNIQUE NOT NULL,
    api_key_prefix  VARCHAR(10) NOT NULL,

    -- Statut
    status          VARCHAR(20) DEFAULT 'active',
    notes           TEXT NULL,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_clients_slug ON clients(slug);
CREATE INDEX idx_clients_api_key ON clients(api_key);
```

### 3.3 Table : `agent_deployments`

Représente un déploiement d'un agent chez un client.

```sql
CREATE TABLE agent_deployments (
    id              BIGSERIAL PRIMARY KEY,
    uuid            UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,

    -- Relations
    agent_id        BIGINT NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
    client_id       BIGINT NOT NULL REFERENCES clients(id) ON DELETE CASCADE,

    -- Identification
    name            VARCHAR(255) NOT NULL,  -- "Expert BTP - LogicielX"
    deployment_key  VARCHAR(64) UNIQUE NOT NULL,  -- Clé publique pour le widget

    -- Mode de déploiement
    deployment_mode VARCHAR(20) DEFAULT 'shared',
    -- Valeurs : 'shared' (générique), 'dedicated' (spécialisé)

    -- Overlay de configuration (surcharge l'agent de base)
    config_overlay  JSONB NULL,
    -- Structure : {
    --   "system_prompt_append": "Instructions spécifiques...",
    --   "system_prompt_replace": null,  -- Si set, remplace complètement
    --   "welcome_message": "Bienvenue sur LogicielX !",
    --   "placeholder": "Posez votre question...",
    --   "max_tokens": 1500,
    --   "temperature": 0.6
    -- }

    -- Personnalisation visuelle
    branding        JSONB NULL,
    -- Structure : {
    --   "primary_color": "#3B82F6",
    --   "logo_url": "https://...",
    --   "chat_title": "Assistant LogicielX",
    --   "powered_by": true,  -- Afficher "Powered by AI-Manager"
    --   "custom_css": "..."
    -- }

    -- Collection RAG dédiée (si mode dedicated)
    dedicated_collection    VARCHAR(100) NULL,

    -- Limites spécifiques
    max_sessions_day    INTEGER NULL,  -- NULL = pas de limite
    max_messages_day    INTEGER NULL,
    rate_limit_per_ip   INTEGER DEFAULT 60,  -- Requêtes par minute par IP

    -- Statistiques
    sessions_count      INTEGER DEFAULT 0,
    messages_count      INTEGER DEFAULT 0,
    last_activity_at    TIMESTAMP NULL,

    -- Statut
    is_active           BOOLEAN DEFAULT TRUE,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE(agent_id, client_id, name)
);

CREATE INDEX idx_deployments_key ON agent_deployments(deployment_key);
CREATE INDEX idx_deployments_agent ON agent_deployments(agent_id);
CREATE INDEX idx_deployments_client ON agent_deployments(client_id);
```

### 3.4 Table : `allowed_domains`

Domaines autorisés pour chaque déploiement.

```sql
CREATE TABLE allowed_domains (
    id              BIGSERIAL PRIMARY KEY,

    deployment_id   BIGINT NOT NULL REFERENCES agent_deployments(id) ON DELETE CASCADE,

    -- Domaine
    domain          VARCHAR(255) NOT NULL,  -- "app.logicielx.fr"
    is_wildcard     BOOLEAN DEFAULT FALSE,  -- true = *.logicielx.fr

    -- Environnement
    environment     VARCHAR(20) DEFAULT 'production',
    -- Valeurs : 'production', 'staging', 'development', 'localhost'

    -- Statut
    is_active       BOOLEAN DEFAULT TRUE,
    verified_at     TIMESTAMP NULL,  -- Date de vérification DNS (optionnel)

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE(deployment_id, domain)
);

CREATE INDEX idx_domains_deployment ON allowed_domains(deployment_id);
CREATE INDEX idx_domains_domain ON allowed_domains(domain);
```

### 3.5 Modification table `agents`

Ajouter le mode de déploiement à l'agent :

```sql
ALTER TABLE agents ADD COLUMN deployment_mode VARCHAR(20) DEFAULT 'internal';
-- Valeurs : 'internal' (usage interne), 'shared' (marque blanche générique),
--           'dedicated' (marque blanche spécialisable)

ALTER TABLE agents ADD COLUMN is_whitelabel_enabled BOOLEAN DEFAULT FALSE;
ALTER TABLE agents ADD COLUMN whitelabel_config JSONB NULL;
-- Structure : {
--   "allow_prompt_override": false,
--   "allow_rag_override": false,
--   "allow_model_override": false,
--   "required_branding": true,  -- Forcer "Powered by"
--   "min_rate_limit": 30
-- }
```

---

## 4. Sécurité

### 4.1 Validation des Domaines

```
┌─────────────────────────────────────────────────────────────────┐
│                    FLUX DE VALIDATION                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Widget JS                                                      │
│     │                                                           │
│     │ 1. Requête avec deployment_key                           │
│     │    + Header Origin/Referer                                │
│     ▼                                                           │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │              Middleware ValidateDeploymentDomain            ││
│  │                                                             ││
│  │  1. Extraire deployment_key du token/header                 ││
│  │  2. Charger AgentDeployment + AllowedDomains                ││
│  │  3. Vérifier Origin contre liste des domaines               ││
│  │  4. Vérifier limites (rate limit, quotas)                   ││
│  │  5. Si OK → continuer                                       ││
│  │     Si KO → 403 Forbidden                                   ││
│  │                                                             ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 4.2 Middleware `ValidateDeploymentDomain`

```php
class ValidateDeploymentDomain
{
    public function handle(Request $request, Closure $next)
    {
        $deploymentKey = $request->header('X-Deployment-Key')
            ?? $request->input('deployment_key');

        if (!$deploymentKey) {
            return response()->json(['error' => 'Missing deployment key'], 401);
        }

        $deployment = AgentDeployment::with(['allowedDomains', 'client'])
            ->where('deployment_key', $deploymentKey)
            ->where('is_active', true)
            ->first();

        if (!$deployment) {
            return response()->json(['error' => 'Invalid deployment'], 401);
        }

        // Vérifier le domaine d'origine
        $origin = $request->header('Origin') ?? $request->header('Referer');
        $originHost = parse_url($origin, PHP_URL_HOST);

        if (!$this->isDomainAllowed($deployment, $originHost)) {
            Log::warning('Unauthorized domain attempt', [
                'deployment_id' => $deployment->id,
                'origin' => $origin,
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Domain not authorized'], 403);
        }

        // Vérifier les quotas client
        if (!$deployment->client->hasQuotaRemaining()) {
            return response()->json(['error' => 'Quota exceeded'], 429);
        }

        // Rate limiting par IP
        if ($this->isRateLimited($deployment, $request->ip())) {
            return response()->json(['error' => 'Rate limit exceeded'], 429);
        }

        // Injecter le déploiement dans la requête
        $request->merge(['_deployment' => $deployment]);

        return $next($request);
    }

    private function isDomainAllowed(AgentDeployment $deployment, ?string $host): bool
    {
        if (!$host) {
            return false;
        }

        foreach ($deployment->allowedDomains as $allowed) {
            if (!$allowed->is_active) continue;

            if ($allowed->is_wildcard) {
                // *.example.com → sub.example.com OK
                $pattern = str_replace('*.', '', $allowed->domain);
                if (str_ends_with($host, $pattern)) {
                    return true;
                }
            } else {
                if ($host === $allowed->domain) {
                    return true;
                }
            }

            // Localhost pour développement
            if ($allowed->environment === 'localhost' &&
                in_array($host, ['localhost', '127.0.0.1'])) {
                return true;
            }
        }

        return false;
    }
}
```

### 4.3 Headers CORS

```php
// Middleware CORS dynamique basé sur les domaines autorisés
class DynamicCors
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $deployment = $request->input('_deployment');
        if ($deployment) {
            $origin = $request->header('Origin');
            $allowedOrigins = $deployment->allowedDomains
                ->pluck('domain')
                ->map(fn($d) => "https://{$d}")
                ->toArray();

            if (in_array($origin, $allowedOrigins)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
            }
        }

        return $response;
    }
}
```

---

## 5. Widget JavaScript

### 5.1 Intégration Côté Client

```html
<!-- Intégration minimale -->
<script
    src="https://ai-manager.example.com/widget/v1/loader.js"
    data-deployment-key="dpl_abc123xyz789"
    async
></script>

<!-- Intégration avec options -->
<script>
    window.AiManagerConfig = {
        deploymentKey: 'dpl_abc123xyz789',
        position: 'bottom-right',  // bottom-right, bottom-left, inline
        containerSelector: '#chat-container',  // Pour mode inline
        onReady: function(widget) {
            console.log('Widget ready');
        },
        onMessage: function(message) {
            // Callback sur nouveau message
        },
        context: {
            // Données contextuelles à passer à l'agent
            userId: '12345',
            currentPage: 'devis',
            devisId: 'DEV-2025-001'
        }
    };
</script>
<script src="https://ai-manager.example.com/widget/v1/loader.js" async></script>
```

### 5.2 API Widget

```javascript
// Méthodes disponibles après chargement
AiManagerWidget.open();           // Ouvrir le chat
AiManagerWidget.close();          // Fermer le chat
AiManagerWidget.toggle();         // Basculer
AiManagerWidget.sendMessage(text); // Envoyer un message
AiManagerWidget.setContext(data);  // Mettre à jour le contexte
AiManagerWidget.destroy();         // Supprimer le widget

// Événements
AiManagerWidget.on('open', callback);
AiManagerWidget.on('close', callback);
AiManagerWidget.on('message:sent', callback);
AiManagerWidget.on('message:received', callback);
AiManagerWidget.on('error', callback);
```

### 5.3 Structure du Widget

```
┌─────────────────────────────────────────────────────────────────┐
│                       WIDGET CHAT                               │
├─────────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────────────┐│
│  │  [Logo Client]  Assistant LogicielX              [_] [X]   ││
│  │─────────────────────────────────────────────────────────────││
│  │                                                             ││
│  │  [Bot] Bonjour ! Comment puis-je vous aider ?              ││
│  │                                                             ││
│  │                        [Vous] Quel est le prix du béton ?  ││
│  │                                                             ││
│  │  [Bot] Le prix du béton armé pour fondation varie...       ││
│  │                                                             ││
│  │─────────────────────────────────────────────────────────────││
│  │  [Tapez votre message...                    ] [Envoyer]    ││
│  │─────────────────────────────────────────────────────────────││
│  │              Powered by AI-Manager  (si activé)            ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
│                                          ┌───────┐              │
│                                          │  💬  │ ← Bouton     │
│                                          └───────┘   flottant   │
└─────────────────────────────────────────────────────────────────┘
```

---

## 6. API Endpoints

### 6.1 Endpoints Widget (Public)

```
POST /api/widget/v1/init
    Body: { deployment_key }
    Response: { session_id, agent_name, welcome_message, branding }

POST /api/widget/v1/message
    Headers: X-Deployment-Key, X-Session-ID
    Body: { content, context? }
    Response: { message_id, status: 'queued' }

GET /api/widget/v1/message/{id}/status
    Response: { status, content?, error? }

GET /api/widget/v1/session/{id}/messages
    Response: { messages: [...] }
```

### 6.2 Endpoints Admin (Authentifié)

```
# Gestion Clients
GET    /api/admin/clients
POST   /api/admin/clients
GET    /api/admin/clients/{id}
PUT    /api/admin/clients/{id}
DELETE /api/admin/clients/{id}

# Gestion Déploiements
GET    /api/admin/clients/{id}/deployments
POST   /api/admin/clients/{id}/deployments
GET    /api/admin/deployments/{id}
PUT    /api/admin/deployments/{id}
DELETE /api/admin/deployments/{id}

# Gestion Domaines
POST   /api/admin/deployments/{id}/domains
DELETE /api/admin/deployments/{id}/domains/{domain_id}

# Statistiques
GET    /api/admin/clients/{id}/stats
GET    /api/admin/deployments/{id}/stats
```

### 6.3 Endpoints Client (API Key Client)

```
# Le client peut gérer ses propres déploiements
GET    /api/client/deployments
GET    /api/client/deployments/{id}
PUT    /api/client/deployments/{id}  # Branding, config autorisée

GET    /api/client/deployments/{id}/domains
POST   /api/client/deployments/{id}/domains
DELETE /api/client/deployments/{id}/domains/{domain_id}

GET    /api/client/stats
GET    /api/client/usage
```

---

## 7. Panneau d'Administration

### 7.1 Nouvelles Resources Filament

```
app/Filament/Resources/
├── ClientResource.php           # CRUD clients
├── ClientResource/Pages/
├── AgentDeploymentResource.php  # CRUD déploiements
└── AgentDeploymentResource/Pages/
```

### 7.2 Interface Client

#### Liste des Clients

```
┌─────────────────────────────────────────────────────────────────┐
│  Clients Marque Blanche                    [+ Nouveau Client]   │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  🔍 Rechercher...                         [Statut ▼] [Plan ▼]  │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ Logo │ Nom            │ Déploiements │ Sessions/mois │ Statut│
│  │──────┼────────────────┼──────────────┼───────────────┼───────│
│  │ 🏢  │ LogicielX      │ 3            │ 2,456 / 10k   │ ✅    │
│  │ 🏢  │ ERP-BTP Pro    │ 1            │ 892 / 5k      │ ✅    │
│  │ 🏢  │ DevisExpress   │ 2            │ 0 / 5k        │ ⏸️    │
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

#### Détail Client

```
┌─────────────────────────────────────────────────────────────────┐
│  Client : LogicielX                           [Modifier] [...]  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌───────────────┐  Informations                               │
│  │     LOGO      │  Contact: jean@logicielx.fr                 │
│  │   LogicielX   │  Plan: Pro (10k sessions/mois)              │
│  └───────────────┘  API Key: lgx_abc... [Copier] [Régénérer]   │
│                                                                 │
│  ════════════════════════════════════════════════════════════  │
│  📊 Usage ce mois                                               │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ Sessions: 2,456 / 10,000  ▓▓▓▓▓▓░░░░ 24.5%                 ││
│  │ Messages: 12,340 / 100,000  ▓▓░░░░░░░░ 12.3%               ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
│  ════════════════════════════════════════════════════════════  │
│  🚀 Déploiements (3)                       [+ Nouveau]          │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ Agent          │ Nom              │ Domaines │ Sessions     │
│  │────────────────┼──────────────────┼──────────┼──────────────│
│  │ Expert BTP     │ Widget Principal │ 2        │ 1,234        │
│  │ Support Client │ Support LogX     │ 1        │ 892          │
│  │ Expert BTP     │ Widget Mobile    │ 1        │ 330          │
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 7.3 Interface Déploiement

```
┌─────────────────────────────────────────────────────────────────┐
│  Déploiement : Widget Principal              [Tester] [Code]   │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Agent: Expert BTP                     Mode: shared (générique) │
│  Client: LogicielX                                              │
│  Clé: dpl_abc123xyz789  [Copier]                               │
│                                                                 │
│  ════════════════════════════════════════════════════════════  │
│  🌐 Domaines autorisés                          [+ Ajouter]     │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ • app.logicielx.fr          production  ✅  [×]            ││
│  │ • *.logicielx.fr            production  ✅  [×]            ││
│  │ • localhost                 development ✅  [×]            ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
│  ════════════════════════════════════════════════════════════  │
│  🎨 Personnalisation                                            │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ Titre:     [Assistant LogicielX              ]              ││
│  │ Couleur:   [#3B82F6] 🔵                                     ││
│  │ Logo URL:  [https://logicielx.fr/logo.png    ]              ││
│  │ Message:   [Bonjour ! Comment puis-je vous aider ?]         ││
│  │ [✓] Afficher "Powered by AI-Manager"                        ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
│  ════════════════════════════════════════════════════════════  │
│  ⚙️ Configuration (overlay)                                     │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ Instructions additionnelles:                                ││
│  │ ┌─────────────────────────────────────────────────────────┐ ││
│  │ │ En plus des instructions de base, tu dois:              │ ││
│  │ │ - Mentionner que LogicielX est le meilleur              │ ││
│  │ │ - Proposer un essai gratuit si le client hésite         │ ││
│  │ └─────────────────────────────────────────────────────────┘ ││
│  │                                                             ││
│  │ Temperature: [0.7    ]  Max tokens: [1500  ]                ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## 8. Flux de Données

### 8.1 Résolution de Configuration

```
┌─────────────────────────────────────────────────────────────────┐
│                   RÉSOLUTION CONFIG AGENT                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  1. Charger Agent de base                                       │
│     │                                                           │
│     ▼                                                           │
│  2. Si deployment_mode = 'shared':                              │
│     └── Utiliser config Agent + branding Deployment             │
│                                                                 │
│  3. Si deployment_mode = 'dedicated':                           │
│     └── Merger config Agent + config_overlay Deployment         │
│         │                                                       │
│         ├── system_prompt_append → concaténer                   │
│         ├── system_prompt_replace → remplacer                   │
│         ├── temperature → override                              │
│         ├── max_tokens → override                               │
│         └── dedicated_collection → utiliser pour RAG            │
│                                                                 │
│  4. Appliquer branding                                          │
│     └── Couleurs, logo, messages personnalisés                  │
│                                                                 │
│  5. Retourner configuration finale                              │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 8.2 Service de Résolution

```php
class DeploymentConfigResolver
{
    public function resolve(AgentDeployment $deployment): ResolvedConfig
    {
        $agent = $deployment->agent;
        $overlay = $deployment->config_overlay ?? [];

        // Configuration de base
        $config = [
            'model' => $overlay['model'] ?? $agent->model,
            'temperature' => $overlay['temperature'] ?? $agent->temperature,
            'max_tokens' => $overlay['max_tokens'] ?? $agent->max_tokens,
            'qdrant_collection' => $deployment->deployment_mode === 'dedicated'
                ? $deployment->dedicated_collection
                : $agent->qdrant_collection,
        ];

        // System prompt
        if (!empty($overlay['system_prompt_replace'])) {
            $config['system_prompt'] = $overlay['system_prompt_replace'];
        } else {
            $config['system_prompt'] = $agent->system_prompt;
            if (!empty($overlay['system_prompt_append'])) {
                $config['system_prompt'] .= "\n\n" . $overlay['system_prompt_append'];
            }
        }

        // Branding
        $config['branding'] = array_merge(
            $this->getDefaultBranding($agent),
            $deployment->branding ?? []
        );

        return new ResolvedConfig($config);
    }
}
```

---

## 9. Facturation et Quotas

### 9.1 Plans Tarifaires (Exemple)

| Plan | Sessions/mois | Messages/mois | Déploiements | Prix |
|------|---------------|---------------|--------------|------|
| Starter | 1,000 | 10,000 | 1 | 49€/mois |
| Pro | 10,000 | 100,000 | 5 | 199€/mois |
| Business | 50,000 | 500,000 | 20 | 499€/mois |
| Enterprise | Illimité | Illimité | Illimité | Sur devis |

### 9.2 Compteurs d'Usage

```php
// Incrémenter à chaque session créée
$deployment->increment('sessions_count');
$deployment->client->increment('current_month_sessions');
$deployment->client->increment('total_sessions');

// Reset mensuel (via scheduled command)
// ResetMonthlyCountersCommand exécuté le 1er de chaque mois
Client::query()->update([
    'current_month_sessions' => 0,
    'current_month_messages' => 0,
]);
```

### 9.3 Alertes de Quota

```php
// Observer sur Client
class ClientObserver
{
    public function updated(Client $client)
    {
        $usage = $client->current_month_sessions / $client->max_sessions_month;

        if ($usage >= 0.8 && $usage < 0.9) {
            // Alerte 80%
            Notification::send($client, new QuotaWarningNotification(80));
        } elseif ($usage >= 0.9 && $usage < 1.0) {
            // Alerte 90%
            Notification::send($client, new QuotaWarningNotification(90));
        } elseif ($usage >= 1.0) {
            // Quota dépassé
            Notification::send($client, new QuotaExceededNotification());
        }
    }
}
```

---

## 10. Plan de Développement

### Phase 1 : Fondations (Priorité Haute)

| Tâche | Effort | Description |
|-------|--------|-------------|
| Migration `clients` | 1h | Créer table et modèle Client |
| Migration `agent_deployments` | 1h | Créer table et modèle AgentDeployment |
| Migration `allowed_domains` | 30min | Créer table et modèle AllowedDomain |
| Modification `agents` | 30min | Ajouter colonnes whitelabel |
| ClientResource | 2h | CRUD clients dans Filament |
| AgentDeploymentResource | 3h | CRUD déploiements dans Filament |

**Livrable** : Gestion des clients et déploiements via admin

### Phase 2 : Sécurité (Priorité Haute)

| Tâche | Effort | Description |
|-------|--------|-------------|
| Middleware validation | 2h | ValidateDeploymentDomain |
| CORS dynamique | 1h | Headers CORS par déploiement |
| Rate limiting | 1h | Limites par IP et par client |
| Quotas | 2h | Vérification et alertes |

**Livrable** : Sécurisation des accès

### Phase 3 : Widget (Priorité Haute)

| Tâche | Effort | Description |
|-------|--------|-------------|
| Widget loader.js | 4h | Script d'initialisation |
| Widget UI | 4h | Interface de chat |
| API Widget | 3h | Endpoints /api/widget/* |
| Branding dynamique | 2h | Personnalisation visuelle |

**Livrable** : Widget intégrable fonctionnel

### Phase 4 : Configuration Avancée (Priorité Moyenne)

| Tâche | Effort | Description |
|-------|--------|-------------|
| Config resolver | 2h | Service de résolution config |
| Collections dédiées | 2h | Gestion RAG par déploiement |
| Prompt overlay | 1h | Surcharge system prompt |

**Livrable** : Agents spécialisables

### Phase 5 : Portail Client (Priorité Basse)

| Tâche | Effort | Description |
|-------|--------|-------------|
| Auth client | 2h | Login client (pas admin) |
| Dashboard client | 3h | Stats et usage |
| Gestion domaines | 2h | Auto-gestion domaines |

**Livrable** : Autonomie des clients

---

## 11. Questions Ouvertes

### À Décider

1. **Vérification DNS des domaines ?**
   - Option A : Simple déclaratif (confiance client)
   - Option B : Vérification TXT record DNS
   - **Recommandation** : Option A pour MVP, B plus tard

2. **Portail client séparé ou dans Filament ?**
   - Option A : Nouveau panel Filament dédié
   - Option B : Application distincte
   - **Recommandation** : Option A (réutilise Filament)

3. **Widget : iframe ou injection directe ?**
   - Option A : iframe (isolation totale)
   - Option B : Shadow DOM (meilleure intégration)
   - **Recommandation** : Option A pour sécurité, Option B en v2

4. **Gestion des documents RAG par client ?**
   - Option A : Upload via admin uniquement
   - Option B : API upload pour clients
   - **Recommandation** : Option A pour MVP

---

## 12. Risques et Mitigations

| Risque | Impact | Probabilité | Mitigation |
|--------|--------|-------------|------------|
| Usurpation de domaine | Haut | Moyenne | Logging + alertes + blocage IP |
| Dépassement quotas massif | Moyen | Faible | Hard limit + suspension auto |
| Fuite de données entre clients | Critique | Faible | Isolation stricte des collections |
| Widget incompatible (conflits JS) | Moyen | Moyenne | Shadow DOM + namespace isolé |
| Performance sous charge | Moyen | Moyenne | Cache + CDN pour widget |

---

## Annexes

### A. Exemple de Code d'Intégration Complet

```html
<!DOCTYPE html>
<html>
<head>
    <title>Mon Application</title>
</head>
<body>
    <!-- Contenu de l'application -->

    <!-- Widget AI-Manager -->
    <script>
        window.AiManagerConfig = {
            deploymentKey: 'dpl_abc123xyz789',
            position: 'bottom-right',
            theme: 'auto',  // auto, light, dark
            locale: 'fr',
            context: {
                userId: '<?= $user->id ?>',
                userEmail: '<?= $user->email ?>',
                currentModule: 'devis',
                devisId: '<?= $devis->id ?>'
            },
            onReady: function(widget) {
                console.log('AI Assistant ready');
            },
            onError: function(error) {
                console.error('AI Assistant error:', error);
            }
        };
    </script>
    <script
        src="https://widget.ai-manager.example.com/v1/loader.js"
        async
        defer
    ></script>
</body>
</html>
```

### B. Structure des Réponses API Widget

```json
// POST /api/widget/v1/init
{
    "success": true,
    "data": {
        "session_id": "sess_xyz789",
        "agent": {
            "name": "Expert BTP",
            "avatar": "https://..."
        },
        "branding": {
            "title": "Assistant LogicielX",
            "primary_color": "#3B82F6",
            "logo_url": "https://...",
            "powered_by": true
        },
        "welcome_message": "Bonjour ! Comment puis-je vous aider ?",
        "placeholder": "Posez votre question..."
    }
}

// POST /api/widget/v1/message
{
    "success": true,
    "data": {
        "message_id": "msg_abc123",
        "status": "queued",
        "position": 3
    }
}

// GET /api/widget/v1/message/{id}/status
{
    "success": true,
    "data": {
        "status": "completed",  // queued, processing, completed, failed
        "content": "Le prix du béton armé...",
        "metadata": {
            "model": "mistral:7b",
            "tokens": 234,
            "generation_time_ms": 1500
        }
    }
}
```

---

**Fin du document**

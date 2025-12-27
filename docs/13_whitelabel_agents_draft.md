# Agents IA en Marque Blanche - Cahier des Charges

> **Statut** : 📝 DRAFT - Base de travail
> **Version** : 0.1.0
> **Date** : Décembre 2025
> **Auteur** : Rodolphe

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

## 11. Décisions Techniques

### Validées

1. **Vérification DNS des domaines ?**
   - ✅ **Option A : Simple déclaratif (confiance client)**
   - ~~Option B : Vérification TXT record DNS~~
   - Mitigations : Logging détaillé, alertes si doublon, validation manuelle Enterprise

2. **Portail client séparé ou dans Filament ?**
   - ✅ **Option A : Nouveau panel Filament dédié**
   - ~~Option B : Application distincte~~
   - Avantages : Mutualisation des améliorations (ex: gestion RAG), maintenance unique, cohérence technique

3. **Widget : iframe ou injection directe ?**
   - ✅ **Option A : iframe (isolation totale) + API/Webhook**
   - ~~Option B : Shadow DOM (meilleure intégration)~~
   - Justification : Compatibilité domaines sensibles (comptabilité, bancaire, RH)
   - Architecture :
     - Contexte sensible via API serveur-à-serveur (jamais en JS navigateur)
     - Widget iframe isolé (Same-Origin Policy)
     - Résultats via Webhook signé vers serveur client

4. **Gestion des documents RAG par client ?**
   - ✅ **Option hybride : Admin (tous) + Client (ses deployments)**
   - Admin : Accès à tous les RAG, gestion des docs communs (master)
   - Client : Accès limité à ses agents via portail Filament + API upload pour clients techniques
   - Documents uploadés par client → collection dédiée du deployment

5. **Architecture Agent partagé entre clients ?**
   - ✅ **Option hybride : Master + Deployment**
   - Un seul Agent Master à maintenir (prompt, modèle)
   - N collections dédiées (1 par deployment client)
   - Docs communs dans collection master, docs spécifiques dans collection client
   - Pas de duplication d'agent, pas de filtrage tenant_id → isolation native Qdrant

6. **Répartition de charge Qdrant ?**
   - ✅ **Non nécessaire : Single node Qdrant suffit**
   - Justification :
     - Qdrant traite 1,000-5,000 req/sec, Ollama est le bottleneck (99.9% du temps de traitement)
     - Isolation native par collection (pas besoin de filtrage tenant_id)
     - 1M vecteurs = ~3 GB RAM, confortable sur un node 32GB
   - Évolution si croissance :
     - < 10M vecteurs : Single node
     - 10-50M vecteurs : Augmenter RAM (64-128GB)
     - > 50M vecteurs : Qdrant cluster mode (réplication native)
   - Pas d'override `qdrant_host` par deployment (contrairement à Ollama)

---

## 12. Architecture Master / Deployment

### 12.1 Principe

```
Agent Master (Expert BTP)          AgentDeployment (LogicielX)
┌─────────────────────────┐        ┌─────────────────────────┐
│ system_prompt (base)    │───────►│ config_overlay:         │
│ model: mistral:7b       │        │   prompt_append: "..."  │
│ temperature: 0.7        │        │   temperature: 0.6      │
│ collection: btp_common  │        │ dedicated_collection:   │
│                         │        │   btp_logicielx         │
└─────────────────────────┘        └─────────────────────────┘
                                              │
                                              ▼
                                   Config Résolue à l'exécution
                                   • prompt = base + append
                                   • collections = [common, dedicated]
```

### 12.2 Répartition Master vs Deployment

| Composant | Champ(s) | Master | Deployment | Notes |
|-----------|----------|:------:|:----------:|-------|
| **IDENTITÉ** | | | | |
| Nom affiché | `name` | ✅ | 🔄 Override | Marque blanche |
| Slug | `slug` | ✅ | ❌ | Identifiant technique interne |
| Description | `description` | ✅ | 🔄 Override | |
| Branding | `icon`, `color` | ✅ | 🔄 Override | Via config branding |
| **PROMPT** | | | | |
| System Prompt | `system_prompt` | ✅ | 🔄 3 modes | Inherit / Append / Replace |
| **CONFIG IA** | | | | |
| Modèle LLM | `model` | ✅ | 🔄 Override | |
| Fallback Model | `fallback_model` | ✅ | 🔄 Override | |
| Température | `temperature` | ✅ | 🔄 Override | |
| Max Tokens | `max_tokens` | ✅ | 🔄 Override | |
| Context Window | `context_window_size` | ✅ | 🔄 Override | |
| **INFRA** | | | | |
| Ollama Host | `ollama_host` | ✅ | 🔄 Override | Répartition charge |
| Ollama Port | `ollama_port` | ✅ | 🔄 Override | |
| **RAG** | | | | |
| Collection Master | `qdrant_collection` | ✅ | Read-only | Docs partagés |
| Collection Dédiée | `dedicated_collection` | ❌ | ✅ Own | Docs client |
| **DOCUMENTS** | | | | |
| Docs Communs | via collection master | ✅ | Read-only | Admin upload |
| Docs Client | via collection dédiée | ❌ | ✅ Own | Client upload |
| **APPRENTISSAGE** | | | | |
| Learned Master | `learned_responses` | ✅ | Read-only | Bénéficie à tous |
| Learned Client | `learned_responses` | ❌ | ✅ Own | Spécifique, promotable |
| **SESSIONS** | | | | |
| Sessions | `ai_sessions` | ❌ | ✅ Own | Isolées par deployment |
| Messages | `ai_messages` | ❌ | ✅ Own | Via sessions |
| **LIMITES** | | | | |
| Minimums système | rate_limit, temp... | ✅ Impose | ❌ | Non négociable |
| Quotas client | sessions/mois... | ❌ | ✅ | Selon plan |
| Quotas deployment | sessions/jour... | ❌ | ✅ | Répartition interne |

**Légende** : ✅ Géré | ❌ Pas géré | 🔄 Override possible | ✅ Own = Propre et isolé

### 12.3 Modes de personnalisation Prompt

| Mode | Usage | Résultat |
|------|-------|----------|
| **Inherit** | Agent générique sans modif | `prompt = master.system_prompt` |
| **Append** | Ajout d'instructions spécifiques | `prompt = master + "\n\n" + overlay.append` |
| **Replace** | Agent 100% personnalisé | `prompt = overlay.replace` |

### 12.4 Promotion Learned Responses

```
Correction sur Deployment LogicielX
         │
         ▼
Sauvegarde locale (deployment_id = dpl_xxx)
         │
         ▼
Admin voit la correction
         │
         ▼
Clique "Promouvoir vers Master"
         │
         ▼
Anonymisation (suppression références client)
         │
         ▼
Création dans Master (deployment_id = NULL)
         │
         ▼
Tous les deployments en bénéficient
```

### 12.5 Système de Limites (3 niveaux)

```
┌─────────────────────────────────────────────────────────────────┐
│ NIVEAU 1 : Master (minimums imposés)                            │
│ • min_rate_limit_per_ip: 30 req/min                            │
│ • temperature: 0.1 - 1.5                                        │
│ • max_context_window: 20                                        │
├─────────────────────────────────────────────────────────────────┤
│ NIVEAU 2 : Client (plan souscrit)                               │
│ • max_sessions_month: 10,000                                    │
│ • max_messages_month: 100,000                                   │
│ • max_deployments: 5                                            │
│ • max_documents_storage: 5 GB                                   │
├─────────────────────────────────────────────────────────────────┤
│ NIVEAU 3 : Deployment (répartition interne)                     │
│ • max_sessions_day: 500                                         │
│ • max_messages_day: 5,000                                       │
│ • rate_limit_per_ip: 60                                         │
└─────────────────────────────────────────────────────────────────┘
```

---

## 13. Risques et Mitigations

| Risque | Impact | Probabilité | Mitigation |
|--------|--------|-------------|------------|
| Usurpation de domaine | Haut | Moyenne | Logging + alertes + blocage IP |
| Dépassement quotas massif | Moyen | Faible | Hard limit + suspension auto |
| Fuite de données entre clients | Critique | Faible | Isolation stricte des collections Qdrant |
| Performance Ollama sous charge | Moyen | Moyenne | Override ollama_host par deployment |
| Prompt injection via client | Haut | Faible | Validation + sanitization des overlays |

---

## 14. Annexes

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

## 15. Cas d'Usage Concret : Parcours Artisan-Client

> ⚠️ **NÉCESSITÉ ABSOLUE** : Ce cas d'usage représente le parcours métier principal de la solution.
> Tous les éléments listés ci-dessous DOIVENT être implémentés pour que le produit soit viable commercialement.
> Sans ces fonctionnalités, le déploiement whitelabel ne couvre pas le besoin réel des clients.

### 15.1 Scénario : Expert BTP déployé chez EBP

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        PARCOURS COMPLET ARTISAN-CLIENT                       │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ACTEURS :                                                                  │
│  • EBP = Éditeur logiciel (Client whitelabel)                              │
│  • Durant Peinture = Artisan (utilisateur EBP)                             │
│  • M. Martin = Client final de l'artisan                                    │
│  • Expert BTP = Agent IA déployé                                           │
│                                                                             │
│  ════════════════════════════════════════════════════════════════════════  │
│                                                                             │
│  1. INITIATION                                                              │
│     ┌─────────────┐                                                        │
│     │   Artisan   │ Crée un lien de session dans EBP                       │
│     │   (EBP)     │ → https://chat.ebp.com/s/abc123?artisan=durant         │
│     └──────┬──────┘                                                        │
│            │ Envoie le lien par email/SMS                                  │
│            ▼                                                                │
│     ┌─────────────┐                                                        │
│     │   Client    │ Clique sur le lien                                     │
│     │  M. Martin  │                                                        │
│     └──────┬──────┘                                                        │
│            │                                                                │
│            ▼                                                                │
│  2. CONVERSATION IA                                                         │
│     ┌─────────────────────────────────────────────────────────────────┐    │
│     │  🤖 "Bonjour, je suis l'assistant de Durant Peinture.          │    │
│     │      Pouvez-vous me décrire votre projet ?"                     │    │
│     │                                                                 │    │
│     │  👤 "Je souhaite refaire ma salle de bain, 8m², douche         │    │
│     │      italienne, carrelage mural et sol"                         │    │
│     │                                                                 │    │
│     │  🤖 "Pouvez-vous m'envoyer quelques photos de l'existant ?"    │    │
│     │                                                                 │    │
│     │  👤 [📷 photo1.jpg] [📷 photo2.jpg]                             │    │
│     │                                                                 │    │
│     │  🤖 "Merci ! Voici un pré-devis estimatif :                    │    │
│     │      - Dépose existant : 450€                                   │    │
│     │      - Plomberie : 1,200€                                       │    │
│     │      - Carrelage sol 8m² : 640€                                 │    │
│     │      - Carrelage mural 20m² : 1,400€                            │    │
│     │      - Douche italienne : 2,100€                                │    │
│     │      Total HT : 5,790€ / TTC : 6,948€                          │    │
│     │                                                                 │    │
│     │      Un devis détaillé vous sera envoyé par Durant Peinture."  │    │
│     └─────────────────────────────────────────────────────────────────┘    │
│            │                                                                │
│            ▼                                                                │
│  3. WEBHOOK VERS EBP                                                        │
│     ┌─────────────────────────────────────────────────────────────────┐    │
│     │  POST https://api.ebp.com/webhooks/ai-manager                   │    │
│     │  {                                                              │    │
│     │    "event": "project_complete",                                 │    │
│     │    "artisan_id": "durant-peinture",                            │    │
│     │    "project": { description, photos[], pre_quote{} },          │    │
│     │    "signature": "hmac_sha256..."                                │    │
│     │  }                                                              │    │
│     └─────────────────────────────────────────────────────────────────┘    │
│            │                                                                │
│            ▼                                                                │
│  4. VALIDATION (2 circuits possibles)                                       │
│                                                                             │
│     Circuit A : Expert EBP disponible                                       │
│     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐               │
│     │  Admin EBP  │────►│ Anonymise   │────►│  Métreur    │               │
│     │  valide     │     │ données     │     │ Expert BTP  │               │
│     └─────────────┘     └─────────────┘     └─────────────┘               │
│                                                                             │
│     Circuit B : Pas d'expert EBP                                            │
│     ┌─────────────┐     ┌─────────────┐                                    │
│     │ Anonymise   │────►│  Métreur    │                                    │
│     │ directement │     │ AI-Manager  │                                    │
│     └─────────────┘     └─────────────┘                                    │
│            │                                                                │
│            ▼                                                                │
│  5. DEVIS SIGNÉ → MARKETPLACE                                               │
│     ┌─────────────┐                      ┌─────────────┐                   │
│     │ Client signe│                      │ AI-Manager  │                   │
│     │ devis (EBP) │─────Webhook─────────►│ Marketplace │                   │
│     └─────────────┘                      │ Matériaux   │                   │
│                                          └─────────────┘                   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 15.2 Éléments Manquants à Implémenter

#### A. Hiérarchie 3 Niveaux : Client → Artisan → Client Final

**Problème** : Le CDC actuel prévoit Client → Deployment, mais le cas réel a 3 niveaux.

**Solution** : Compte utilisateur unique AI-Manager + Associations multi-tenant

L'artisan a UN compte AI-Manager (pour marketplace, accès direct) qui peut être lié à N clients (EBP, SAGE...) avec un branding différent par contexte.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      ARCHITECTURE COMPTE ARTISAN                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  users (compte AI-Manager unique)                                           │
│  ┌───────────────────────────────────────────────────────────────────────┐ │
│  │ id: 1, name: "Durant Peinture", role: "artisan"                       │ │
│  │ branding: { welcome: "Assistant Durant", color: "#E53935" }           │ │
│  │ ↑ Branding par défaut (usage direct AI-Manager / Marketplace)         │ │
│  └───────────────────────────────────────────────────────────────────────┘ │
│           │                                                                 │
│           │ user_tenant_links (associations aux clients)                    │
│           ├────────────────────────────┬────────────────────────────────┐  │
│           ▼                            ▼                                │  │
│  ┌─────────────────────────┐  ┌─────────────────────────┐               │  │
│  │ client: EBP             │  │ client: SAGE            │               │  │
│  │ external_id: "DUR-001"  │  │ external_id: "A-7834"   │               │  │
│  │ branding: {             │  │ branding: {             │               │  │
│  │   welcome: "Assistant   │  │   welcome: "Bienvenue   │               │  │
│  │   EBP - Durant"         │  │   chez Durant"          │               │  │
│  │ }                       │  │ }                       │               │  │
│  └─────────────────────────┘  └─────────────────────────┘               │  │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

**Modification table `users`** :
```sql
-- Ajout colonnes à la table users existante
ALTER TABLE users ADD COLUMN role VARCHAR(50) DEFAULT 'admin';
-- Roles: 'super_admin', 'admin', 'client_admin', 'artisan', 'metreur'

ALTER TABLE users ADD COLUMN branding JSONB NULL;
-- Branding par défaut (usage direct AI-Manager)
-- {
--   "welcome_message": "Bonjour, je suis l'assistant de {user.name}",
--   "primary_color": "#E53935",
--   "logo_url": "https://...",
--   "signature": "L'équipe Durant Peinture"
-- }

ALTER TABLE users ADD COLUMN marketplace_enabled BOOLEAN DEFAULT FALSE;
-- Accès marketplace pour commandes matériaux
```

**Nouvelle table `user_tenant_links`** :
```sql
CREATE TABLE user_tenant_links (
    id              BIGSERIAL PRIMARY KEY,

    -- L'artisan (compte AI-Manager)
    user_id         BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,

    -- Le client (EBP, SAGE, etc.)
    client_id       BIGINT NOT NULL REFERENCES clients(id) ON DELETE CASCADE,

    -- ID de l'artisan dans le système du client
    external_id     VARCHAR(100) NOT NULL,  -- "DUR-001" chez EBP

    -- Branding spécifique pour ce client (override user.branding)
    branding        JSONB NULL,
    -- {
    --   "welcome_message": "Assistant EBP - Durant Peinture",
    --   "primary_color": "#1E88E5",
    --   "logo_url": "https://...",
    --   "signature": "Durant Peinture via EBP"
    -- }

    -- Permissions spécifiques chez ce client
    permissions     JSONB NULL,
    -- {
    --   "can_create_sessions": true,
    --   "can_view_analytics": false,
    --   "max_sessions_month": 100
    -- }

    -- Statut
    is_active       BOOLEAN DEFAULT TRUE,
    linked_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE(user_id, client_id),
    UNIQUE(client_id, external_id)
);

CREATE INDEX idx_tenant_links_user ON user_tenant_links(user_id);
CREATE INDEX idx_tenant_links_client ON user_tenant_links(client_id);
CREATE INDEX idx_tenant_links_external ON user_tenant_links(external_id);
```

**Modification table `ai_sessions`** :
```sql
ALTER TABLE ai_sessions ADD COLUMN user_id BIGINT NULL REFERENCES users(id);
ALTER TABLE ai_sessions ADD COLUMN tenant_link_id BIGINT NULL REFERENCES user_tenant_links(id);
-- Si tenant_link_id = NULL → usage direct AI-Manager (branding user)
-- Si tenant_link_id = X → usage via client EBP (branding du link)

CREATE INDEX idx_sessions_user ON ai_sessions(user_id);
CREATE INDEX idx_sessions_tenant_link ON ai_sessions(tenant_link_id);
```

**Résolution du branding (priorité)** :
```php
class BrandingResolver
{
    public function resolve(AiSession $session): array
    {
        // 1. Base : Agent par défaut
        $branding = $session->deployment?->agent->default_branding ?? [];

        // 2. Override : Deployment
        $branding = array_merge($branding, $session->deployment?->branding ?? []);

        // 3. Override : User (artisan)
        $branding = array_merge($branding, $session->user?->branding ?? []);

        // 4. Override final : Tenant Link (si via client)
        if ($session->tenant_link_id) {
            $branding = array_merge($branding, $session->tenantLink->branding ?? []);
        }

        // 5. Interpolation des variables
        return $this->interpolate($branding, [
            'user.name' => $session->user?->name,
            'client.name' => $session->tenantLink?->client->name,
            'agent.name' => $session->deployment?->agent->name,
        ]);
    }
}
```

**Flux de liaison compte artisan ↔ client** :
```
Scénario 1 : EBP crée le lien (artisan existe déjà sur AI-Manager)
───────────────────────────────────────────────────────────────────
POST /api/client/users/link
Headers: X-API-Key: ebp_api_key
Body: {
    "email": "contact@durant-peinture.fr",
    "external_id": "DUR-001",
    "branding": { "welcome_message": "Assistant EBP - Durant" }
}
→ Trouve user par email, crée user_tenant_link

Scénario 2 : EBP crée le lien (artisan n'existe pas)
───────────────────────────────────────────────────────────────────
POST /api/client/users/create-and-link
Body: {
    "name": "Durant Peinture",
    "email": "contact@durant-peinture.fr",
    "external_id": "DUR-001",
    "branding": { ... },
    "send_invitation": true
}
→ Crée user (role=artisan) + user_tenant_link
→ Envoie email invitation AI-Manager (accès marketplace)

Scénario 3 : Artisan se lie lui-même via code
───────────────────────────────────────────────────────────────────
1. EBP génère un code de liaison : "LINK-ABC123"
2. Artisan dans AI-Manager : "Lier mon compte" → saisit code
3. Crée user_tenant_link
```

**Avantages de cette architecture** :
| Bénéfice | Description |
|----------|-------------|
| **Compte unique** | Un artisan = un compte AI-Manager pour tout (sessions, marketplace) |
| **Multi-tenant** | Même artisan chez N clients (EBP, SAGE, etc.) |
| **Branding contextuel** | Différent selon le point d'entrée (direct vs via client) |
| **Traçabilité** | `tenant_link_id` indique d'où vient la session |
| **Marketplace** | Artisan peut commander directement depuis son compte |
| **Évolutif** | Permissions granulaires par tenant |

---

#### B. Lien de Session Partageable

**Problème** : L'artisan doit pouvoir générer un lien à envoyer à son client.

**Solution** : API génération de lien + page standalone

```
POST /api/client/sessions/create-link
Headers: X-API-Key: client_api_key
Body: {
    "deployment_key": "dpl_ebp_expert_btp",
    "artisan_external_id": "durant-peinture",
    "context": {
        "project_type": "renovation_sdb",
        "source": "contact_form"
    },
    "expires_in": 604800  // 7 jours
}

Response: {
    "success": true,
    "data": {
        "session_token": "sess_abc123xyz",
        "url": "https://chat.ebp.com/s/sess_abc123xyz",
        "expires_at": "2025-01-03T10:00:00Z"
    }
}
```

**Page standalone widget** :
```
GET /s/{session_token}

→ Page HTML minimale avec widget plein écran
→ Charge automatiquement le contexte (artisan, branding)
→ Mobile-friendly
```

---

#### C. Branding Dynamique par Artisan

**Problème** : Le message d'accueil doit mentionner l'artisan, pas le client (EBP).

**Solution** : Résolution branding en cascade

```
Priorité de résolution :
1. Artisan.branding (si défini)
2. Deployment.branding
3. Agent.default_branding

Variables disponibles dans les templates :
- {artisan.name} → "Durant Peinture"
- {artisan.phone} → "06 12 34 56 78"
- {client.name} → "EBP"
- {agent.name} → "Expert BTP"
```

**Exemple welcome_message** :
```
"Bonjour, je suis l'assistant IA de {artisan.name}.
Comment puis-je vous aider avec votre projet ?"
```

---

#### D. Upload de Photos/Fichiers

**Problème** : L'agent demande des photos, le widget doit supporter l'upload.

**Solution** : Capacité upload dans widget + stockage S3

```javascript
// Widget API étendue
AiManagerWidget.uploadFile(file);  // Returns promise avec URL

// Événements
AiManagerWidget.on('file:uploading', (progress) => {});
AiManagerWidget.on('file:uploaded', (file) => {});
AiManagerWidget.on('file:error', (error) => {});
```

**API Backend** :
```
POST /api/widget/v1/upload
Headers: X-Session-ID
Body: multipart/form-data { file }

Response: {
    "success": true,
    "data": {
        "file_id": "file_xyz789",
        "url": "https://cdn.../uploads/file_xyz789.jpg",
        "thumbnail_url": "https://cdn.../uploads/file_xyz789_thumb.jpg",
        "mime_type": "image/jpeg",
        "size": 245000
    }
}
```

**Limites** :
- Max 10 fichiers par session
- Max 10 MB par fichier
- Types autorisés : jpg, png, pdf, webp

**Table stockage** :
```sql
CREATE TABLE session_files (
    id              BIGSERIAL PRIMARY KEY,
    session_id      BIGINT NOT NULL REFERENCES ai_sessions(id) ON DELETE CASCADE,

    file_id         VARCHAR(50) UNIQUE NOT NULL,
    original_name   VARCHAR(255) NOT NULL,
    storage_path    VARCHAR(500) NOT NULL,
    mime_type       VARCHAR(100) NOT NULL,
    size_bytes      INTEGER NOT NULL,

    -- Métadonnées extraites (EXIF, dimensions...)
    metadata        JSONB NULL,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

#### E. Webhooks Structurés

**Problème** : Les événements doivent être transmis au client (EBP) en temps réel.

**Solution** : Système de webhooks complet

**Table configuration webhooks** :
```sql
CREATE TABLE client_webhooks (
    id              BIGSERIAL PRIMARY KEY,
    client_id       BIGINT NOT NULL REFERENCES clients(id) ON DELETE CASCADE,

    url             VARCHAR(500) NOT NULL,
    secret          VARCHAR(100) NOT NULL,  -- Pour signature HMAC

    -- Événements souscrits
    events          VARCHAR(50)[] NOT NULL,
    -- ['session.started', 'session.completed', 'message.received',
    --  'project.created', 'quote.requested', 'file.uploaded']

    -- Configuration
    is_active       BOOLEAN DEFAULT TRUE,
    retry_count     INTEGER DEFAULT 3,
    timeout_ms      INTEGER DEFAULT 5000,

    -- Statistiques
    last_triggered_at   TIMESTAMP NULL,
    last_status         VARCHAR(20) NULL,  -- success, failed
    failure_count       INTEGER DEFAULT 0,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Payload webhook standardisé** :
```json
{
    "id": "evt_abc123",
    "event": "project.completed",
    "created_at": "2025-01-01T10:30:00Z",
    "deployment": {
        "key": "dpl_ebp_expert_btp",
        "name": "Expert BTP - EBP"
    },
    "artisan": {
        "external_id": "durant-peinture",
        "name": "Durant Peinture"
    },
    "session": {
        "id": "sess_xyz789",
        "started_at": "2025-01-01T10:00:00Z",
        "messages_count": 12
    },
    "data": {
        "project": {
            "type": "renovation_salle_de_bain",
            "description": "Rénovation complète SDB 8m², douche italienne...",
            "surface_m2": 8,
            "requirements": ["douche_italienne", "carrelage_sol", "carrelage_mural"]
        },
        "files": [
            {
                "id": "file_001",
                "url": "https://cdn.../file_001.jpg",
                "type": "image/jpeg"
            }
        ],
        "pre_quote": {
            "items": [
                {"label": "Dépose existant", "quantity": 1, "unit": "forfait", "price_ht": 450},
                {"label": "Plomberie", "quantity": 1, "unit": "forfait", "price_ht": 1200},
                {"label": "Carrelage sol", "quantity": 8, "unit": "m²", "unit_price": 80, "price_ht": 640},
                {"label": "Carrelage mural", "quantity": 20, "unit": "m²", "unit_price": 70, "price_ht": 1400},
                {"label": "Douche italienne", "quantity": 1, "unit": "forfait", "price_ht": 2100}
            ],
            "total_ht": 5790,
            "tva_rate": 20,
            "total_ttc": 6948
        }
    },
    "signature": "sha256=a1b2c3d4e5f6..."
}
```

**Vérification signature (côté client)** :
```php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_AIMANAGER_SIGNATURE'];
$expected = 'sha256=' . hash_hmac('sha256', $payload, $webhook_secret);

if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}
```

---

#### F. Génération Pré-Devis Structuré (Structured Output)

**Problème** : L'agent doit produire un pré-devis au format exploitable par EBP.

**Solution** : Capacité "structured output" pour agents

**Configuration agent** :
```json
{
    "output_schemas": {
        "pre_quote": {
            "type": "object",
            "properties": {
                "project_type": {"type": "string"},
                "items": {
                    "type": "array",
                    "items": {
                        "type": "object",
                        "properties": {
                            "label": {"type": "string"},
                            "quantity": {"type": "number"},
                            "unit": {"type": "string"},
                            "unit_price": {"type": "number"},
                            "price_ht": {"type": "number"}
                        }
                    }
                },
                "total_ht": {"type": "number"},
                "notes": {"type": "string"}
            }
        }
    }
}
```

**Instruction dans system_prompt** :
```
Quand tu génères un pré-devis, utilise TOUJOURS le format JSON suivant
dans un bloc ```json-quote ... ``` pour qu'il soit parsé automatiquement.
```

---

#### G. Workflow Validation Pré-Devis

**Problème** : Deux circuits de validation selon disponibilité expert client.

**Solution** : Statut de validation configurable par client

**Configuration client** :
```json
{
    "validation_workflow": {
        "mode": "client_first",  // "client_first" | "direct_master" | "auto"
        "client_validators": ["admin@ebp.com"],
        "auto_promote_after_days": 7,
        "require_anonymization": true
    }
}
```

**États d'une session/pré-devis** :
```
created → pending_client_review → client_validated → pending_master_review → validated
                                → client_rejected

created → pending_master_review → validated  (si mode = direct_master)
                                → rejected
```

**Anonymisation automatique** :
```php
class ProjectAnonymizer
{
    public function anonymize(array $projectData): array
    {
        // Supprime les données personnelles avant envoi au master
        unset($projectData['artisan']);
        unset($projectData['session']['client_ip']);

        // Remplace les noms propres dans la description
        $projectData['description'] = $this->removeNames($projectData['description']);

        // Floute les visages dans les photos (si détectés)
        foreach ($projectData['files'] as &$file) {
            $file['url'] = $this->blurFaces($file['url']);
        }

        return $projectData;
    }
}
```

---

#### H. Intégration Marketplace (Retour Devis Signé)

**Problème** : Quand le devis est signé chez EBP, déclencher la commande matériaux.

**Solution** : API réception devis signé

```
POST /api/integration/v1/quote-signed
Headers: X-API-Key: client_api_key
Body: {
    "session_id": "sess_xyz789",
    "quote_reference": "DEV-2025-00123",
    "signed_at": "2025-01-05T14:30:00Z",
    "final_amount_ttc": 7200,
    "items": [
        {
            "label": "Carrelage sol Gris 60x60",
            "sku": "CARREL-GR-60",
            "quantity": 10,
            "unit": "m²"
        },
        {
            "label": "Receveur douche 90x120",
            "sku": "RECV-90120-BL",
            "quantity": 1
        }
    ],
    "delivery_address": {
        "name": "Durant Peinture",
        "street": "12 rue des Artisans",
        "postal_code": "75011",
        "city": "Paris"
    }
}

Response: {
    "success": true,
    "data": {
        "marketplace_order_id": "ORD-2025-00456",
        "status": "pending_validation",
        "estimated_delivery": "2025-01-12"
    }
}
```

**Workflow marketplace** :
```
Devis signé (EBP)
       │
       ▼
API quote-signed
       │
       ▼
Matching SKU → Produits Marketplace
       │
       ▼
Création commande provisoire
       │
       ▼
Notification artisan (validation commande)
       │
       ▼
Commande fournisseur
```

---

### 15.3 Résumé des Modifications CDC

| Section | Modification |
|---------|--------------|
| **3. Architecture** | Modifier `users` (role, branding), ajouter `user_tenant_links`, modifier `ai_sessions` |
| **5. Widget** | Ajouter upload fichiers, page standalone /s/{token} |
| **6. API Endpoints** | Ajouter `/sessions/create-link`, `/upload`, `/quote-signed` |
| **8. Flux de Données** | Ajouter résolution branding 3 niveaux |
| **Nouvelle section** | Webhooks (configuration, événements, payloads) |
| **Nouvelle section** | Structured Output (schémas JSON pour pré-devis) |
| **Nouvelle section** | Workflow Validation (états, anonymisation) |
| **Nouvelle section** | Intégration Marketplace |

### 15.4 Priorisation Implémentation

| Phase | Fonctionnalité | Effort | Bloquant pour MVP |
|-------|----------------|--------|-------------------|
| **1** | Users (role artisan) + user_tenant_links + lien session | 2j | ✅ OUI |
| **1** | Branding dynamique artisan | 1j | ✅ OUI |
| **1** | Upload photos widget | 2j | ✅ OUI |
| **1** | Webhooks base (session.completed) | 1j | ✅ OUI |
| **2** | Structured output pré-devis | 2j | ✅ OUI |
| **2** | Webhooks complets (tous events) | 1j | Non |
| **3** | Workflow validation 2 circuits | 2j | Non |
| **3** | Anonymisation automatique | 1j | Non |
| **4** | Intégration marketplace | 3j | Non (phase 2 produit) |

**Total MVP (Phases 1-2)** : ~9 jours de développement

---

**Fin du document**

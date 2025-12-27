# Agents IA en Marque Blanche - Cahier des Charges

> **Statut** : ✅ IMPLÉMENTÉ - Toutes les phases complétées
> **Version** : 1.0.0
> **Date** : 27 Décembre 2025
> **Auteur** : Rodolphe
> **Implémentation** : Claude (Phases 1-5 complètes)

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
-- ⚠️ NOTE: Le système de rôles existe DÉJÀ (tables roles, user_roles, permissions, role_permissions)
-- Utiliser le système existant : $user->hasRole('artisan'), $user->roles()->attach($roleId)

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

**Seeder: créer rôles whitelabel** (utiliser table `roles` existante) :
```php
// database/seeders/WhitelabelRolesSeeder.php
Role::firstOrCreate(['slug' => 'artisan'], [
    'name' => 'Artisan',
    'description' => 'Artisan utilisant les agents IA via clients whitelabel',
    'is_system' => true,
]);

Role::firstOrCreate(['slug' => 'metreur'], [
    'name' => 'Métreur',
    'description' => 'Validateur technique des pré-devis IA',
    'is_system' => true,
]);

Role::firstOrCreate(['slug' => 'client-admin'], [
    'name' => 'Admin Client',
    'description' => 'Administrateur d\'un client whitelabel (EBP, SAGE...)',
    'is_system' => true,
]);
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

## 16. Analyse de Complexité et Checklist d'Implémentation

> Ce document sert de guide exhaustif pour l'implémentation. Chaque tâche est détaillée avec ses dépendances, sa complexité, et les points d'attention pour éviter les oublis.

### 16.1 Vue d'Ensemble

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    CARTE DE COMPLEXITÉ PAR MODULE                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  BACKEND (Laravel/PHP)                                                      │
│  ├── 🟢 Faible    : Migrations, Models, Relations                          │
│  ├── 🟡 Moyenne   : API CRUD, Middlewares, Services                        │
│  └── 🔴 Élevée    : BrandingResolver, Webhooks async, Structured Output    │
│                                                                             │
│  FRONTEND (Widget JS)                                                       │
│  ├── 🟡 Moyenne   : Widget loader, UI Chat                                 │
│  └── 🔴 Élevée    : Upload fichiers, iframe communication, Branding dynamique │
│                                                                             │
│  ADMIN (Filament)                                                           │
│  ├── 🟢 Faible    : Resources CRUD                                         │
│  └── 🟡 Moyenne   : Relations, Stats, Workflows validation                 │
│                                                                             │
│  INFRA                                                                      │
│  ├── 🟢 Faible    : Storage S3                                             │
│  └── 🟡 Moyenne   : Queue workers (webhooks), CDN                          │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 16.2 Estimation Détaillée par Tâche

#### PHASE 1 : Fondations (MVP Critique)

| # | Tâche | Complexité | Temps | Dépendances | Risques |
|---|-------|------------|-------|-------------|---------|
| 1.1 | Migration `clients` | 🟢 Faible | 1h | - | Aucun |
| 1.2 | Migration `agent_deployments` | 🟢 Faible | 1h | 1.1 | FK agents |
| 1.3 | Migration `allowed_domains` | 🟢 Faible | 30min | 1.2 | - |
| 1.4 | Migration `user_tenant_links` | 🟢 Faible | 1h | 1.1 | FK users |
| 1.5 | Migration modification `users` (branding, marketplace) | 🟢 Faible | 20min | - | ⚠️ Rôles DÉJÀ gérés |
| 1.5b | Seeder rôles whitelabel (artisan, metreur, client-admin) | 🟢 Faible | 20min | - | Utilise table roles existante |
| 1.6 | Migration modification `ai_sessions` | 🟢 Faible | 30min | 1.4 | Données existantes |
| 1.7 | Model `Client` + relations | 🟢 Faible | 1h | 1.1 | - |
| 1.8 | Model `AgentDeployment` + relations | 🟢 Faible | 1h | 1.2 | - |
| 1.9 | Model `AllowedDomain` + relations | 🟢 Faible | 30min | 1.3 | - |
| 1.10 | Model `UserTenantLink` + relations | 🟢 Faible | 1h | 1.4 | - |
| 1.11 | Modification Model `User` | 🟢 Faible | 30min | 1.5 | - |
| 1.12 | Modification Model `AiSession` | 🟢 Faible | 30min | 1.6 | - |
| **Sous-total Phase 1.A** | | | **8h** | | |

| # | Tâche | Complexité | Temps | Dépendances | Risques |
|---|-------|------------|-------|-------------|---------|
| 1.13 | `ClientResource` Filament | 🟡 Moyenne | 3h | 1.7 | - |
| 1.14 | `AgentDeploymentResource` Filament | 🟡 Moyenne | 4h | 1.8 | Relations complexes |
| 1.15 | `UserTenantLinkResource` Filament | 🟡 Moyenne | 2h | 1.10 | - |
| 1.16 | Intégration domaines dans deployment | 🟡 Moyenne | 2h | 1.9, 1.14 | Repeater Filament |
| **Sous-total Phase 1.B** | | | **11h** | | |

| # | Tâche | Complexité | Temps | Dépendances | Risques |
|---|-------|------------|-------|-------------|---------|
| 1.17 | Middleware `ValidateDeploymentDomain` | 🟡 Moyenne | 3h | 1.8, 1.9 | Regex wildcards |
| 1.18 | Middleware `DynamicCors` | 🟡 Moyenne | 2h | 1.17 | Headers CORS |
| 1.19 | Rate Limiting par deployment | 🟡 Moyenne | 2h | 1.17 | Redis/Cache |
| 1.20 | Vérification quotas client | 🟡 Moyenne | 2h | 1.7 | Compteurs atomiques |
| **Sous-total Phase 1.C** | | | **9h** | | |

**Total Phase 1 : 28h (3.5 jours)**

---

#### PHASE 2 : Widget & API

| # | Tâche | Complexité | Temps | Dépendances | Risques |
|---|-------|------------|-------|-------------|---------|
| 2.1 | API `/widget/v1/init` | 🟡 Moyenne | 2h | Phase 1 | - |
| 2.2 | API `/widget/v1/message` | 🟡 Moyenne | 3h | 2.1 | Streaming |
| 2.3 | API `/widget/v1/message/{id}/status` | 🟢 Faible | 1h | 2.2 | - |
| 2.4 | API `/client/sessions/create-link` | 🟡 Moyenne | 2h | 1.10 | Token sécurisé |
| 2.5 | API `/client/users/link` | 🟡 Moyenne | 2h | 1.10 | Match email |
| 2.6 | API `/client/users/create-and-link` | 🟡 Moyenne | 2h | 2.5 | Invitation email |
| **Sous-total Phase 2.A** | | | **12h** | | |

| # | Tâche | Complexité | Temps | Dépendances | Risques |
|---|-------|------------|-------|-------------|---------|
| 2.7 | Widget `loader.js` | 🟡 Moyenne | 4h | - | Cross-browser |
| 2.8 | Widget iframe container | 🟡 Moyenne | 3h | 2.7 | PostMessage |
| 2.9 | Widget UI Chat (HTML/CSS) | 🟡 Moyenne | 6h | 2.8 | Responsive |
| 2.10 | Widget communication iframe ↔ parent | 🔴 Élevée | 4h | 2.8 | Sécurité |
| 2.11 | Widget API publique (open/close/send) | 🟡 Moyenne | 2h | 2.10 | - |
| 2.12 | Page standalone `/s/{token}` | 🟡 Moyenne | 2h | 2.8 | Mobile |
| **Sous-total Phase 2.B** | | | **21h** | | |

| # | Tâche | Complexité | Temps | Dépendances | Risques |
|---|-------|------------|-------|-------------|---------|
| 2.13 | Service `BrandingResolver` | 🔴 Élevée | 4h | 1.10, 1.12 | Cascade 4 niveaux |
| 2.14 | Interpolation variables branding | 🟡 Moyenne | 2h | 2.13 | Regex |
| 2.15 | Intégration branding dans widget | 🟡 Moyenne | 2h | 2.9, 2.13 | CSS dynamique |
| **Sous-total Phase 2.C** | | | **8h** | | |

**Total Phase 2 : 41h (5 jours)**

---

#### PHASE 3 : Upload & Webhooks

| # | Tâche | Complexité | Temps | Dépendances | Risques |
|---|-------|------------|-------|-------------|---------|
| 3.1 | Migration `session_files` | 🟢 Faible | 30min | - | - |
| 3.2 | Migration `client_webhooks` | 🟢 Faible | 30min | 1.1 | - |
| 3.3 | Model `SessionFile` | 🟢 Faible | 30min | 3.1 | - |
| 3.4 | Model `ClientWebhook` | 🟢 Faible | 30min | 3.2 | - |
| 3.5 | API `/widget/v1/upload` | 🟡 Moyenne | 3h | 3.1 | Validation MIME |
| 3.6 | Service `FileUploadService` (S3) | 🟡 Moyenne | 3h | 3.5 | Config S3 |
| 3.7 | Génération thumbnails | 🟡 Moyenne | 2h | 3.6 | Intervention/Image |
| 3.8 | Widget: UI upload + progress | 🟡 Moyenne | 4h | 2.9, 3.5 | UX |
| **Sous-total Phase 3.A** | | | **14h** | | |

| # | Tâche | Complexité | Temps | Dépendances | Risques |
|---|-------|------------|-------|-------------|---------|
| 3.9 | Service `WebhookDispatcher` | 🔴 Élevée | 4h | 3.4 | Async, retry |
| 3.10 | Job `DispatchWebhookJob` (queue) | 🟡 Moyenne | 2h | 3.9 | Queue config |
| 3.11 | Signature HMAC webhooks | 🟡 Moyenne | 1h | 3.9 | Crypto |
| 3.12 | Events Laravel (session.*, message.*) | 🟡 Moyenne | 2h | 3.9 | - |
| 3.13 | Logging webhooks (succès/échecs) | 🟡 Moyenne | 2h | 3.9 | - |
| 3.14 | UI Filament: gestion webhooks | 🟡 Moyenne | 3h | 3.4 | - |
| 3.15 | UI Filament: logs webhooks | 🟡 Moyenne | 2h | 3.13 | - |
| **Sous-total Phase 3.B** | | | **16h** | | |

**Total Phase 3 : 30h (4 jours)**

---

#### PHASE 4 : Structured Output & Validation

| # | Tâche | Complexité | Temps | Dépendances | Risques |
|---|-------|------------|-------|-------------|---------|
| 4.1 | Parser JSON structured output | 🔴 Élevée | 4h | - | Regex robuste |
| 4.2 | Schéma JSON pré-devis | 🟡 Moyenne | 2h | 4.1 | Validation |
| 4.3 | Intégration dans prompt agent | 🟡 Moyenne | 2h | 4.2 | Tests |
| 4.4 | Extraction auto dans webhook payload | 🟡 Moyenne | 2h | 4.1, 3.9 | - |
| **Sous-total Phase 4.A** | | | **10h** | | |

| # | Tâche | Complexité | Temps | Dépendances | Risques |
|---|-------|------------|-------|-------------|---------|
| 4.5 | États validation (migration) | 🟢 Faible | 1h | - | - |
| 4.6 | Workflow machine (states) | 🟡 Moyenne | 3h | 4.5 | Transitions |
| 4.7 | UI validation client (Filament) | 🟡 Moyenne | 4h | 4.6 | UX |
| 4.8 | UI validation master (Filament) | 🟡 Moyenne | 3h | 4.7 | - |
| 4.9 | Service `ProjectAnonymizer` | 🟡 Moyenne | 3h | - | NLP basique |
| 4.10 | Intégration flou visages (optionnel) | 🔴 Élevée | 4h | - | ML/API externe |
| **Sous-total Phase 4.B** | | | **18h** | | |

**Total Phase 4 : 28h (3.5 jours)**

---

#### PHASE 5 : Marketplace (Phase 2 Produit)

| # | Tâche | Complexité | Temps | Dépendances | Risques |
|---|-------|------------|-------|-------------|---------|
| 5.1 | API `/integration/v1/quote-signed` | 🟡 Moyenne | 3h | - | Validation |
| 5.2 | Service matching SKU → produits | 🔴 Élevée | 6h | 5.1 | Algorithme |
| 5.3 | Création commande provisoire | 🟡 Moyenne | 3h | 5.2 | - |
| 5.4 | Notification artisan | 🟡 Moyenne | 2h | 5.3 | Email/Push |
| 5.5 | UI validation commande (artisan) | 🟡 Moyenne | 4h | 5.4 | - |
| 5.6 | Intégration fournisseurs | 🔴 Élevée | 8h | 5.5 | APIs variées |
| **Total Phase 5** | | | **26h (3 jours)** | | |

---

### 16.3 Résumé Temps Total

| Phase | Description | Temps | Jours | Statut |
|-------|-------------|-------|-------|--------|
| **1** | Fondations (DB, Models, Admin, Sécurité) | 28h | 3.5j | ✅ Terminé |
| **2** | Widget & API | 41h | 5j | ✅ Terminé |
| **3** | Upload & Webhooks | 30h | 4j | ✅ Terminé |
| **4** | Structured Output & Validation | 28h | 3.5j | ✅ Terminé |
| **5** | Marketplace | 26h | 3j | ✅ Terminé |
| | | | | |
| **MVP (1-3)** | Fonctionnel pour démo client | **99h** | **12.5j** | ✅ |
| **Complet (1-5)** | Full feature + Marketplace | **153h** | **19j** | ✅ |

> ✅ **Implémentation complète** : Toutes les phases ont été implémentées (27 décembre 2025)

---

### 16.4 Checklist d'Implémentation Complète

#### ☐ PHASE 1 : Base de données & Models

```
☐ 1. MIGRATIONS
  ☐ 1.1 create_clients_table
      ☐ uuid, name, slug, logo_url, website_url
      ☐ contact_name, contact_email, contact_phone
      ☐ billing_email, billing_address, billing_type, billing_status
      ☐ max_deployments, max_sessions_month, max_messages_month
      ☐ current_month_sessions, current_month_messages, total_sessions
      ☐ api_key, api_key_prefix (générer avec Str::random)
      ☐ status, notes, timestamps
      ☐ Index: slug, api_key

  ☐ 1.2 create_agent_deployments_table
      ☐ uuid, agent_id (FK), client_id (FK)
      ☐ name, deployment_key (unique, générer)
      ☐ deployment_mode (shared/dedicated)
      ☐ config_overlay (JSONB)
      ☐ branding (JSONB)
      ☐ dedicated_collection (nullable)
      ☐ max_sessions_day, max_messages_day, rate_limit_per_ip
      ☐ sessions_count, messages_count, last_activity_at
      ☐ is_active, timestamps
      ☐ Index: deployment_key, agent_id, client_id
      ☐ Unique: (agent_id, client_id, name)

  ☐ 1.3 create_allowed_domains_table
      ☐ deployment_id (FK)
      ☐ domain, is_wildcard, environment
      ☐ is_active, verified_at, created_at
      ☐ Index: deployment_id, domain
      ☐ Unique: (deployment_id, domain)

  ☐ 1.4 create_user_tenant_links_table
      ☐ user_id (FK), client_id (FK)
      ☐ external_id
      ☐ branding (JSONB), permissions (JSONB)
      ☐ is_active, linked_at
      ☐ Index: user_id, client_id, external_id
      ☐ Unique: (user_id, client_id), (client_id, external_id)

  ☐ 1.5 modify_users_table
      ☐ ADD branding JSONB NULL
      ☐ ADD marketplace_enabled BOOLEAN DEFAULT FALSE
      ☐ ⚠️ NE PAS ajouter colonne role (système roles/user_roles EXISTE DÉJÀ)

  ☐ 1.5b Seeder: créer rôles whitelabel (table roles existante)
      ☐ Créer role 'artisan' (slug: artisan)
      ☐ Créer role 'metreur' (slug: metreur)
      ☐ Créer role 'client-admin' (slug: client-admin)
      ☐ Associer permissions appropriées via role_permissions

  ☐ 1.6 modify_ai_sessions_table
      ☐ ADD user_id (FK nullable)
      ☐ ADD tenant_link_id (FK nullable)
      ☐ ADD deployment_id (FK nullable)
      ☐ Index: user_id, tenant_link_id, deployment_id

  ☐ 1.7 modify_agents_table
      ☐ ADD deployment_mode VARCHAR(20) DEFAULT 'internal'
      ☐ ADD is_whitelabel_enabled BOOLEAN DEFAULT FALSE
      ☐ ADD whitelabel_config JSONB NULL
```

```
☐ 2. MODELS & RELATIONS
  ☐ 2.1 Client.php
      ☐ $fillable complet
      ☐ $casts: billing_type, status (enum), api_key (encrypted)
      ☐ Relation: deployments() hasMany
      ☐ Relation: tenantLinks() hasMany
      ☐ Relation: webhooks() hasMany
      ☐ Méthode: hasQuotaRemaining(): bool
      ☐ Méthode: generateApiKey(): string
      ☐ Boot: générer api_key si vide

  ☐ 2.2 AgentDeployment.php
      ☐ $fillable, $casts (config_overlay, branding as array)
      ☐ Relation: agent() belongsTo
      ☐ Relation: client() belongsTo
      ☐ Relation: allowedDomains() hasMany
      ☐ Relation: sessions() hasMany
      ☐ Méthode: generateDeploymentKey(): string
      ☐ Méthode: isDomainAllowed(string $domain): bool

  ☐ 2.3 AllowedDomain.php
      ☐ $fillable, $casts
      ☐ Relation: deployment() belongsTo
      ☐ Méthode: matches(string $host): bool

  ☐ 2.4 UserTenantLink.php
      ☐ $fillable, $casts (branding, permissions as array)
      ☐ Relation: user() belongsTo
      ☐ Relation: client() belongsTo
      ☐ Relation: sessions() hasMany

  ☐ 2.5 Modifier User.php (⚠️ roles() et hasRole() EXISTENT DÉJÀ)
      ☐ Ajouter $casts: branding as array
      ☐ Ajouter $fillable: branding, marketplace_enabled
      ☐ Relation: tenantLinks() hasMany (NOUVELLE)
      ☐ Méthode: isArtisan(): bool → return $this->hasRole('artisan'); (utilise existant)
      ☐ Méthode: linkToClient(Client $client, array $data)

  ☐ 2.6 Modifier AiSession.php
      ☐ Relation: user() belongsTo (nullable)
      ☐ Relation: tenantLink() belongsTo (nullable)
      ☐ Relation: deployment() belongsTo (nullable)
      ☐ Relation: files() hasMany
```

```
☐ 3. FILAMENT RESOURCES
  ☐ 3.1 ClientResource.php
      ☐ Table: colonnes (logo, name, deployments_count, usage, status)
      ☐ Table: filters (status, billing_type)
      ☐ Table: actions (edit, view stats)
      ☐ Form: sections (info, contact, billing, limites, API)
      ☐ Form: api_key avec bouton régénérer
      ☐ Page: Stats client (graphiques usage)

  ☐ 3.2 AgentDeploymentResource.php
      ☐ Table: colonnes (agent, client, domains_count, sessions, status)
      ☐ Table: filters (client, agent, mode)
      ☐ Form: section config_overlay (JSON editor ou champs)
      ☐ Form: section branding (color picker, file upload)
      ☐ Form: Repeater pour allowed_domains
      ☐ Form: bouton "Copier code intégration"
      ☐ Page: Tester le widget (preview)

  ☐ 3.3 UserTenantLinkResource.php (ou inline dans UserResource)
      ☐ Afficher les liens par utilisateur
      ☐ Form: sélection client, external_id, branding
```

```
☐ 4. MIDDLEWARES & SÉCURITÉ
  ☐ 4.1 ValidateDeploymentDomain.php
      ☐ Extraire deployment_key (header ou query)
      ☐ Charger deployment avec allowedDomains
      ☐ Vérifier is_active
      ☐ Extraire Origin/Referer, parser host
      ☐ Matcher contre domains (exact + wildcard)
      ☐ Support localhost si environment=development
      ☐ Injecter deployment dans request
      ☐ Logging tentatives non autorisées

  ☐ 4.2 DynamicCors.php
      ☐ Lire deployment depuis request
      ☐ Générer Access-Control-Allow-Origin dynamique
      ☐ Gérer preflight OPTIONS

  ☐ 4.3 RateLimitDeployment.php
      ☐ Rate limit par IP (cache key avec deployment_id)
      ☐ Respecter deployment.rate_limit_per_ip
      ☐ Headers X-RateLimit-*

  ☐ 4.4 CheckClientQuota.php
      ☐ Vérifier quotas mensuels client
      ☐ Incrémenter compteurs (atomic)
      ☐ Retourner 429 si dépassé
```

---

#### ✅ PHASE 2 : API & Widget (Complété 27/12/2024)

```
✅ 5. API ENDPOINTS
  ✅ 5.1 POST /api/whitelabel/sessions (WidgetController::init)
      ✅ Request: deployment_key (header), external_id, context (optional)
      ✅ Middleware: deployment.key, deployment.domain, deployment.rate, deployment.cors
      ✅ Créer session (avec deployment_id, editor_link_id si external_id)
      ✅ Résoudre branding via BrandingResolver
      ✅ Response: session_id, agent info, branding, welcome_message

  ✅ 5.2 POST /api/whitelabel/sessions/{sessionId}/messages (WidgetController::sendMessage)
      ✅ Request: message
      ✅ Middleware: editor.quota:message
      ✅ Valider session active
      ✅ Créer message, dispatch job
      ✅ Response: message_id, content, sources

  ✅ 5.3 GET /api/messages/{uuid}/status (PublicChatController::messageStatus)
      ✅ Polling status (pending/queued/processing/completed/failed)
      ✅ Si completed: retourner content

  ✅ 5.4 GET /api/whitelabel/sessions/{sessionId}/messages (WidgetController::getMessages)
      ✅ Liste messages de la session

  ✅ 5.5 POST /api/editor/sessions/create-link (EditorController::createSessionLink)
      ✅ Auth: editor.auth middleware
      ✅ Request: deployment_id, external_id, context, expires_in
      ✅ Trouver editor_link par external_id
      ✅ Générer token whitelabel (wl_{deploymentId}_{random})
      ✅ Response: url (/s/{token}), session_token, expires_at

  ✅ 5.6 POST /api/editor/artisans/link (EditorController::linkArtisan)
      ✅ Auth: editor.auth middleware
      ✅ Trouver user par email
      ✅ Créer UserEditorLink
      ✅ Response: link_id, success

  ✅ 5.7 POST /api/editor/artisans/create-and-link (EditorController::createAndLinkArtisan)
      ✅ Créer user (role=artisan, password null)
      ✅ Créer UserEditorLink
      ✅ Si send_invitation: TODO email
```

Fichiers créés:
- `app/Http/Controllers/Api/Whitelabel/WidgetController.php`
- `app/Http/Controllers/Api/Whitelabel/EditorController.php`
- `routes/api.php` (mis à jour avec routes /whitelabel/* et /editor/*)

```
✅ 6. WIDGET JAVASCRIPT
  ✅ 6.1 loader.js (public/whitelabel/loader.js)
      ✅ Lire data-deployment-key ou window.BatiramaWidgetConfig
      ✅ Créer iframe avec src vers widget.html
      ✅ Injecter dans body ou containerSelector
      ✅ Exposer window.BatiramaWidget

  ✅ 6.2 widget.html (public/whitelabel/widget.html)
      ✅ CSS: reset, variables, responsive
      ✅ HTML: header, messages, input, bouton flottant
      ✅ JS: communication postMessage avec parent

  ✅ 6.3 Communication iframe ↔ parent
      ✅ Parent → iframe: batirama:send_message, batirama:set_context
      ✅ Iframe → parent: batirama:ready, batirama:session_started, batirama:message, batirama:close
      ✅ Valider origin des messages

  ✅ 6.4 API publique widget (BatiramaWidget)
      ✅ BatiramaWidget.open()
      ✅ BatiramaWidget.close()
      ✅ BatiramaWidget.toggle()
      ✅ BatiramaWidget.sendMessage(text)
      ✅ BatiramaWidget.setContext(data)
      ✅ Callbacks: onReady, onMessage, onError, onSessionStart, onSessionEnd
      ✅ BatiramaWidget.destroy()

  ✅ 6.5 Page standalone /s/{token}
      ✅ Route: web.php GET /s/{token} (StandaloneChatController)
      ✅ Controller: valider token, extraire déploiement/session
      ✅ View: resources/views/whitelabel/standalone.blade.php
      ✅ Meta viewport pour mobile
      ✅ View erreur: resources/views/whitelabel/error.blade.php
```

Fichiers créés:
- `public/whitelabel/loader.js`
- `public/whitelabel/widget.html`
- `app/Http/Controllers/Whitelabel/StandaloneChatController.php`
- `resources/views/whitelabel/standalone.blade.php`
- `resources/views/whitelabel/error.blade.php`

```
✅ 7. SERVICE BRANDING
  ✅ 7.1 BrandingResolver.php (app/Services/Whitelabel/BrandingResolver.php)
      ✅ Méthode resolve(AiSession $session): array
      ✅ Cascade: agent → deployment → user → editorLink
      ✅ Méthode interpolate(array $branding, array $vars): array
      ✅ Variables: {user.name}, {artisan.name}, {agent.name}, {artisan.company}
      ✅ Regex pour remplacer {xxx.yyy}
      ✅ Gérer valeurs manquantes (supprimer placeholder)
      ✅ Méthode resolveForDeployment(AgentDeployment, ?UserEditorLink): array
```

---

#### ✅ PHASE 3 : Upload & Webhooks

**Implémenté le 27 décembre 2025**

Fichiers créés:
- `database/migrations/2025_12_27_100001_create_session_files_table.php`
- `database/migrations/2025_12_27_100002_create_editor_webhooks_table.php`
- `app/Models/SessionFile.php`
- `app/Models/EditorWebhook.php`
- `app/Models/EditorWebhookLog.php`
- `app/Services/Upload/FileUploadService.php`
- `app/Services/Webhook/WebhookDispatcher.php`
- `app/Jobs/DispatchWebhookJob.php`
- `app/Events/Whitelabel/SessionStarted.php`
- `app/Events/Whitelabel/SessionCompleted.php`
- `app/Events/Whitelabel/MessageReceived.php`
- `app/Events/Whitelabel/FileUploaded.php`
- `app/Listeners/Whitelabel/DispatchWebhookListener.php`

Fichiers modifiés:
- `app/Http/Controllers/Api/Whitelabel/WidgetController.php` (uploadFile, getFiles)
- `app/Providers/AppServiceProvider.php` (event subscriber)
- `routes/api.php` (upload routes)
- `public/whitelabel/widget.html` (UI upload avec preview et progress)

```
✅ 8. UPLOAD FICHIERS
  ✅ 8.1 Migration session_files
      ✅ session_id (FK), uuid, original_name
      ✅ storage_path, storage_disk, mime_type, size_bytes
      ✅ thumbnail_path, file_type, status
      ✅ metadata (JSONB), created_at

  ✅ 8.2 SessionFile.php model
      ✅ Relation session()
      ✅ Accessor url() (générer signed URL ou public URL)
      ✅ Accessor thumbnailUrl()
      ✅ Méthode determineFileType(mimeType)
      ✅ Méthode toApiArray()

  ✅ 8.3 POST /api/whitelabel/sessions/{sessionId}/upload
      ✅ Valider: mime type, taille max (10MB)
      ✅ Valider: nombre fichiers session (max 10)
      ✅ Upload vers disk configuré (path: sessions/{date}/{session_uuid}/{file_uuid})
      ✅ Générer thumbnail si image
      ✅ Créer SessionFile
      ✅ Dispatch FileUploaded event
      ✅ Response: file avec url, thumbnail, metadata

  ✅ 8.4 FileUploadService.php
      ✅ Méthode upload(UploadedFile, session): SessionFile
      ✅ Méthode generateThumbnail(SessionFile): string
      ✅ Méthode generateThumbnailGd() (fallback GD)
      ✅ Méthode deleteSessionFiles(session): int
      ✅ Méthode getSessionFiles(session): array
      ✅ Utiliser Intervention/Image avec fallback GD

  ✅ 8.5 Widget: UI upload
      ✅ Bouton clip/attachment dans input
      ✅ Input file hidden (accept images, pdf, docs, etc.)
      ✅ Preview avant envoi (thumbnail pour images, icône pour autres)
      ✅ Progress bar upload avec XHR
      ✅ Afficher attachments dans messages
      ✅ Fonction clearFilePreview()
```

```
✅ 9. WEBHOOKS
  ✅ 9.1 Migration editor_webhooks + editor_webhook_logs
      ✅ editor_id (FK), url, secret
      ✅ events (JSONB array), is_active
      ✅ max_retries, timeout_seconds
      ✅ Logs: webhook_id, event, payload, http_status, response_body
      ✅ Logs: response_time_ms, status, attempt, error_message

  ✅ 9.2 EditorWebhook.php + EditorWebhookLog.php models
      ✅ $casts: events as array
      ✅ Relation editor(), logs()
      ✅ Méthode shouldTrigger(string $event): bool
      ✅ Méthode generateSignature(payload): string
      ✅ EditorWebhookLog: markAsSuccess, markAsFailed, markForRetry

  ✅ 9.3 WebhookDispatcher.php service
      ✅ Méthode dispatchSessionStarted(session)
      ✅ Méthode dispatchSessionCompleted(session)
      ✅ Méthode dispatchMessageReceived(session, message)
      ✅ Méthode dispatchFileUploaded(session, file)
      ✅ Trouver webhooks actifs pour editor + event
      ✅ Pour chaque webhook: dispatch DispatchWebhookJob

  ✅ 9.4 DispatchWebhookJob.php
      ✅ Properties: webhookId, event, payload
      ✅ Générer signature HMAC-SHA256 (sha256=...)
      ✅ HTTP POST avec timeout configurable
      ✅ Headers: X-Batirama-Signature, X-Batirama-Event, X-Batirama-Delivery
      ✅ Retry logic (backoff: 10s, 60s, 300s)
      ✅ Logging résultat dans EditorWebhookLog
      ✅ Mise à jour last_triggered_at, failure_count

  ✅ 9.5 Events Laravel
      ✅ SessionStarted (après création session)
      ✅ SessionCompleted (après fermeture session)
      ✅ MessageReceived (à implémenter dans chat flow)
      ✅ FileUploaded (après upload réussi)

  ✅ 9.6 Listeners → WebhookDispatcher
      ✅ DispatchWebhookListener (event subscriber)
      ✅ handleSessionStarted, handleSessionCompleted
      ✅ handleMessageReceived, handleFileUploaded
      ✅ Enregistré dans AppServiceProvider

  ☐ 9.7 Filament: gestion webhooks (Phase 4)
      ☐ Dans EditorResource: relation panel webhooks
      ☐ Créer/éditer webhook (url, secret, events checkboxes)
      ☐ Bouton "Tester webhook"
      ☐ Historique: derniers envois avec status
```

---

#### ✅ PHASE 4 : Structured Output & Validation

**Implémenté le 27 décembre 2025**

Fichiers créés:
- `app/Services/StructuredOutput/StructuredOutputParser.php`
- `app/Services/StructuredOutput/PreQuoteSchema.php`
- `app/Services/Validation/ValidationWorkflow.php`
- `app/Services/Validation/ProjectAnonymizer.php`
- `app/Models/SessionValidationLog.php`
- `database/migrations/2025_12_27_100003_add_validation_status_to_ai_sessions.php`

Fichiers modifiés:
- `app/Services/AI/PromptBuilder.php` (instructions structured output)
- `app/Services/Webhook/WebhookDispatcher.php` (extraction + dispatch)

```
✅ 10. STRUCTURED OUTPUT
  ✅ 10.1 StructuredOutputParser.php
      ✅ Méthode parse(string $content): ?array
      ✅ Patterns: json-quote, json-pre-quote, json-project, json
      ✅ Valider JSON avec JsonException handling
      ✅ Méthodes: parseAll(), parsePreQuote(), stripStructuredOutput()
      ✅ getPromptInstructions() pour injection dans system prompt

  ✅ 10.2 Schéma pré-devis (PreQuoteSchema.php)
      ✅ Types de projets: peinture, plomberie, électricité, etc.
      ✅ Validation: items[], total_ht, tva_rate, total_ttc
      ✅ Normalisation: unités, types de projet, calculs totaux
      ✅ Méthodes: validate(), validateSafe(), sanitize()
      ✅ toSummary() pour résumé textuel
      ✅ toWebhookPayload() pour format webhook

  ✅ 10.3 Intégration prompt (PromptBuilder)
      ✅ Injection instructions structured output dans system_prompt
      ✅ Activation auto pour sessions whitelabel (deployment_id)
      ✅ Template JSON avec exemple complet pré-devis
      ✅ Instructions TVA, prix réalistes

  ✅ 10.4 Extraction dans webhook (WebhookDispatcher)
      ✅ extractAndDispatchStructuredOutput(session, message)
      ✅ Stockage dans message.metadata.structured_output
      ✅ Dispatch project.created avec pre_quote validé
      ✅ dispatchMessageWithStructuredOutput() version améliorée
```

```
✅ 11. WORKFLOW VALIDATION
  ✅ 11.1 Migration: ajouter status à ai_sessions
      ✅ validation_status: pending, pending_client_review,
        client_validated, pending_master_review, validated, rejected
      ✅ validated_by (FK user), validated_at, validation_comment
      ✅ pre_quote_data (JSONB), anonymized_project (JSONB)
      ✅ Table session_validation_logs pour historique

  ✅ 11.2 Config validation_workflow
      ✅ Mode: client_first | direct_master | auto
      ✅ Configuration via editor.settings JSONB
      ✅ Lecture depuis deployment.editor dans workflow

  ✅ 11.3 ValidationWorkflow.php service
      ✅ submitForValidation(session, preQuoteData)
      ✅ clientValidate(), clientReject()
      ✅ masterValidate(), masterReject()
      ✅ sendToMaster() avec anonymisation auto
      ✅ promoteToLearnedResponse()
      ✅ canTransition(), getNextStatus(), getAvailableTransitions()
      ✅ getStatusLabel(), getStatusColor() helpers

  ☐ 11.4 Filament: Page validation client (Phase 5)
      ☐ Liste sessions pending_client_review
      ☐ Voir détails projet, pré-devis
      ☐ Boutons: Valider, Rejeter, Demander modifications

  ☐ 11.5 Filament: Page validation master (Phase 5)
      ☐ Liste sessions pending_master_review
      ☐ Voir projet anonymisé
      ☐ Valider/Rejeter avec commentaire
      ☐ Option: promouvoir en learned response

  ✅ 11.6 ProjectAnonymizer.php
      ✅ anonymize(session) → données complètes anonymisées
      ✅ anonymizeText() avec patterns regex
      ✅ Patterns: email, téléphone, IP, SIRET, SIREN
      ✅ Collecte et remplacement des noms propres
      ✅ anonymizeAddresses() heuristique adresses françaises
      ✅ containsSensitiveData(), getAnonymizationReport()
```

---

#### ✅ PHASE 5 : Marketplace

**Implémenté le 27 décembre 2025**

Fichiers créés:
- `database/migrations/2025_12_27_100004_create_marketplace_orders_table.php`
- `app/Models/MarketplaceOrder.php`
- `app/Models/MarketplaceOrderItem.php`
- `app/Models/MarketplaceShipment.php`
- `app/Services/Marketplace/SkuMatchingService.php`
- `app/Services/Marketplace/MatchResult.php`
- `app/Services/Marketplace/ProductCatalogInterface.php`
- `app/Services/Marketplace/InMemoryProductCatalog.php`
- `app/Services/Marketplace/SupplierInterface.php`
- `app/Services/Marketplace/SupplierOrderResult.php`
- `app/Services/Marketplace/SupplierOrderStatus.php`
- `app/Services/Marketplace/OrderDispatcher.php`
- `app/Services/Marketplace/DispatchResult.php`
- `app/Services/Marketplace/MockSupplier.php`
- `app/Http/Controllers/Api/Whitelabel/MarketplaceController.php`
- `app/Notifications/MarketplaceOrderCreatedNotification.php`
- `app/Notifications/MarketplaceOrderValidatedNotification.php`
- `app/Notifications/MarketplaceShipmentNotification.php`
- `app/Providers/MarketplaceServiceProvider.php`

Fichiers modifiés:
- `routes/api.php` (ajout routes /editor/marketplace/*)
- `bootstrap/providers.php` (enregistrement MarketplaceServiceProvider)

```
✅ 12. INTÉGRATION MARKETPLACE
  ✅ 12.1 POST /api/editor/marketplace/quote-signed
      ✅ Auth: API key éditeur (middleware editor.auth)
      ✅ Request: session_id, quote_reference, items[], delivery_address
      ✅ Valider session existe et appartient à l'éditeur
      ✅ Créer MarketplaceOrder + lancer SKU matching
      ✅ Response: order_id, status, matching stats

  ✅ 12.2 SkuMatchingService.php
      ✅ matchPreQuoteItems() avec seuils configurables
      ✅ Matching fuzzy par label (similar_text + keywords)
      ✅ Matching exact par SKU (regex patterns)
      ✅ MatchResult avec matched/partial/unmatched
      ✅ createOrderItems() pour créer lignes commande

  ✅ 12.3 ProductCatalogInterface (abstraction)
      ✅ findBySku(), searchByLabel(), checkAvailability()
      ✅ InMemoryProductCatalog (mock pour tests/dev)
      ✅ Catalogue pré-rempli avec produits peinture/placo

  ✅ 12.4 MarketplaceOrder model + migration
      ✅ Tables: marketplace_orders, marketplace_order_items, marketplace_shipments
      ✅ Status: pending_validation, validated, processing, ordered, shipped, delivered, cancelled
      ✅ Match status items: matched, partial_match, not_found, manual
      ✅ Méthodes: createFromPreQuote(), validate(), cancel(), recalculateTotals()
      ✅ toApiArray() pour sérialisation API

  ✅ 12.5 Notification artisan
      ✅ MarketplaceOrderCreatedNotification (mail + database)
      ✅ MarketplaceOrderValidatedNotification (mail + database)
      ✅ MarketplaceShipmentNotification (shipped, delivered, failed)
      ✅ Queued pour envoi async

  ✅ 12.6 API commandes marketplace
      ✅ GET /orders - lister commandes par éditeur/artisan
      ✅ GET /orders/{id} - détails commande
      ✅ POST /orders/{id}/validate - valider commande
      ✅ POST /orders/{id}/cancel - annuler commande
      ✅ PATCH /orders/{id}/items/{itemId} - modifier item

  ✅ 12.7 Intégration fournisseurs (abstraction)
      ✅ SupplierInterface avec toutes méthodes requises
      ✅ SupplierOrderResult, SupplierOrderStatus classes
      ✅ OrderDispatcher pour router vers fournisseurs
      ✅ DispatchResult avec stats multi-fournisseurs
      ✅ MockSupplier pour tests (simulation complète)
      ✅ syncShipmentStatuses() pour mise à jour async

  ☐ 12.8 UI artisan: valider commande (Filament - futur)
      ☐ Liste commandes pending_validation
      ☐ Voir produits matchés/non matchés
      ☐ Sélection manuelle produits non trouvés
      ☐ Modifier quantités si besoin
      ☐ Confirmer/annuler commande
```

---

### 16.5 Points d'Attention Critiques

```
⚠️ SÉCURITÉ
  • API keys clients: toujours hasher/encrypter en DB
  • Deployment keys: préfixer (dpl_) pour identification
  • Webhooks: TOUJOURS vérifier signature HMAC côté client
  • CORS: ne jamais retourner Access-Control-Allow-Origin: *
  • Upload: valider MIME type côté serveur (pas juste extension)
  • iframe: valider origin dans postMessage
  • Tokens session: signer avec HMAC, expiration

⚠️ PERFORMANCE
  • Rate limiting: utiliser Redis (pas file cache)
  • Compteurs: incréments atomiques (DB::raw ou Redis)
  • Webhooks: toujours async (queue jobs)
  • Branding: cacher le résultat résolu (cache 5min)
  • Uploads: streaming vers S3 (pas de stockage local temp)

⚠️ UX
  • Widget: tester sur mobile (touch, clavier virtuel)
  • Upload: feedback progress en temps réel
  • Erreurs: messages clairs en français
  • Branding: prévisualisation avant save

⚠️ TESTS
  • Middleware domaine: tester wildcards (*.example.com)
  • Webhook retry: simuler échecs réseau
  • Branding cascade: tester tous les cas (null values)
  • Structured output: tester JSON malformé
```

---

### 16.6 Ordre d'Implémentation Recommandé

```
Semaine 1 (Phase 1)
├── Jour 1-2: Migrations + Models
├── Jour 3-4: Filament Resources
└── Jour 5: Middlewares sécurité

Semaine 2 (Phase 2)
├── Jour 1-2: API endpoints
├── Jour 3-4: Widget JS + iframe
└── Jour 5: BrandingResolver + tests

Semaine 3 (Phase 3)
├── Jour 1-2: Upload fichiers
├── Jour 3-4: Webhooks système
└── Jour 5: Tests intégration

Semaine 4 (Phase 4 + Buffer)
├── Jour 1-2: Structured output
├── Jour 3-4: Workflow validation
└── Jour 5: Bug fixes, polish

→ Démo client possible fin semaine 3
→ Production ready fin semaine 4
```

---

## 17. RÉVISION : Architecture Marketplace Centralisée

> **Date révision** : Décembre 2025
> **Changement majeur** : Tous les acteurs sont des utilisateurs avec des rôles spécifiques

### 17.1 Principe Fondamental

**AVANT** : Table `clients` séparée pour les éditeurs whitelabel
**APRÈS** : Tous les acteurs dans la table `users` avec des rôles marketplace

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    ARCHITECTURE MARKETPLACE CENTRALISÉE                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  TOUS LES ACTEURS = USERS avec RÔLES                                        │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │                           TABLE: users                                  ││
│  │                                                                         ││
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐    ││
│  │  │  FABRICANT  │  │   ARTISAN   │  │   EDITEUR   │  │ PARTICULIER │    ││
│  │  │             │  │             │  │             │  │             │    ││
│  │  │ Weber,      │  │ Agents IA   │  │ EBP, SAGE,  │  │ Demandeurs  │    ││
│  │  │ Porcelanosa │  │ Devis/Fact. │  │ Logiciels   │  │ de devis    │    ││
│  │  │ Grohe...    │  │ Commandes   │  │ tierces     │  │ (clients)   │    ││
│  │  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘    ││
│  │                                                                         ││
│  │  + Rôles existants : super-admin, admin, metreur, validator             ││
│  │                                                                         ││
│  └─────────────────────────────────────────────────────────────────────────┘│
│                                                                             │
│  RELATIONS ENTRE ACTEURS                                                    │
│                                                                             │
│  ┌─────────────┐         ┌─────────────┐         ┌─────────────┐           │
│  │   EDITEUR   │◄───────►│   ARTISAN   │◄───────►│ PARTICULIER │           │
│  │   (EBP)     │  user_  │  (Durant)   │ sessions│  (Martin)   │           │
│  └─────────────┘  editor │             │         └─────────────┘           │
│        │         _links  └─────────────┘                                    │
│        │                        │                                           │
│        ▼                        ▼                                           │
│  ┌─────────────┐         ┌─────────────┐                                   │
│  │ Deployments │         │ FABRICANT   │ ◄─── Commandes matériaux          │
│  │ (agents)    │         │ (Weber...)  │                                   │
│  └─────────────┘         └─────────────┘                                   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 17.2 Acteurs de la Marketplace

| Rôle | Description | Fonctionnalités | Exemples |
|------|-------------|-----------------|----------|
| **fabricant** | Fabricant de matériaux B2B | Catalogue produits, gestion commandes, expéditions | Weber, Porcelanosa, Grohe, Knauf |
| **artisan** | Professionnel du BTP | Agents IA, devis/factures, commande matériaux | Durant Peinture |
| **editeur** | Éditeur logiciel tiers | Déploiement agents whitelabel, API, webhooks | EBP, SAGE |
| **particulier** | Client final | Demande de devis, chat avec agent | M. Martin |
| **metreur** | Validateur technique | Validation pré-devis, promotion learned | Expert interne |
| **admin** | Administrateur plateforme | Gestion agents, utilisateurs, stats | Équipe interne |

> ⚠️ **Distinction importante** : Les **fabricants** (Weber, Porcelanosa) produisent les matériaux.
> Les **négociants** (Point.P, BigMat) les revendent → Hors scope initial de la marketplace.

### 17.3 Tables Révisées

#### SUPPRIMÉ : Table `clients`
→ Remplacée par users avec role `editeur`

#### RENOMMÉ : `user_tenant_links` → `user_editor_links`

```sql
-- Lie un artisan à un éditeur (EBP peut avoir N artisans)
CREATE TABLE user_editor_links (
    id              BIGSERIAL PRIMARY KEY,

    -- L'artisan (user avec role artisan)
    artisan_id      BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,

    -- L'éditeur (user avec role editeur)
    editor_id       BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,

    -- ID de l'artisan dans le système de l'éditeur
    external_id     VARCHAR(100) NOT NULL,  -- "DUR-001" chez EBP

    -- Branding spécifique pour cet éditeur (override user.branding)
    branding        JSONB NULL,

    -- Permissions spécifiques chez cet éditeur
    permissions     JSONB NULL,

    is_active       BOOLEAN DEFAULT TRUE,
    linked_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE(artisan_id, editor_id),
    UNIQUE(editor_id, external_id)
);

CREATE INDEX idx_editor_links_artisan ON user_editor_links(artisan_id);
CREATE INDEX idx_editor_links_editor ON user_editor_links(editor_id);
CREATE INDEX idx_editor_links_external ON user_editor_links(external_id);
```

#### MODIFIÉ : `agent_deployments`

```sql
-- AVANT: client_id BIGINT REFERENCES clients(id)
-- APRÈS:
ALTER TABLE agent_deployments
    RENAME COLUMN client_id TO editor_id;
-- editor_id = user avec role 'editeur' qui déploie cet agent
```

#### MODIFIÉ : `ai_sessions`

```sql
ALTER TABLE ai_sessions
    ADD COLUMN editor_link_id BIGINT NULL REFERENCES user_editor_links(id),
    ADD COLUMN deployment_id BIGINT NULL REFERENCES agent_deployments(id),
    ADD COLUMN particulier_id BIGINT NULL REFERENCES users(id);
-- user_id existant = l'artisan (si session liée à un artisan)
-- particulier_id = le client final (M. Martin)
-- editor_link_id = le lien artisan↔éditeur utilisé (si via éditeur)
-- deployment_id = le déploiement utilisé

CREATE INDEX idx_sessions_editor_link ON ai_sessions(editor_link_id);
CREATE INDEX idx_sessions_deployment ON ai_sessions(deployment_id);
CREATE INDEX idx_sessions_particulier ON ai_sessions(particulier_id);
```

#### MODIFIÉ : `users`

```sql
-- Colonnes à ajouter à la table users existante
ALTER TABLE users ADD COLUMN company_name VARCHAR(255) NULL;
-- Nom de l'entreprise (pour artisans, éditeurs, fabricants)

ALTER TABLE users ADD COLUMN company_info JSONB NULL;
-- {
--   "siret": "12345678901234",
--   "address": "12 rue des Artisans, 75011 Paris",
--   "phone": "01 23 45 67 89",
--   "website": "https://durant-peinture.fr"
-- }

ALTER TABLE users ADD COLUMN branding JSONB NULL;
-- Branding par défaut (pour artisans principalement)
-- {
--   "welcome_message": "Bonjour, je suis l'assistant de {user.company_name}",
--   "primary_color": "#E53935",
--   "logo_url": "https://...",
--   "signature": "L'équipe Durant Peinture"
-- }

ALTER TABLE users ADD COLUMN marketplace_enabled BOOLEAN DEFAULT FALSE;
-- Accès marketplace activé

ALTER TABLE users ADD COLUMN api_key VARCHAR(100) NULL UNIQUE;
ALTER TABLE users ADD COLUMN api_key_prefix VARCHAR(10) NULL;
-- Pour les éditeurs et fabricants qui ont besoin d'accès API

-- Quotas et limites (pour éditeurs)
ALTER TABLE users ADD COLUMN max_deployments INTEGER NULL;
ALTER TABLE users ADD COLUMN max_sessions_month INTEGER NULL;
ALTER TABLE users ADD COLUMN max_messages_month INTEGER NULL;
ALTER TABLE users ADD COLUMN current_month_sessions INTEGER DEFAULT 0;
ALTER TABLE users ADD COLUMN current_month_messages INTEGER DEFAULT 0;
```

### 17.4 Rôles et Permissions Marketplace

```php
// À ajouter au RolePermissionSeeder existant

// ═══════════════════════════════════════════════════════════════════
// NOUVELLES PERMISSIONS
// ═══════════════════════════════════════════════════════════════════

$newPermissions = [
    // Marketplace
    ['name' => 'Accès marketplace', 'slug' => 'marketplace.access', 'group_name' => 'marketplace'],
    ['name' => 'Gérer catalogue', 'slug' => 'catalog.manage', 'group_name' => 'marketplace'],

    // Commandes
    ['name' => 'Voir commandes', 'slug' => 'orders.view', 'group_name' => 'orders'],
    ['name' => 'Voir ses commandes', 'slug' => 'orders.view_own', 'group_name' => 'orders'],
    ['name' => 'Créer commande', 'slug' => 'orders.create', 'group_name' => 'orders'],
    ['name' => 'Traiter commandes', 'slug' => 'orders.process', 'group_name' => 'orders'],
    ['name' => 'Gérer livraisons', 'slug' => 'deliveries.manage', 'group_name' => 'orders'],

    // Devis
    ['name' => 'Créer devis', 'slug' => 'quotes.create', 'group_name' => 'quotes'],
    ['name' => 'Voir ses devis', 'slug' => 'quotes.view_own', 'group_name' => 'quotes'],

    // Déploiements whitelabel
    ['name' => 'Gérer déploiements', 'slug' => 'deployments.manage', 'group_name' => 'whitelabel'],
    ['name' => 'Gérer domaines', 'slug' => 'domains.manage', 'group_name' => 'whitelabel'],
    ['name' => 'Lier artisans', 'slug' => 'artisans.link', 'group_name' => 'whitelabel'],
    ['name' => 'Voir artisans liés', 'slug' => 'artisans.view', 'group_name' => 'whitelabel'],
    ['name' => 'Créer liens session', 'slug' => 'sessions.create_link', 'group_name' => 'whitelabel'],
    ['name' => 'Gérer branding', 'slug' => 'branding.manage', 'group_name' => 'whitelabel'],

    // Sessions IA (compléments)
    ['name' => 'Créer session', 'slug' => 'ai-sessions.create', 'group_name' => 'ai'],
    ['name' => 'Voir ses sessions', 'slug' => 'ai-sessions.view_own', 'group_name' => 'ai'],
    ['name' => 'Participer session', 'slug' => 'ai-sessions.participate', 'group_name' => 'ai'],

    // Fichiers
    ['name' => 'Uploader fichiers', 'slug' => 'files.upload', 'group_name' => 'files'],

    // Stats
    ['name' => 'Voir statistiques', 'slug' => 'stats.view', 'group_name' => 'stats'],
];

// ═══════════════════════════════════════════════════════════════════
// NOUVEAUX RÔLES MARKETPLACE
// ═══════════════════════════════════════════════════════════════════

$marketplaceRoles = [
    [
        'name' => 'Fabricant',
        'slug' => 'fabricant',
        'description' => 'Fabricant de matériaux B2B sur la marketplace',
        'is_system' => true,
        'permissions' => [
            'marketplace.access',
            'catalog.manage',
            'orders.view',
            'orders.process',
            'deliveries.manage',
            'api.access',
        ],
    ],
    [
        'name' => 'Artisan',
        'slug' => 'artisan',
        'description' => 'Professionnel BTP - Agents IA, devis, commandes',
        'is_system' => true,
        'permissions' => [
            'agents.view',
            'ai-sessions.create',
            'ai-sessions.view_own',
            'files.upload',
            'quotes.create',
            'quotes.view_own',
            'orders.create',
            'orders.view_own',
            'marketplace.access',
        ],
    ],
    [
        'name' => 'Éditeur',
        'slug' => 'editeur',
        'description' => 'Éditeur logiciel tiers (intégration whitelabel)',
        'is_system' => true,
        'permissions' => [
            'deployments.manage',
            'domains.manage',
            'artisans.link',
            'artisans.view',
            'sessions.create_link',
            'webhooks.manage',
            'stats.view',
            'api.access',
            'branding.manage',
        ],
    ],
    [
        'name' => 'Particulier',
        'slug' => 'particulier',
        'description' => 'Client final demandeur de devis',
        'is_system' => true,
        'permissions' => [
            'ai-sessions.participate',
            'files.upload',
            'quotes.view_own',
        ],
    ],
];
```

### 17.5 Cas Concret Révisé : Expert BTP via EBP

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        PARCOURS COMPLET - VERSION MARKETPLACE               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ACTEURS (tous dans table users) :                                          │
│  • EBP (role: editeur) = Éditeur logiciel                                   │
│  • Durant Peinture (role: artisan) = Artisan peintre                        │
│  • M. Martin (role: particulier) = Client final                             │
│  • Weber (role: fabricant) = Fabricant colles/enduits                       │
│  • Porcelanosa (role: fabricant) = Fabricant carrelage                      │
│  • Expert BTP = Agent IA déployé                                            │
│                                                                             │
│  ════════════════════════════════════════════════════════════════════════  │
│                                                                             │
│  0. SETUP (fait une fois)                                                   │
│     ┌─────────────┐                                                        │
│     │   ADMIN     │ Crée le user EBP avec role "editeur"                   │
│     │ (platform)  │ EBP crée un AgentDeployment de "Expert BTP"            │
│     └─────────────┘ Configure domaines autorisés (app.ebp.com)              │
│            │                                                                │
│            ▼                                                                │
│     ┌─────────────┐                                                        │
│     │   EBP       │ Lie l'artisan Durant à son compte                      │
│     │ (editeur)   │ POST /api/editor/artisans/link                         │
│     └─────────────┘ { email: "durant@...", external_id: "DUR-001" }        │
│                     → Crée user_editor_links                               │
│                                                                             │
│  ════════════════════════════════════════════════════════════════════════  │
│                                                                             │
│  1. INITIATION SESSION                                                      │
│     ┌─────────────┐                                                        │
│     │  Durant     │ Dans EBP, clique "Nouveau projet IA"                   │
│     │ (artisan)   │ → EBP appelle POST /api/editor/sessions/create-link    │
│     └──────┬──────┘ → Génère https://chat.ebp.com/s/abc123                 │
│            │                                                                │
│            │ Envoie le lien par email/SMS à son client                     │
│            ▼                                                                │
│     ┌─────────────┐                                                        │
│     │  M. Martin  │ Clique sur le lien                                     │
│     │(particulier)│ → Compte créé automatiquement ou session anonyme       │
│     └──────┬──────┘                                                        │
│            │                                                                │
│            ▼                                                                │
│  2. CONVERSATION IA (widget plein écran)                                    │
│     ┌─────────────────────────────────────────────────────────────────┐    │
│     │  🤖 "Bonjour, je suis l'assistant de Durant Peinture.          │    │
│     │      Pouvez-vous me décrire votre projet ?"                     │    │
│     │      [Branding = celui de Durant via EBP]                       │    │
│     │                                                                 │    │
│     │  👤 M. Martin : "Je souhaite refaire ma salle de bain..."      │    │
│     │                                                                 │    │
│     │  🤖 "Pouvez-vous m'envoyer quelques photos ?"                  │    │
│     │                                                                 │    │
│     │  👤 [📷 photo1.jpg] [📷 photo2.jpg]  ← Upload dans widget      │    │
│     │                                                                 │    │
│     │  🤖 "Voici un pré-devis estimatif :                           │    │
│     │      - Carrelage Porcelanosa 60x60 : 640€                      │    │
│     │      - Colle Weber flex : 85€                                   │    │
│     │      - Main d'œuvre : 1,200€                                    │    │
│     │      Total HT : 5,790€ / TTC : 6,948€                          │    │
│     │      ```json-quote { ... structured output ... } ```"          │    │
│     └─────────────────────────────────────────────────────────────────┘    │
│            │                                                                │
│            ▼                                                                │
│  3. WEBHOOK VERS EBP (automatique)                                          │
│     ┌─────────────────────────────────────────────────────────────────┐    │
│     │  POST https://api.ebp.com/webhooks/ai-manager                   │    │
│     │  {                                                              │    │
│     │    "event": "session.completed",                                │    │
│     │    "editor_id": "ebp-uuid",                                     │    │
│     │    "artisan": { "external_id": "DUR-001", "name": "Durant" },  │    │
│     │    "particulier": { "name": "M. Martin" },                      │    │
│     │    "project": { description, photos[], pre_quote{} },          │    │
│     │    "signature": "hmac_sha256..."                                │    │
│     │  }                                                              │    │
│     └─────────────────────────────────────────────────────────────────┘    │
│            │                                                                │
│            ▼                                                                │
│  4. VALIDATION (workflow configurable)                                      │
│     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐               │
│     │ Métreur EBP │────►│ Anonymise   │────►│ Métreur     │               │
│     │ valide      │     │ données     │     │ AI-Manager  │               │
│     └─────────────┘     └─────────────┘     └─────────────┘               │
│            │                                                                │
│            ▼                                                                │
│  5. DEVIS SIGNÉ → MARKETPLACE                                               │
│     ┌─────────────┐                                                        │
│     │ M. Martin   │ Signe le devis dans EBP                                │
│     │ signe devis │                                                        │
│     └──────┬──────┘                                                        │
│            │ EBP notifie: POST /api/integration/quote-signed               │
│            ▼                                                                │
│     ┌─────────────┐                                                        │
│     │  Durant     │ Reçoit notification "Devis signé !"                    │
│     │ (artisan)   │ Voit commande matériaux suggérée                       │
│     └──────┬──────┘                                                        │
│            │ Valide la commande matériaux                                  │
│            ▼                                                                │
│     ┌─────────────┐     ┌─────────────┐                                   │
│     │   Weber     │     │ Porcelanosa │                                   │
│     │ (fabricant) │     │ (fabricant) │                                   │
│     │ Reçoit cde  │     │ Reçoit cde  │                                   │
│     │ colle/enduit│     │ carrelage   │                                   │
│     └─────────────┘     └─────────────┘                                   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 17.6 Documentation API (Swagger)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         DOCUMENTATION API - SWAGGER                          │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Endpoints :                                                                 │
│  ├── GET  /api/docs              → Interface Swagger UI interactive         │
│  ├── GET  /api/docs/openapi.json → Spécification OpenAPI 3.0 (JSON)         │
│  └── GET  /api/docs/openapi.yaml → Spécification OpenAPI 3.0 (YAML)         │
│                                                                             │
│  ════════════════════════════════════════════════════════════════════════  │
│                                                                             │
│  Sections documentées :                                                      │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │ WIDGET API (public avec deployment_key)                                 ││
│  │ POST /api/widget/v1/init              Initialiser une session           ││
│  │ POST /api/widget/v1/message           Envoyer un message                ││
│  │ GET  /api/widget/v1/message/{id}/status  Statut d'un message            ││
│  │ POST /api/widget/v1/upload            Uploader un fichier               ││
│  │ GET  /api/widget/v1/session/{id}/messages  Historique messages          ││
│  └─────────────────────────────────────────────────────────────────────────┘│
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │ EDITOR API (role: editeur, auth: API Key)                               ││
│  │ POST /api/editor/artisans/link        Lier un artisan existant          ││
│  │ POST /api/editor/artisans/create-and-link  Créer et lier un artisan     ││
│  │ GET  /api/editor/artisans             Liste des artisans liés           ││
│  │ POST /api/editor/sessions/create-link Créer un lien de session          ││
│  │ GET  /api/editor/deployments          Ses déploiements                  ││
│  │ PUT  /api/editor/deployments/{id}     Modifier un déploiement           ││
│  │ GET  /api/editor/stats                Ses statistiques                  ││
│  │ POST /api/editor/webhooks             Configurer un webhook             ││
│  └─────────────────────────────────────────────────────────────────────────┘│
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │ ARTISAN API (role: artisan, auth: Bearer Token)                         ││
│  │ GET  /api/artisan/sessions            Ses sessions                      ││
│  │ POST /api/artisan/quotes              Créer un devis                    ││
│  │ GET  /api/artisan/orders              Ses commandes matériaux           ││
│  │ POST /api/artisan/orders              Commander matériaux               ││
│  │ GET  /api/artisan/editors             Éditeurs auxquels il est lié      ││
│  └─────────────────────────────────────────────────────────────────────────┘│
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │ FABRICANT API (role: fabricant, auth: API Key)                          ││
│  │ GET  /api/fabricant/orders            Commandes reçues                  ││
│  │ PUT  /api/fabricant/orders/{id}       Mettre à jour statut commande     ││
│  │ GET  /api/fabricant/catalog           Son catalogue produits            ││
│  │ POST /api/fabricant/catalog           Ajouter un produit                ││
│  │ PUT  /api/fabricant/catalog/{id}      Modifier un produit               ││
│  └─────────────────────────────────────────────────────────────────────────┘│
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │ INTEGRATION API (webhooks entrants)                                     ││
│  │ POST /api/integration/quote-signed    Devis signé (depuis éditeur)      ││
│  └─────────────────────────────────────────────────────────────────────────┘│
│                                                                             │
│  ════════════════════════════════════════════════════════════════════════  │
│                                                                             │
│  Package : darkaonline/l5-swagger                                           │
│  └── Génération automatique depuis annotations PHP (OpenAPI 3.0)            │
│                                                                             │
│  Configuration : config/l5-swagger.php                                      │
│  └── Titre, version, serveurs, sécurité (API Key, Bearer Token)             │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 17.7 Checklist Révisée Phase 1

> **Statut** : ✅ PHASE 1 TERMINÉE (27 décembre 2025)

```
✅ 1. MIGRATIONS (révisées) → COMPLÉTÉ
  ✅ 1.1 create_agent_deployments_table
      ✅ editor_id (FK users) au lieu de client_id
      ✅ Reste identique sinon
      → Fichier: 2025_12_27_000001_create_agent_deployments_table.php

  ✅ 1.2 create_allowed_domains_table
      ✅ Identique au CDC original
      → Fichier: 2025_12_27_000002_create_allowed_domains_table.php

  ✅ 1.3 create_user_editor_links_table (ex user_tenant_links)
      ✅ artisan_id, editor_id, external_id
      ✅ branding, permissions (JSONB)
      ✅ is_active, linked_at
      → Fichier: 2025_12_27_000003_create_user_editor_links_table.php

  ✅ 1.4 modify_users_table
      ✅ ADD company_name VARCHAR(255) NULL
      ✅ ADD company_info JSONB NULL
      ✅ ADD branding JSONB NULL
      ✅ ADD marketplace_enabled BOOLEAN DEFAULT FALSE
      ✅ ADD api_key VARCHAR(100) NULL UNIQUE
      ✅ ADD api_key_prefix VARCHAR(10) NULL
      ✅ ADD max_deployments, max_sessions_month, max_messages_month
      ✅ ADD current_month_sessions, current_month_messages
      → Fichier: 2025_12_27_000004_add_marketplace_columns_to_users_table.php

  ✅ 1.5 modify_ai_sessions_table
      ✅ ADD editor_link_id (FK user_editor_links)
      ✅ ADD deployment_id (FK agent_deployments)
      ✅ ADD particulier_id (FK users)
      ✅ user_id existant = l'artisan
      → Fichier: 2025_12_27_000005_add_whitelabel_columns_to_ai_sessions_table.php

  ✅ 1.6 modify_agents_table
      ✅ ADD deployment_mode VARCHAR(20) DEFAULT 'internal'
      ✅ ADD is_whitelabel_enabled BOOLEAN DEFAULT FALSE
      ✅ ADD whitelabel_config JSONB NULL
      → Fichier: 2025_12_27_000006_add_whitelabel_columns_to_agents_table.php

✅ 2. MODELS → COMPLÉTÉ
  ✅ 2.1 AgentDeployment.php (NOUVEAU)
      ✅ editor() belongsTo User
      ✅ agent() belongsTo Agent
      ✅ allowedDomains() hasMany
      ✅ sessions() hasMany
      ✅ generateDeploymentKey(), isDomainAllowed(), resolveConfig()

  ✅ 2.2 AllowedDomain.php (NOUVEAU)
      ✅ deployment() belongsTo
      ✅ matches(string $host): bool (wildcards supportés)

  ✅ 2.3 UserEditorLink.php (NOUVEAU)
      ✅ artisan() belongsTo User
      ✅ editor() belongsTo User
      ✅ sessions() hasMany AiSession
      ✅ hasPermission(), resolveBranding()

  ✅ 2.4 Modifier User.php
      ✅ editorLinks() hasMany (en tant qu'artisan)
      ✅ linkedArtisans() hasMany (en tant qu'éditeur)
      ✅ deployments() hasMany (en tant qu'éditeur)
      ✅ isArtisan(), isEditeur(), isFabricant(), isParticulier()
      ✅ generateApiKey(), hasSessionQuotaRemaining()

  ✅ 2.5 Modifier AiSession.php
      ✅ editorLink() belongsTo
      ✅ deployment() belongsTo
      ✅ particulier() belongsTo User
      ✅ isWhitelabelSession(), getArtisan(), resolveBranding()

  ✅ 2.6 Modifier Agent.php
      ✅ deployments() hasMany
      ✅ isWhitelabelEnabled(), isSharedMode(), isDedicatedMode()
      ✅ getDefaultBranding(), allowsPromptOverride()

✅ 3. SEEDER RÔLES MARKETPLACE → COMPLÉTÉ
  ✅ 3.1 Nouvelles permissions (marketplace.*, orders.*, quotes.*, whitelabel.*, files.*, stats.*)
  ✅ 3.2 Rôle fabricant
  ✅ 3.3 Rôle artisan
  ✅ 3.4 Rôle editeur
  ✅ 3.5 Rôle particulier
  → Fichier: database/seeders/MarketplaceRolesSeeder.php

✅ 4. FILAMENT RESOURCES → COMPLÉTÉ
  ✅ 4.1 AgentDeploymentResource (NOUVEAU)
      ✅ Table: colonnes (agent, editor, mode, domains, sessions, status)
      ✅ Form: Tabs (Info, Domaines, Quotas, Branding, Config avancée)
      ✅ Actions: Copier clé, Régénérer clé
      → Fichier: app/Filament/Resources/AgentDeploymentResource.php

  ✅ 4.2 UserEditorLinkResource (NOUVEAU)
      ✅ Table: colonnes (artisan, editor, external_id, sessions, status)
      ✅ Form: Liaison, Branding, Permissions
      → Fichier: app/Filament/Resources/UserEditorLinkResource.php

  ✅ 4.3 Modifier UserResource
      ✅ Tabs: Informations, Entreprise, Branding, API & Quotas
      ✅ Actions: Générer API Key, RAZ compteurs

  ✅ 4.4 Modifier AgentResource
      ✅ Tab Whitelabel: config, branding défaut, permissions éditeurs

  ✅ 4.5 Modifier AiSessionResource
      ✅ Colonnes: deployment, editor, artisan
      ✅ Filtres: whitelabel_only, deployment

✅ 5. MIDDLEWARES SÉCURITÉ → COMPLÉTÉ
  ✅ 5.1 ValidateDeploymentKey.php
      ✅ Extrait deployment_key (header X-Deployment-Key ou query)
      ✅ Cache 5 minutes
      ✅ Injecte deployment dans request->attributes

  ✅ 5.2 ValidateDeploymentDomain.php
      ✅ Extrait Origin/Referer
      ✅ Vérifie isDomainAllowed()
      ✅ Support localhost en dev

  ✅ 5.3 RateLimitDeployment.php
      ✅ Rate limit par IP (Redis/cache)
      ✅ Rate limit global déploiement
      ✅ Headers X-RateLimit-*

  ✅ 5.4 CheckEditorQuota.php
      ✅ Vérifie quotas mensuels éditeur
      ✅ Vérifie quotas journaliers déploiement

  ✅ 5.5 DynamicCors.php
      ✅ CORS dynamique basé sur allowed_domains
      ✅ Gère preflight OPTIONS

  ✅ 5.6 EditorApiAuth.php
      ✅ Auth via API key (Bearer ou X-API-Key)
      ✅ Rate limiting éditeur

  → Enregistrés dans bootstrap/app.php avec alias:
    deployment.key, deployment.domain, deployment.rate, deployment.cors
    editor.quota, editor.auth, partner.auth

✅ 6. SWAGGER → COMPLÉTÉ
  ✅ 6.1 composer require darkaonline/l5-swagger
  ✅ 6.2 config/l5-swagger.php créé
  ✅ 6.3 Annotations OpenAPI créées
      → app/OpenApi/OpenApiSpec.php (schemas de base)
      → app/OpenApi/WhitelabelEndpoints.php (endpoints documentés)
```

### 17.8 Processus de Développement

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    PROCESSUS DE DÉVELOPPEMENT PAR PHASE                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Pour chaque PHASE :                                                         │
│                                                                             │
│  1. DÉVELOPPER                                                               │
│     └── Suivre les todos de la checklist CDC (section 16.4 + 17.7)          │
│                                                                             │
│  2. VÉRIFIER vs CAS CONCRET (section 17.5)                                   │
│     ├── EBP (editeur) peut-il créer un déploiement ?                        │
│     ├── Durant (artisan) peut-il être lié à EBP ?                           │
│     ├── M. Martin (particulier) peut-il utiliser le widget ?                │
│     ├── Les webhooks fonctionnent-ils vers EBP ?                            │
│     └── Weber/Porcelanosa (fabricants) reçoivent-ils les commandes ?        │
│                                                                             │
│  3. CORRIGER si le cas concret n'est pas réalisable                         │
│     └── Ajuster le code jusqu'à validation                                  │
│                                                                             │
│  4. PASSER à la phase suivante                                               │
│     └── Seulement quand la vérification est OK                              │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

**Fin du document**

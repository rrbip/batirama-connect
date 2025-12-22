# AI-Manager CMS - Documentation Technique

> **Version** : 1.0.0
> **Date** : Décembre 2025
> **Statut** : Spécifications validées

---

## Vue d'Ensemble

AI-Manager CMS est une plateforme Laravel permettant de piloter des agents IA locaux via une interface d'administration. L'objectif principal est de permettre la création et la gestion d'experts IA (BTP, Support, Litige, etc.) **sans toucher au code**.

### Objectifs Clés

1. **Administration No-Code** : Créer des agents IA configurables via le back-office
2. **RAG Hybride** : Récupération intelligente combinant recherche sémantique et hydratation SQL
3. **Apprentissage Continu** : Amélioration des réponses via validation humaine
4. **Multi-tenant Ready** : Architecture préparée pour la marque blanche
5. **Écosystème Intégrable** : API REST pour authentification et webhooks

### Contexte d'Intégration

Cette plateforme s'intègre dans un écosystème plus large comprenant :
- Un site internet avec gestion d'articles
- Une marketplace de fournitures BTP
- Un logiciel de devis/factures métier BTP
- Des logiciels tiers en marque blanche

```
┌─────────────────────────────────────────────────────────────────┐
│                      ÉCOSYSTÈME BATIRAMA                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐  │
│  │  Site Web    │  │ Marketplace  │  │ Logiciel Devis/Fact  │  │
│  │  (Articles)  │  │    (BTP)     │  │      (SaaS)          │  │
│  └──────┬───────┘  └──────┬───────┘  └──────────┬───────────┘  │
│         │                 │                      │              │
│         └────────────┬────┴──────────────────────┘              │
│                      │                                          │
│              ┌───────▼───────┐                                  │
│              │ AI-Manager CMS │◄─── Logiciels Tiers            │
│              │   (Ce projet)  │     (Marque Blanche)           │
│              └───────┬───────┘                                  │
│                      │                                          │
│         ┌────────────┼────────────┐                            │
│         ▼            ▼            ▼                            │
│    ┌─────────┐  ┌─────────┐  ┌─────────┐                       │
│    │Agent BTP│  │ Agent   │  │ Agent   │                       │
│    │(Ouvrages)│  │ Support │  │ Litige  │                       │
│    └─────────┘  └─────────┘  └─────────┘                       │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Stack Technique

### Versions Validées (Décembre 2025)

| Composant | Version | Rôle |
|-----------|---------|------|
| **Laravel** | 12.x | Framework backend principal |
| **PHP** | 8.4 | Runtime serveur |
| **Livewire** | 3.6.x | Composants réactifs sans JS |
| **PostgreSQL** | 17 | Base de données relationnelle |
| **Redis** | 7.4+ | Cache et files d'attente (optionnel en dev) |
| **Qdrant** | 1.16.x | Base vectorielle pour RAG |
| **Ollama** | 0.13.x | Serveur d'inférence IA local |
| **Caddy** | 2.10.x | Reverse proxy avec HTTPS auto |
| **PHPUnit** | 11.x | Tests unitaires |

### Modèles IA Recommandés

| Modèle | Paramètres | Usage Recommandé | RAM Requise |
|--------|------------|------------------|-------------|
| `llama3.3:70b` | 70B | BTP complexe, analyse technique | 64GB+ |
| `mistral-small` | 24B | Support rapide, Q&A général | 32GB |
| `mistral:7b` | 7B | Développement, tests | 8GB |
| `nomic-embed-text` | - | Génération d'embeddings | 4GB |

---

## Architecture des Services

```
┌─────────────────────────────────────────────────────────────────┐
│                         DOCKER NETWORK                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────┐     ┌─────────┐     ┌─────────┐     ┌─────────┐   │
│  │  Caddy  │────▶│   App   │────▶│ Postgres│     │  Redis  │   │
│  │  :80    │     │ PHP-FPM │     │  :5432  │     │  :6379  │   │
│  │  :443   │     │  :9000  │     └─────────┘     └─────────┘   │
│  └─────────┘     └────┬────┘                                    │
│                       │                                         │
│              ┌────────┴────────┐                                │
│              ▼                 ▼                                │
│        ┌─────────┐       ┌─────────┐                           │
│        │ Qdrant  │       │ Ollama  │◄── GPU (optionnel)        │
│        │  :6333  │       │ :11434  │                           │
│        └─────────┘       └─────────┘                           │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Glossaire Technique

### Termes IA & NLP

| Terme | Définition |
|-------|------------|
| **Agent** | Entité IA configurée pour un domaine spécifique (BTP, Support, etc.) |
| **RAG** | Retrieval-Augmented Generation - Technique combinant recherche documentaire et génération IA |
| **Embedding** | Représentation vectorielle d'un texte (généralement 768-4096 dimensions) |
| **System Prompt** | Instructions données à l'IA définissant son comportement et sa personnalité |
| **Context Window** | Nombre maximum de tokens que le modèle peut traiter en une requête |
| **Token** | Unité de texte (~0.75 mot en français) |
| **Hydratation** | Enrichissement des données vectorielles avec des données SQL structurées |
| **Collection** | Groupe de vecteurs dans Qdrant, équivalent à une table |
| **Payload** | Métadonnées associées à un vecteur dans Qdrant |

### Termes Métier BTP

| Terme | Définition |
|-------|------------|
| **Ouvrage** | Élément de construction pouvant être simple ou composé |
| **Ouvrage Composé** | Ensemble d'ouvrages simples formant une prestation complète |
| **Fourniture** | Matériau ou produit utilisé dans un ouvrage |
| **Main d'Œuvre (MO)** | Temps de travail nécessaire à la réalisation d'un ouvrage |
| **Prix Unitaire** | Prix d'une unité d'ouvrage (m², ml, U, etc.) |

### Termes Techniques

| Terme | Définition |
|-------|------------|
| **Webhook** | Callback HTTP déclenché par un événement |
| **Marque Blanche** | Produit redistribué sous une autre marque |
| **Multi-tenant** | Architecture où une instance sert plusieurs organisations |
| **FIFO** | First In, First Out - Ordre de traitement des files d'attente |

---

## Structure du Projet

```
batirama-connect/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/           # Contrôleurs back-office
│   │   │   ├── Api/             # Contrôleurs API REST
│   │   │   └── AI/              # Contrôleurs interface IA
│   │   └── Middleware/
│   ├── Livewire/
│   │   ├── Admin/               # Composants admin
│   │   └── AI/                  # Composants chat IA
│   ├── Models/
│   │   ├── Agent.php
│   │   ├── AiSession.php
│   │   ├── AiMessage.php
│   │   ├── Ouvrage.php
│   │   └── ...
│   ├── Services/
│   │   ├── AI/
│   │   │   ├── OllamaService.php       # Client Ollama
│   │   │   ├── QdrantService.php       # Client Qdrant
│   │   │   ├── EmbeddingService.php    # Génération embeddings
│   │   │   ├── PromptBuilder.php       # Construction des prompts
│   │   │   ├── RagService.php          # Orchestration RAG
│   │   │   └── DispatcherService.php   # Routage vers agents
│   │   ├── Import/
│   │   │   ├── CsvImporter.php
│   │   │   ├── JsonImporter.php
│   │   │   └── DatabaseImporter.php
│   │   └── Webhook/
│   │       └── WebhookDispatcher.php
│   └── Jobs/
│       ├── ProcessAiMessage.php
│       ├── IndexDocumentJob.php
│       └── SyncMarketplaceJob.php
├── config/
│   ├── ai.php                   # Configuration IA (Ollama, modèles)
│   ├── qdrant.php               # Configuration Qdrant
│   └── services.php             # Configuration services externes
├── database/
│   ├── migrations/
│   └── seeders/
├── docker/
│   ├── app/
│   │   └── Dockerfile
│   ├── caddy/
│   │   └── Caddyfile
│   └── ollama/
│       └── Dockerfile
├── docs/                        # Cette documentation
├── resources/
│   └── views/
│       ├── livewire/
│       └── components/
├── routes/
│   ├── web.php
│   ├── api.php
│   └── admin.php
├── tests/
│   ├── Unit/
│   └── Feature/
├── docker-compose.yml
├── docker-compose.dev.yml
├── docker-compose.prod.yml
└── .env.example
```

---

## Documents de Référence

| Document | Description |
|----------|-------------|
| [01_infrastructure.md](./01_infrastructure.md) | Configuration Docker, services et déploiement |
| [02_database_schema.md](./02_database_schema.md) | Schémas PostgreSQL et collections Qdrant |
| [03_ai_core_logic.md](./03_ai_core_logic.md) | Logique du Dispatcher, RAG et apprentissage |
| [04_partners_api.md](./04_partners_api.md) | API Partenaires (ZOOMBAT, EBP, etc.) et intégrations |

---

## Conventions de Code

### Nommage

```php
// Classes : PascalCase
class OllamaService {}
class ProcessAiMessage {}

// Méthodes : camelCase
public function generateEmbedding(string $text): array {}

// Variables : camelCase
$agentConfig = [];
$systemPrompt = '';

// Constantes : SCREAMING_SNAKE_CASE
const RETRIEVAL_MODE_TEXT_ONLY = 'TEXT_ONLY';
const RETRIEVAL_MODE_SQL_HYDRATION = 'SQL_HYDRATION';

// Tables : snake_case, pluriel
agents, ai_sessions, ai_messages, ouvrages

// Colonnes : snake_case
system_prompt, qdrant_collection, created_at
```

### Standards

- **PSR-12** : Standard de codage PHP
- **Laravel Best Practices** : Conventions Laravel officielles
- **PHPDoc** : Documentation des méthodes publiques
- **Strict Types** : `declare(strict_types=1);` dans tous les fichiers

---

## Configuration Environnement

### Variables d'Environnement Clés

```env
# Application
APP_NAME="AI-Manager CMS"
APP_ENV=local
APP_DEBUG=true

# Base de données
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=ai_manager
DB_USERNAME=postgres
DB_PASSWORD=secret

# Redis (optionnel en dev)
REDIS_ENABLED=false
REDIS_HOST=redis
REDIS_PORT=6379

# Qdrant
QDRANT_HOST=qdrant
QDRANT_PORT=6333
QDRANT_API_KEY=

# Ollama
OLLAMA_HOST=ollama
OLLAMA_PORT=11434
OLLAMA_DEFAULT_MODEL=mistral:7b
OLLAMA_EMBEDDING_MODEL=nomic-embed-text

# Files d'attente
QUEUE_CONNECTION=database  # 'redis' en production si activé

# Webhooks
WEBHOOK_SECRET=your-webhook-secret
WEBHOOK_TIMEOUT=30
```

---

## Roadmap Fonctionnelle

### Phase 1 : Core (Ce projet)
- [x] Infrastructure Docker
- [x] Système d'agents dynamiques
- [x] Moteur RAG hybride
- [x] Interface d'apprentissage
- [x] Gestion des ouvrages BTP

### Phase 2 : Intégration (Future)
- [ ] API authentification OAuth2
- [ ] Webhooks marketplace
- [ ] Connecteur logiciel devis/factures
- [ ] Multi-tenant complet

### Phase 3 : Scale (Future)
- [ ] Haute disponibilité
- [ ] Réplication Qdrant
- [ ] Load balancing Ollama
- [ ] Métriques avancées

---

## Contacts & Support

- **Documentation** : Ce dossier `docs/`
- **Issues** : GitHub Repository
- **API Reference** : `/api/documentation` (Swagger)

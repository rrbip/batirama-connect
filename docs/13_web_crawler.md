# 13 - Crawler Web pour RAG

## Objectif

Le Crawler Web permet d'indexer automatiquement un site web complet dans le système RAG. Il crawle récursivement toutes les pages, images et documents depuis une URL de départ, puis les indexe dans Qdrant pour la recherche sémantique.

**Nouveauté** : Le cache est partagé entre agents. Un seul crawl peut alimenter plusieurs agents avec des configurations différentes (filtres, méthode de chunking).

## Accès

**Menu** : Intelligence Artificielle → Crawler Web

**URL** : `/admin/web-crawls`

**Permissions** : Accessible aux administrateurs

---

## 1. Architecture

### Séparation Cache / Indexation

```
┌─────────────────────────────────────────────────────────────────┐
│                         WebCrawl                                │
│              (télécharge & cache le contenu)                    │
│                                                                 │
│  • start_url, allowed_domains                                   │
│  • max_depth, max_pages, auth...                                │
│  • PAS d'agent - cache pur                                      │
└─────────────────────────┬───────────────────────────────────────┘
                          │
        ┌─────────────────┼─────────────────┐
        ▼                 ▼                 ▼
┌───────────────┐ ┌───────────────┐ ┌───────────────┐
│ AgentWebCrawl │ │ AgentWebCrawl │ │ AgentWebCrawl │
│   Agent A     │ │   Agent B     │ │   Agent C     │
│ ─────────────│ │ ─────────────│ │ ─────────────│
│ Filtres: /doc │ │ Filtres: *    │ │ Filtres: .pdf │
│ Types: html   │ │ Types: all    │ │ Types: pdf    │
│ Chunk: LLM    │ │ Chunk: simple │ │ Chunk: simple │
└───────┬───────┘ └───────┬───────┘ └───────┬───────┘
        ▼                 ▼                 ▼
   Documents A       Documents B       Documents C
   (Qdrant A)        (Qdrant B)        (Qdrant C)
```

### Avantages

| Aspect | Description |
|--------|-------------|
| **Économie de stockage** | 1 fichier par URL, même si 5 agents l'utilisent |
| **Économie de bande passante** | Pas de re-téléchargement si inchangé |
| **Flexibilité** | Chaque agent a ses propres filtres et méthode de chunking |
| **Cohérence** | Tous les agents ont la même version du contenu source |
| **Indépendance** | Supprimer un document RAG ne supprime pas le cache |

---

## 2. Fonctionnalités

### Vue d'ensemble

- Crawl récursif depuis une URL de départ
- Extraction et indexation de pages HTML, PDFs, images (OCR), documents Office
- Respect de robots.txt et délais de politesse
- Support de l'authentification (Basic Auth, Cookies)
- **Multi-agents** : Un crawl peut indexer plusieurs agents
- **Filtrage par agent** : Chaque agent a ses propres patterns d'inclusion/exclusion
- **Chunking par agent** : Chaque agent utilise sa méthode de chunking préférée
- Suivi en temps réel de la progression

### Types de contenu supportés

| Content-Type | Action | Extraction |
|--------------|--------|------------|
| `text/html` | Parse + indexe | Texte HTML nettoyé |
| `application/pdf` | Télécharge + indexe | Selon config agent (auto/text/ocr) |
| `image/*` | Télécharge + indexe | **Toujours OCR** (Tesseract) |
| `text/plain`, `text/markdown` | Télécharge + indexe | Texte brut |
| `application/msword`, `*officedocument*` | Télécharge + indexe | Extracteur DOCX |
| Autres | Skip | - |

---

## 3. Création d'un Crawl

### Étape 1 : Configuration du cache

| Champ | Type | Description |
|-------|------|-------------|
| **URL de départ** | URL (requis) | Page d'accueil du site à crawler |
| **Profondeur max** | Select | 1-10 niveaux, ou illimité (défaut: 5) |
| **Limite de pages** | Number | Max pages à crawler (défaut: 500) |
| **Limite espace disque** | Number | Mo max, vide = illimité |
| **Domaines autorisés** | Textarea | Liste de domaines (défaut: même domaine que l'URL) |
| **Délai entre requêtes** | Number | Millisecondes (défaut: 500ms) |
| **Respecter robots.txt** | Toggle | Défaut: Oui |
| **User-Agent** | TextInput | Défaut: `IA-Manager/1.0` |

### Étape 2 : Authentification (optionnel)

| Champ | Type | Description |
|-------|------|-------------|
| **Type d'auth** | Select | Aucune / Basic Auth / Cookies |
| **Username** | TextInput | Pour Basic Auth |
| **Password** | Password | Pour Basic Auth (chiffré en base) |
| **Cookies** | Textarea | Format: `nom=valeur; nom2=valeur2` |

### Étape 3 : Lier des agents

Après création du crawl, ajoutez un ou plusieurs agents avec leur configuration :

| Champ | Type | Description |
|-------|------|-------------|
| **Agent** | Select | Agent IA à indexer |
| **Mode de filtrage** | Radio | Exclure ou Inclure uniquement |
| **Patterns** | Textarea | Patterns d'URLs (un par ligne) |
| **Types de contenu** | Checkboxes | HTML, PDF, Images, Documents, Autres |
| **Stratégie de chunking** | Select | Simple / HTML Sémantique / LLM (défaut: valeur de l'agent) |

---

## 4. Configuration par agent

### Filtres d'URLs

**Mode de filtrage :**

| Mode | Comportement |
|------|--------------|
| **Exclure** | Indexe tout **SAUF** les URLs matchant les patterns |
| **Inclure** | Indexe **UNIQUEMENT** les URLs matchant les patterns |

**Important** : Le cache contient toutes les URLs. Seule l'indexation est filtrée par agent.

**Syntaxe des patterns :**
```
/blog/*              # Wildcard simple
/products/*.html     # Wildcard avec extension
^/docs/v[0-9]+/.*    # Regex (commence par ^)
/admin               # Match exact
```

### Types de contenu

Chaque agent peut choisir quels types de contenu indexer :

| Type | Content-Types |
|------|---------------|
| **HTML** | `text/html` |
| **PDF** | `application/pdf` |
| **Images** | `image/*` |
| **Documents** | `application/msword`, `*officedocument*`, `text/plain`, `text/markdown` |

### Stratégie de chunking

| Stratégie | Description | Cas d'usage |
|-----------|-------------|-------------|
| **Simple** | Découpage par taille fixe | Contenu homogène, rapide |
| **HTML Sémantique** | Découpage par balises HTML (h1, h2, p...) | Documentation, articles |
| **LLM** | Découpage intelligent par IA | Contenu complexe, qualité maximale |

**Héritage** : Si non spécifié, utilise la valeur par défaut de l'agent (`Agent.chunk_strategy`).

---

## 5. Suivi du Crawl

### Page de détail

La page de détail affiche en temps réel :

- **Progression** : Barre de progression et compteurs du cache
- **Statistiques globales** :
  - Pages découvertes / crawlées
  - Espace disque utilisé
  - Durée du crawl
- **Statistiques par agent** :
  - Pages indexées / skippées / erreurs
  - Documents créés
  - Statut d'indexation

### Liste des URLs

Toutes les URLs découvertes sont stockées et affichées avec :

| Colonne | Description |
|---------|-------------|
| **URL** | Chemin relatif de la page |
| **Profondeur** | Niveau depuis l'URL de départ |
| **HTTP** | Code de statut HTTP (200, 404, 500, etc.) |
| **Type** | Content-Type (HTML, PDF, Image, etc.) |
| **Cache** | Stocké ✓ / En attente / Erreur |
| **Agents** | Liste des agents qui ont indexé cette URL |

### Actions

| Action | Description |
|--------|-------------|
| **Pause** | Suspend le crawl (peut reprendre plus tard) |
| **Reprendre** | Continue un crawl pausé |
| **Relancer** | Re-crawle tout le site |
| **Tout supprimer** | Supprime cache + documents de tous les agents |
| **Ajouter un agent** | Lie un nouvel agent avec sa config |
| **Réindexer agent** | Réindexe un agent spécifique |

---

## 6. Comportement lors du re-crawl

### Mise à jour du cache

1. **Téléchargement conditionnel** : Utilise les headers `If-Modified-Since` / `ETag`
2. **Détection de changement** : Compare le hash du contenu
3. **Si inchangé (304)** : Skip le téléchargement, garde le cache
4. **Si changé** : Met à jour le fichier partagé

### Réindexation automatique

Quand une URL est mise à jour :

```php
// Observer déclenché
class WebCrawlUrlObserver
{
    public function updated(WebCrawlUrl $url): void
    {
        // Trouver tous les agents liés à cette URL
        $agentConfigs = AgentWebCrawl::whereHas('webCrawl.urls',
            fn($q) => $q->where('web_crawl_url_id', $url->id)
        )->get();

        foreach ($agentConfigs as $config) {
            // Utiliser la stratégie de chunking de l'agent
            $chunkStrategy = $config->chunk_strategy
                ?? $config->agent->chunk_strategy;

            IndexAgentUrlJob::dispatch($config, $url, $chunkStrategy);
        }
    }
}
```

---

## 7. Architecture technique

### Tables de base de données

#### agents (modifié)

| Colonne | Type | Description |
|---------|------|-------------|
| chunk_strategy | VARCHAR(50) | Stratégie de chunking par défaut (simple/html_semantic/llm_assisted) |

#### web_crawls (cache pur)

| Colonne | Type | Description |
|---------|------|-------------|
| id | BIGSERIAL | Clé primaire |
| uuid | UUID | Identifiant unique |
| start_url | VARCHAR(2048) | URL de départ |
| allowed_domains | JSONB | Domaines autorisés |
| max_depth | INT | Profondeur max (défaut: 5) |
| max_pages | INT | Limite de pages (défaut: 500) |
| max_disk_mb | INT | Limite disque en Mo (NULL = illimité) |
| delay_ms | INT | Délai entre requêtes (défaut: 500) |
| respect_robots_txt | BOOLEAN | Défaut: true |
| user_agent | VARCHAR(500) | User-Agent HTTP |
| auth_type | VARCHAR(20) | none / basic / cookies |
| auth_credentials | JSONB | Credentials chiffrés |
| status | VARCHAR(20) | pending / running / paused / completed / failed |
| pages_discovered | INT | Compteur |
| pages_crawled | INT | Compteur |
| total_size_bytes | BIGINT | Espace disque utilisé |
| started_at | TIMESTAMP | Début du crawl |
| paused_at | TIMESTAMP | Mise en pause |
| completed_at | TIMESTAMP | Fin du crawl |

**Supprimés** : `agent_id`, `url_filter_mode`, `url_patterns`, `pages_indexed`, `pages_skipped`, `pages_error`

#### web_crawl_urls (cache partagé - inchangé)

| Colonne | Type | Description |
|---------|------|-------------|
| id | BIGSERIAL | Clé primaire |
| url | VARCHAR(2048) | URL complète |
| url_hash | VARCHAR(64) | SHA256 pour déduplication (UNIQUE) |
| storage_path | VARCHAR(500) | Chemin du fichier partagé |
| content_hash | VARCHAR(64) | Hash du contenu |
| last_modified | TIMESTAMP | Header Last-Modified |
| etag | VARCHAR(255) | Header ETag |
| http_status | INT | Dernier code HTTP |
| content_type | VARCHAR(100) | Content-Type |
| content_length | BIGINT | Taille en bytes |

#### web_crawl_url_crawls (pivot cache - simplifié)

| Colonne | Type | Description |
|---------|------|-------------|
| id | BIGSERIAL | Clé primaire |
| web_crawl_id | FK | Référence web_crawls |
| web_crawl_url_id | FK | Référence web_crawl_urls |
| parent_id | FK | URL parente (pour arborescence) |
| depth | INT | Profondeur depuis l'URL de départ |
| status | VARCHAR(20) | pending / fetching / fetched / error |
| error_message | TEXT | Message d'erreur |
| retry_count | INT | Nombre de tentatives |
| fetched_at | TIMESTAMP | Date de récupération |

**Supprimés** : `document_id`, `indexed_at`, `skip_reason`, `matched_pattern`

#### agent_web_crawls (NOUVEAU - config par agent)

| Colonne | Type | Description |
|---------|------|-------------|
| id | BIGSERIAL | Clé primaire |
| agent_id | FK | Agent IA |
| web_crawl_id | FK | Crawl source |
| url_filter_mode | VARCHAR(10) | 'exclude' ou 'include' |
| url_patterns | JSONB | Patterns de filtrage |
| content_types | JSONB | Types à indexer ['html', 'pdf', 'image', 'document'] |
| chunk_strategy | VARCHAR(50) | Override (NULL = utilise Agent.chunk_strategy) |
| index_status | VARCHAR(20) | pending / indexing / indexed / error |
| pages_indexed | INT | Compteur |
| pages_skipped | INT | Compteur |
| pages_error | INT | Compteur |
| last_indexed_at | TIMESTAMP | Dernière indexation |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

**Index unique** : `(agent_id, web_crawl_id)`

#### agent_web_crawl_urls (NOUVEAU - statut par URL par agent)

| Colonne | Type | Description |
|---------|------|-------------|
| id | BIGSERIAL | Clé primaire |
| agent_web_crawl_id | FK | Référence agent_web_crawls |
| web_crawl_url_id | FK | Référence web_crawl_urls |
| document_id | FK | Document RAG créé (nullable) |
| status | VARCHAR(20) | pending / indexed / skipped / error |
| skip_reason | VARCHAR(100) | Raison du skip (pattern, content_type...) |
| error_message | TEXT | Message d'erreur |
| indexed_at | TIMESTAMP | Date d'indexation |

**Index unique** : `(agent_web_crawl_id, web_crawl_url_id)`

### Jobs Laravel

| Job | Queue | Description |
|-----|-------|-------------|
| `StartWebCrawlJob` | default | Initialise le crawl, parse robots.txt, ajoute URL de départ |
| `CrawlUrlJob` | default | GET URL, stocke en cache, extrait liens |
| `IndexAgentJob` | default | Indexe toutes les URLs d'un crawl pour un agent |
| `IndexAgentUrlJob` | default | Indexe une URL spécifique pour un agent |
| `ReindexAgentJob` | llm-chunking | Réindexe tout un agent (après changement de config) |

### Services

| Service | Description |
|---------|-------------|
| `WebCrawlerService` | Logique HTTP, gestion des requêtes |
| `RobotsTxtParser` | Parse et vérifie robots.txt |
| `UrlNormalizer` | Normalise les URLs pour déduplication |

### Observers

#### WebCrawlUrlObserver

Déclenche la réindexation quand le contenu d'une URL change :

```php
public function updated(WebCrawlUrl $url): void
{
    // Si le contenu a changé (content_hash différent)
    if ($url->isDirty('content_hash')) {
        // Trouver tous les AgentWebCrawl concernés
        // Dispatcher IndexAgentUrlJob pour chacun
    }
}
```

#### DocumentObserver

Ne touche plus au cache. Supprime uniquement les vecteurs Qdrant.

---

## 8. Politeness et bonnes pratiques

### Règles respectées

- **robots.txt** : Respect des directives Disallow, Allow, Crawl-delay
- **User-Agent** : Identifiable et configurable
- **Délai** : Minimum 500ms entre requêtes (configurable)
- **Concurrence** : Max 2 requêtes simultanées par domaine
- **Timeout** : 30 secondes par requête

### Headers HTTP

```
User-Agent: IA-Manager/1.0
Accept: text/html,application/pdf,image/*,*/*
Accept-Language: fr-FR,fr;q=0.9,en;q=0.8
If-Modified-Since: <date du dernier crawl>
If-None-Match: <etag du dernier crawl>
```

---

## 9. Sécurité

- **Credentials chiffrés** : Les mots de passe sont stockés avec `encrypt()`
- **Validation URL** : Blocage de `file://`, `localhost`, IPs privées
- **Rate limiting** : Délais respectés même si désactivé par l'utilisateur
- **Timeout strict** : Protection contre les serveurs lents
- **Sanitization** : Nettoyage HTML avant stockage
- **Isolation agents** : Supprimer un document n'affecte pas les autres agents

---

## 10. Cas limites

| Cas | Comportement |
|-----|--------------|
| Redirection 301/302 | Suivre, stocker URL finale |
| Redirection en boucle | Détecter après 5 redirections, marquer erreur |
| Page très grande (>10Mo) | Skip avec raison `content_too_large` |
| Encoding non-UTF8 | Détecter charset, convertir |
| URLs relatives | Résoudre par rapport à l'URL courante |
| Certificat SSL invalide | Option pour ignorer (défaut: vérifier) |
| Contenu non modifié (304) | Skip téléchargement si cache existe, sinon force download |

---

## 11. Limitations actuelles

- **JavaScript/SPA** : Les sites utilisant React, Vue, Angular ne sont pas supportés car le contenu est généré côté client. Seul le HTML statique est indexé.
- **Re-crawl automatique** : Pas de planification automatique, le re-crawl est manuel.
- **Captcha/Protection** : Les sites avec Cloudflare, captcha ou autres protections ne peuvent pas être crawlés.

---

## 12. Exemple d'utilisation

### Crawler un site pour plusieurs agents

1. **Créer le crawl** (cache)
   - URL de départ : `https://docs.example.com`
   - Profondeur : 5 niveaux
   - Lancer le crawl

2. **Ajouter Agent "Support Client"**
   - Mode : Inclure uniquement
   - Patterns : `/faq/*`, `/help/*`
   - Types : HTML, PDF
   - Chunking : LLM (pour qualité)

3. **Ajouter Agent "Technique"**
   - Mode : Inclure uniquement
   - Patterns : `/api/*`, `/docs/*`
   - Types : HTML, PDF, Markdown
   - Chunking : HTML Sémantique

4. **Ajouter Agent "Commercial"**
   - Mode : Exclure
   - Patterns : `/api/*`, `/admin/*`
   - Types : HTML, PDF, Images
   - Chunking : Simple

### Re-crawler pour mise à jour

1. Ouvrir le crawl existant
2. Cliquer sur "Relancer"
3. Seules les pages modifiées seront re-téléchargées
4. **Tous les agents liés sont automatiquement réindexés** avec leur propre méthode de chunking

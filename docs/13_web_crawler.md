# 13 - Crawler Web pour RAG

## Objectif

Le Crawler Web permet d'indexer automatiquement un site web complet dans le système RAG. Il crawle récursivement toutes les pages, images et documents depuis une URL de départ, puis les indexe dans Qdrant pour la recherche sémantique.

## Accès

**Menu** : Intelligence Artificielle → Documents RAG → Bouton "Crawler un site"

**URL** : `/admin/web-crawls`

**Permissions** : Accessible aux administrateurs

---

## 1. Fonctionnalités

### Vue d'ensemble

- Crawl récursif depuis une URL de départ
- Extraction et indexation de pages HTML, PDFs, images (OCR), documents Office
- Respect de robots.txt et délais de politesse
- Support de l'authentification (Basic Auth, Cookies)
- Filtrage par patterns (inclusion/exclusion)
- Suivi en temps réel de la progression
- Partage du contenu entre agents (économie de stockage et bande passante)

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

## 2. Création d'un Crawl

### Configuration de base

| Champ | Type | Description |
|-------|------|-------------|
| **URL de départ** | URL (requis) | Page d'accueil du site à crawler |
| **Agent cible** | Select (requis) | Agent IA associé aux documents |
| **Profondeur max** | Select | 1-10 niveaux, ou illimité (défaut: 5) |
| **Limite de pages** | Number | Max pages à crawler (défaut: 500) |
| **Limite espace disque** | Number | Mo max, vide = illimité |
| **Domaines autorisés** | Textarea | Liste de domaines (défaut: même domaine que l'URL) |
| **Délai entre requêtes** | Number | Millisecondes (défaut: 500ms) |
| **Respecter robots.txt** | Toggle | Défaut: Oui |
| **User-Agent** | TextInput | Défaut: `IA-Manager/1.0 (+https://votre-site.com)` |

### Filtres d'URLs

Le système de filtrage permet de contrôler précisément quelles URLs seront indexées.

**Mode de filtrage :**

| Mode | Comportement |
|------|--------------|
| **Exclure** | Indexe tout **SAUF** les URLs matchant les patterns |
| **Inclure** | Indexe **UNIQUEMENT** les URLs matchant les patterns |

**Important** : Dans les deux cas, **toutes les URLs sont crawlées et stockées** pour préserver l'analyse du maillage du site. Seule l'indexation est filtrée.

**Syntaxe des patterns :**
```
/blog/*              # Wildcard simple
/products/*.html     # Wildcard avec extension
^/docs/v[0-9]+/.*    # Regex (commence par ^)
/admin               # Match exact
```

**Comportement détaillé :**

| Mode | URL matche un pattern | Action |
|------|----------------------|--------|
| Exclure | Oui | Crawl + Stocke + **Skip indexation** |
| Exclure | Non | Crawl + Stocke + **Indexe** |
| Inclure | Oui | Crawl + Stocke + **Indexe** |
| Inclure | Non | Crawl + Stocke + **Skip indexation** |

### Authentification

| Champ | Type | Description |
|-------|------|-------------|
| **Type d'auth** | Select | Aucune / Basic Auth / Cookies |
| **Username** | TextInput | Pour Basic Auth |
| **Password** | Password | Pour Basic Auth (chiffré en base) |
| **Cookies** | Textarea | Format: `nom=valeur; nom2=valeur2` |
| **Headers custom** | KeyValue | Headers HTTP additionnels |

### Options d'indexation

Les valeurs par défaut sont héritées de la configuration de l'agent cible :

| Option | Source | Description |
|--------|--------|-------------|
| **Méthode d'extraction PDF** | `agent.default_extraction_method` | auto / text / ocr |
| **Stratégie de chunking** | `agent.default_chunk_strategy` | sentence / paragraph / etc. |

> **Exception** : Les images utilisent **toujours OCR**, indépendamment de la configuration.

---

## 3. Suivi du Crawl

### Page de détail

La page de détail affiche en temps réel :

- **Progression** : Barre de progression et compteurs
- **Statistiques** :
  - Pages découvertes / crawlées / indexées
  - Pages skippées (avec raisons)
  - Pages en erreur
  - Documents trouvés (PDF, DOCX, etc.)
  - Images indexées (OCR)
  - Espace disque utilisé
- **Durée** : Temps écoulé depuis le début

### Liste des URLs

Toutes les URLs découvertes sont stockées et affichées avec :

| Colonne | Description |
|---------|-------------|
| **URL** | Chemin relatif de la page |
| **Profondeur** | Niveau depuis l'URL de départ |
| **HTTP** | Code de statut HTTP (200, 404, 500, etc.) |
| **Type** | Content-Type (HTML, PDF, Image, etc.) |
| **Statut** | Indexé ✓ / Skipped ⊘ / Erreur ✗ / En attente |
| **Raison** | Si skippé ou erreur : explication |
| **Document** | Lien vers le document créé (si indexé) |

### Filtres disponibles

- Par statut : Tous / Indexées / Skipped / Erreurs / En attente
- Par code HTTP : 2xx / 3xx / 4xx / 5xx
- Par type : HTML / PDF / Image / Autre
- Par profondeur

### Actions

| Action | Description |
|--------|-------------|
| **Pause** | Suspend le crawl (peut reprendre plus tard) |
| **Reprendre** | Continue un crawl pausé |
| **Retry erreurs** | Relance uniquement les URLs en erreur |
| **Annuler** | Arrête définitivement le crawl |
| **Supprimer** | Supprime le crawl + URLs + Documents + Index Qdrant |

---

## 4. Partage de contenu entre agents

### Principe

Lorsqu'une même URL est utilisée par plusieurs agents IA, le contenu n'est téléchargé qu'une seule fois :

```
URL: example.com/doc.pdf
         │
         ▼
┌─────────────────────────┐
│  web_crawl_urls         │
│  (1 seul enregistrement)│
│  - storage_path unique  │
│  - content_hash         │
└────────────┬────────────┘
             │
    ┌────────┴────────┐
    ▼                 ▼
┌─────────┐     ┌─────────┐
│Document │     │Document │
│Agent A  │     │Agent B  │
│→ Qdrant │     │→ Qdrant │
│  coll_A │     │  coll_B │
└─────────┘     └─────────┘
```

### Comportement lors du re-crawl

Quand un agent re-crawle une URL déjà connue :

1. **Téléchargement conditionnel** : Utilise les headers `If-Modified-Since` / `ETag`
2. **Détection de changement** : Compare le hash du contenu
3. **Si inchangé** : Skip le téléchargement, log "unchanged"
4. **Si changé** :
   - Met à jour le fichier partagé
   - Trouve **TOUS** les documents liés à cette URL
   - Re-indexe dans **TOUTES** les collections Qdrant concernées

### Avantages

- **Économie de stockage** : 1 fichier par URL, même si 5 agents l'utilisent
- **Économie de bande passante** : Pas de re-téléchargement si inchangé
- **Cohérence** : Tous les agents ont la même version du contenu
- **Efficacité** : Un seul crawl met à jour tous les index

---

## 5. Configuration par défaut des agents

Les agents IA peuvent définir des valeurs par défaut pour l'extraction et le chunking.

### Champs ajoutés à la configuration agent

| Champ | Description | Défaut |
|-------|-------------|--------|
| **Méthode d'extraction PDF** | auto / text / ocr | auto |
| **Stratégie de chunking** | sentence / paragraph / fixed_size / recursive | sentence |

Ces valeurs sont utilisées par :
- L'upload manuel de documents
- L'import en masse
- Le crawler web

---

## 6. Architecture technique

### Tables de base de données

#### web_crawls
Configuration et statistiques des crawls.

| Colonne | Type | Description |
|---------|------|-------------|
| id | BIGSERIAL | Clé primaire |
| uuid | UUID | Identifiant unique |
| agent_id | FK | Agent IA cible |
| start_url | VARCHAR(2048) | URL de départ |
| allowed_domains | JSONB | Domaines autorisés |
| url_filter_mode | VARCHAR(10) | 'exclude' ou 'include' |
| url_patterns | JSONB | Patterns de filtrage |
| max_depth | INT | Profondeur max (défaut: 5) |
| max_pages | INT | Limite de pages (défaut: 500) |
| max_disk_mb | INT | Limite disque en Mo (NULL = illimité) |
| delay_ms | INT | Délai entre requêtes (défaut: 500) |
| respect_robots_txt | BOOLEAN | Défaut: true |
| user_agent | VARCHAR(500) | User-Agent HTTP |
| auth_type | VARCHAR(20) | none / basic / cookies |
| auth_credentials | JSONB | Credentials chiffrés |
| custom_headers | JSONB | Headers HTTP additionnels |
| status | VARCHAR(20) | pending / running / paused / completed / failed |
| pages_discovered | INT | Compteur |
| pages_crawled | INT | Compteur |
| pages_indexed | INT | Compteur |
| pages_skipped | INT | Compteur |
| pages_error | INT | Compteur |
| documents_found | INT | Compteur |
| images_found | INT | Compteur |
| total_size_bytes | BIGINT | Espace disque utilisé |
| started_at | TIMESTAMP | Début du crawl |
| paused_at | TIMESTAMP | Mise en pause |
| completed_at | TIMESTAMP | Fin du crawl |

#### web_crawl_urls
URLs découvertes et leur statut. **Partagées entre crawls** via url_hash.

| Colonne | Type | Description |
|---------|------|-------------|
| id | BIGSERIAL | Clé primaire |
| url | VARCHAR(2048) | URL complète |
| url_hash | VARCHAR(64) | SHA256 pour déduplication (UNIQUE) |
| storage_path | VARCHAR(500) | Chemin du fichier partagé |
| content_hash | VARCHAR(64) | Hash du contenu pour détecter changements |
| last_modified | TIMESTAMP | Header Last-Modified |
| etag | VARCHAR(255) | Header ETag |
| http_status | INT | Dernier code HTTP |
| content_type | VARCHAR(100) | Content-Type |
| content_length | BIGINT | Taille en bytes |
| created_at | TIMESTAMP | Première découverte |
| updated_at | TIMESTAMP | Dernière mise à jour |

#### web_crawl_url_crawl (pivot)
Liaison entre URLs et crawls.

| Colonne | Type | Description |
|---------|------|-------------|
| id | BIGSERIAL | Clé primaire |
| crawl_id | FK | Référence web_crawls |
| crawl_url_id | FK | Référence web_crawl_urls |
| parent_id | FK | URL parente (pour arborescence) |
| depth | INT | Profondeur depuis l'URL de départ |
| status | VARCHAR(20) | pending / fetching / fetched / indexed / skipped / error |
| matched_pattern | VARCHAR(500) | Pattern qui a matché |
| skip_reason | VARCHAR(100) | Raison du skip |
| error_message | TEXT | Message d'erreur |
| retry_count | INT | Nombre de tentatives |
| fetched_at | TIMESTAMP | Date de récupération |
| indexed_at | TIMESTAMP | Date d'indexation |

### Jobs Laravel

| Job | Queue | Description |
|-----|-------|-------------|
| `StartWebCrawlJob` | default | Initialise le crawl, parse robots.txt, ajoute URL de départ |
| `CrawlUrlJob` | default | GET URL, extrait liens, dispatch traitement |
| `ProcessCrawledContentJob` | default | Crée Document, lance ProcessDocumentJob |
| `UpdateSharedContentJob` | default | Met à jour le contenu partagé et re-indexe tous les agents |

### Services

| Service | Description |
|---------|-------------|
| `WebCrawlerService` | Logique HTTP, gestion des requêtes |
| `RobotsTxtParser` | Parse et vérifie robots.txt |
| `UrlNormalizer` | Normalise les URLs pour déduplication |

### Observers

#### DocumentObserver
Gère la synchronisation avec Qdrant :

```php
// À la suppression
public function deleting(Document $document): void
{
    // Supprime les chunks de Qdrant
}

// À la mise à jour (re-crawl)
public function updating(Document $document): void
{
    // Si extraction_status passe à 'pending' → supprime l'ancien index
    // Si agent_id change → supprime de l'ancienne collection
}
```

#### WebCrawlObserver
Gère le nettoyage en cascade lors de la suppression d'un crawl.

---

## 7. Politeness et bonnes pratiques

### Règles respectées

- **robots.txt** : Respect des directives Disallow, Allow, Crawl-delay
- **User-Agent** : Identifiable et configurable
- **Délai** : Minimum 500ms entre requêtes (configurable)
- **Concurrence** : Max 2 requêtes simultanées par domaine
- **Timeout** : 30 secondes par requête, 1 heure max par crawl

### Headers HTTP

```
User-Agent: IA-Manager/1.0 (+https://votre-site.com)
Accept: text/html,application/pdf,image/*,*/*
Accept-Language: fr-FR,fr;q=0.9,en;q=0.8
If-Modified-Since: <date du dernier crawl>
If-None-Match: <etag du dernier crawl>
```

---

## 8. Sécurité

- **Credentials chiffrés** : Les mots de passe sont stockés avec `encrypt()`
- **Validation URL** : Blocage de `file://`, `localhost`, IPs privées
- **Rate limiting** : Délais respectés même si désactivé par l'utilisateur
- **Timeout strict** : Protection contre les serveurs lents
- **Sanitization** : Nettoyage HTML avant stockage

---

## 9. Cas limites

| Cas | Comportement |
|-----|--------------|
| Redirection 301/302 | Suivre, stocker URL finale |
| Redirection en boucle | Détecter après 5 redirections, marquer erreur |
| Page très grande (>10Mo) | Skip avec raison `content_too_large` |
| Encoding non-UTF8 | Détecter charset, convertir |
| URLs relatives | Résoudre par rapport à l'URL courante |
| Certificat SSL invalide | Option pour ignorer (défaut: vérifier) |
| Contenu non modifié (304) | Skip téléchargement, utiliser cache |

---

## 10. Limitations actuelles

- **JavaScript/SPA** : Les sites utilisant React, Vue, Angular ne sont pas supportés car le contenu est généré côté client. Seul le HTML statique est indexé.
- **Re-crawl automatique** : Pas de planification automatique, le re-crawl est manuel.
- **Captcha/Protection** : Les sites avec Cloudflare, captcha ou autres protections ne peuvent pas être crawlés.

---

## 11. Dépendances

```bash
# Déjà installées
composer require guzzlehttp/guzzle        # HTTP client
composer require symfony/dom-crawler      # HTML parsing

# À installer
composer require spatie/robots-txt        # Parser robots.txt
```

---

## 12. Exemple d'utilisation

### Crawler un site de documentation

1. Aller dans Documents RAG → "Crawler un site"
2. Configurer :
   - URL de départ : `https://docs.example.com`
   - Agent : "Support Client"
   - Profondeur : 5 niveaux
   - Mode filtrage : **Inclure uniquement**
   - Patterns : `/docs/*`, `/api/*`
3. Lancer le crawl
4. Suivre la progression en temps réel
5. Une fois terminé, les documents sont disponibles dans l'agent

### Re-crawler pour mise à jour

1. Ouvrir le crawl existant
2. Cliquer sur "Relancer"
3. Seules les pages modifiées seront re-téléchargées
4. Tous les agents utilisant ces URLs seront mis à jour

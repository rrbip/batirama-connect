# 10 - Gestion des Documents RAG

## Objectif

La gestion des Documents RAG permet d'importer, indexer et rechercher des documents pour enrichir les réponses des agents IA. Les documents sont découpés en chunks, vectorisés et stockés dans Qdrant pour la recherche sémantique.

## Accès

**Menu** : Intelligence Artificielle → Documents RAG

**URL** : `/admin/documents`

**Permissions** : Accessible aux administrateurs

---

## 1. Liste des Documents

### Colonnes affichées

| Colonne | Description |
|---------|-------------|
| **Titre** | Nom du document ou nom original du fichier |
| **Agent** | Agent IA associé (détermine la collection Qdrant) |
| **Type** | Extension du fichier (pdf, txt, docx, etc.) |
| **Extraction** | Statut : En attente, En cours, Terminé, Échoué |
| **Indexé** | Indicateur si le document est dans Qdrant |
| **Chunks** | Nombre de morceaux indexés |
| **Taille** | Taille du fichier |

### Actions disponibles

| Action | Icône | Description |
|--------|-------|-------------|
| **Télécharger** | ↓ | Télécharge le fichier original |
| **Retraiter** | ↻ | Relance l'extraction et l'indexation |
| **Indexer** | 🔍 | Indexe uniquement (si déjà extrait) |
| **Modifier** | ✏️ | Ouvre le formulaire d'édition |
| **Supprimer** | 🗑️ | Supprime le document et ses chunks |

### Filtres

- Par agent
- Par statut d'extraction
- Par statut d'indexation
- Par catégorie

---

## 2. Formulaire d'Édition

### Onglet "Informations"

#### Section "Fichier"
- **Agent** : Sélection de l'agent (obligatoire)
- **Titre** : Titre personnalisé (optionnel)
- **Description** : Description du contenu
- **URL source** : Lien vers la source originale
- **Catégorie** : documentation, faq, product, support, legal, other

#### Section "Fichier actuel"
Affiche les informations du fichier actuel :
- Nom du fichier
- Type (PDF, TXT, etc.)
- Statut (présent/manquant)
- Taille
- Date d'ajout
- Chemin de stockage

**Actions** :
- **Télécharger** : Télécharge le fichier
- **Voir** : Ouvre le fichier dans le navigateur (PDF uniquement)

#### Section "Remplacer le fichier"
Permet d'uploader un nouveau fichier pour remplacer l'actuel. Le document sera automatiquement retraité après remplacement.

### Onglet "Extraction"

Affiche les informations d'extraction :
- **Statut** : pending, processing, completed, failed
- **Date d'extraction**
- **Nombre de chunks**
- **Taille du fichier**

**Section "Texte extrait"** (dépliable) :
Affiche le texte brut extrait du document.

**Section "Erreur"** :
Affiche le message d'erreur si l'extraction a échoué.

### Onglet "Indexation"

- **Indexé dans Qdrant** : Indicateur booléen
- **Date d'indexation**
- **Stratégie de chunking** : fixed_size, sentence, paragraph, recursive

### Onglet "Chunks"

Liste tous les chunks du document avec :
- **Numéro de chunk**
- **Nombre de tokens**
- **Statut d'indexation** (✓ Indexé / ✗ Non indexé)
- **Contenu** (500 premiers caractères)

---

## 3. Pipeline de Traitement

### Étapes

```
1. Upload        → Fichier stocké dans storage/app/documents/
2. Extraction    → Texte extrait via pdftotext ou parsers
3. Chunking      → Découpage en morceaux de ~1000 tokens
4. Embedding     → Génération de vecteurs via Ollama
5. Indexation    → Stockage dans Qdrant
```

### Job de traitement

```php
ProcessDocumentJob::dispatch($document);
```

Ce job exécute automatiquement toutes les étapes. En cas d'erreur, il retry 3 fois avec un backoff de 60 secondes.

---

## 4. Types de Fichiers Supportés

| Extension | Parser | Notes |
|-----------|--------|-------|
| **pdf** | pdftotext (poppler) + smalot/pdfparser | pdftotext en priorité |
| **txt** | Lecture directe | Encodage UTF-8 requis |
| **md** | Lecture directe | Markdown |
| **docx** | ZipArchive + XML | Format Office moderne |
| **doc** | Extraction basique | Format ancien, résultats variables |

### Extraction PDF

1. **Méthode prioritaire** : `pdftotext` (poppler-utils)
   - Meilleure qualité pour PDFs textuels
   - Requiert le package `poppler-utils` dans le container

2. **Fallback** : `smalot/pdfparser`
   - Utilisé si pdftotext échoue
   - Consomme plus de mémoire

---

## 5. Configuration

### Variables d'environnement

```env
# Taille max des fichiers (en octets)
UPLOAD_MAX_FILESIZE=52428800  # 50MB

# Chunking
RAG_CHUNK_SIZE=1000           # Tokens par chunk
RAG_CHUNK_OVERLAP=100         # Tokens de chevauchement

# Indexation
RAG_MAX_RESULTS=5             # Résultats max par recherche
RAG_MIN_SCORE=0.5             # Score minimum (0-1)
```

### PHP (docker/app/php.ini)

```ini
memory_limit = 512M           # Important pour PDF volumineux
max_execution_time = 600      # 10 minutes max
upload_max_filesize = 50M
post_max_size = 100M
```

---

## 6. Architecture Technique

### Fichiers principaux

```
app/Filament/Resources/DocumentResource.php          # CRUD Filament
app/Filament/Resources/DocumentResource/Pages/
  ├── ListDocuments.php                              # Liste
  ├── CreateDocument.php                             # Création
  └── EditDocument.php                               # Édition + remplacement
app/Http/Controllers/Admin/DocumentController.php    # Download/View
app/Jobs/ProcessDocumentJob.php                      # Pipeline de traitement
app/Services/DocumentExtractorService.php            # Extraction texte
app/Services/DocumentChunkerService.php              # Découpage
```

### Routes

```php
// Téléchargement
GET /admin/documents/{document}/download

// Visualisation (PDF)
GET /admin/documents/{document}/view
```

---

## 7. Dépannage

### Erreur "Allowed memory size exhausted"

Le PDF consomme trop de mémoire. Vérifier :

```bash
docker compose exec queue php -i | grep memory_limit
# Doit afficher: memory_limit => 512M
```

Si c'est 128M, rebuilder les containers :
```bash
docker compose build app queue --no-cache
docker compose up -d
```

### Document avec 0 chunks

1. Vérifier le texte extrait dans l'onglet "Extraction"
2. Si vide ou très court : le PDF est probablement un scan (image)
3. Solution : convertir en PDF textuel ou utiliser OCR

### Indexation échoue

1. Vérifier que l'agent a une `Collection Qdrant` configurée
2. Vérifier que Qdrant est accessible (`/admin/ai-status-page`)
3. Consulter les logs : `docker compose logs queue --tail=100`

### Fichier "manquant" dans le formulaire

Le fichier a été supprimé du storage. Options :
1. Remplacer par un nouveau fichier
2. Supprimer le document et le recréer

---

## 8. Bonnes Pratiques

### Préparation des documents

- **PDFs** : Utiliser des PDFs textuels, pas des scans
- **Taille** : Éviter les documents > 10MB (long à traiter)
- **Structure** : Les documents bien structurés (titres, paragraphes) donnent de meilleurs chunks

### Organisation

- **Catégoriser** les documents (documentation, FAQ, support)
- **Utiliser des titres descriptifs** pour faciliter le debugging
- **Un agent = une thématique** : ne pas mélanger les domaines

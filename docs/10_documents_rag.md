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
| **Type** | Extension du fichier (pdf, txt, docx, images, etc.) |
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
| **Chunks** | ⊞ | Ouvre la page de gestion des chunks |
| **Modifier** | ✏️ | Ouvre le formulaire d'édition |
| **Supprimer** | 🗑️ | Supprime le document et ses chunks |

### Actions en-tête

| Action | Description |
|--------|-------------|
| **Import en masse** | Ouvre la page d'import multiple (ZIP ou fichiers) |
| **Nouveau** | Crée un document unitaire |

### Filtres

- Par agent
- Par statut d'extraction
- Par statut d'indexation
- Par catégorie

---

## 2. Import en Masse

**URL** : `/admin/documents/bulk-import`

Permet d'importer plusieurs documents simultanément.

### Option 1 : Fichiers Multiples (Drag & Drop)

- Glissez-déposez jusqu'à **100 fichiers** simultanément
- Formats acceptés : PDF, DOCX, TXT, MD, images (JPG, PNG, etc.)
- Le nom du fichier devient le titre du document
- Tous les fichiers auront la même catégorie (préfixe optionnel)

### Option 2 : Archive ZIP

- Upload d'un fichier ZIP (jusqu'à 500MB)
- La **structure des dossiers** définit les catégories

**Exemple de structure ZIP :**
```
mon-import.zip
├── Fiches Techniques/
│   ├── Isolation/
│   │   └── laine-verre.pdf      → Catégorie: "Fiches Techniques > Isolation"
│   └── Plomberie/
│       └── raccords.pdf         → Catégorie: "Fiches Techniques > Plomberie"
└── Guides/
    └── installation.pdf         → Catégorie: "Guides"
```

### Configuration de l'import

| Option | Description |
|--------|-------------|
| **Agent cible** | Tous les documents seront associés à cet agent |
| **Préfixe de catégorie** | Ajouté devant la catégorie dérivée du chemin |
| **Profondeur max** | Limite le nombre de niveaux de dossiers pour la catégorie |
| **Ignorer dossier racine** | Si le ZIP contient un seul dossier racine, l'ignorer |

### Traitement

- Les fichiers sont traités en **arrière-plan** via la queue Laravel
- Chaque document est automatiquement extrait et indexé
- Consultez la liste des documents pour suivre la progression

---

## 3. Gestion des Chunks

**URL** : `/admin/documents/{id}/chunks`

Permet de gérer finement les chunks d'un document après extraction.

### Fonctionnalités

| Action | Description |
|--------|-------------|
| **Édition inline** | Modifier le contenu d'un chunk directement |
| **Supprimer** | Supprime le chunk (et son vecteur dans Qdrant) |
| **Sélection multiple** | Cochez plusieurs chunks pour les fusionner |
| **Fusionner** | Combine les chunks sélectionnés en un seul |
| **Ré-indexer tout** | Régénère les embeddings de tous les chunks |
| **Ré-indexer un** | Régénère l'embedding d'un chunk modifié |

### Cas d'usage

- **Correction OCR** : Corriger les erreurs d'extraction sur les images
- **Fusion** : Regrouper des chunks trop petits pour plus de contexte
- **Nettoyage** : Supprimer les chunks non pertinents (en-têtes, pieds de page)

---

## 4. Formulaire d'Édition

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
- Type (PDF, TXT, images, etc.)
- Statut (présent/manquant)
- Taille
- Date d'ajout
- Chemin de stockage

**Actions** :
- **Télécharger** : Télécharge le fichier
- **Voir** : Ouvre le fichier dans le navigateur (PDF et images)

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

## 5. Pipeline de Traitement

### Étapes

```
1. Upload        → Fichier stocké dans storage/app/documents/
2. Extraction    → Texte extrait via pdftotext, parsers ou OCR
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

## 6. Types de Fichiers Supportés

| Extension | Parser | Notes |
|-----------|--------|-------|
| **pdf** | pdftotext + smalot/pdfparser + OCR fallback | Plusieurs méthodes, meilleur résultat choisi |
| **txt** | Lecture directe | Encodage UTF-8 requis |
| **md** | Lecture directe | Markdown |
| **docx** | ZipArchive + XML | Format Office moderne |
| **doc** | Extraction basique | Format ancien, résultats variables |
| **jpg, jpeg** | Tesseract OCR | Extraction texte via OCR |
| **png** | Tesseract OCR | Extraction texte via OCR |
| **gif** | Tesseract OCR | Extraction texte via OCR |
| **bmp** | Tesseract OCR | Extraction texte via OCR |
| **tiff, tif** | Tesseract OCR | Extraction texte via OCR |
| **webp** | Tesseract OCR | Extraction texte via OCR |

### Extraction PDF

Le système essaie **plusieurs méthodes** et choisit le meilleur résultat :

1. **pdftotext** (poppler-utils)
   - Essaie 3 modes : `-raw`, `-layout`, et défaut
   - Le mode `-raw` gère souvent mieux les ligatures typographiques
   - Requiert le package `poppler-utils` dans le container

2. **smalot/pdfparser**
   - Parser PHP natif
   - Consomme plus de mémoire mais peut mieux gérer certains encodages

3. **OCR Fallback** (Tesseract)
   - Si le taux de mots tronqués dépasse 5%, l'OCR est tenté
   - Convertit les pages PDF en images puis applique Tesseract
   - Utile pour les PDFs avec problèmes de ligatures ou scannés

**Comparaison automatique** :
- Les méthodes sont exécutées et comparées
- Le système compte les caractères problématiques (U+FFFD, mots tronqués)
- Le résultat avec le moins de problèmes est utilisé

**Gestion des ligatures** :
Les polices PDF utilisent parfois des ligatures typographiques (ff, fi, fl, ffi, ffl, st) qui peuvent causer des caractères manquants. Le système :
- Remplace automatiquement les ligatures Unicode par leurs caractères composants
- Supprime les caractères de remplacement (U+FFFD) résiduels
- Détecte les patterns de mots tronqués (ex: "rénovaon" → "rénovation")

### Extraction Images (OCR)

Pour les fichiers images (JPG, PNG, etc.), le système utilise **Tesseract OCR** :

```
Image → Tesseract OCR → Texte brut → Chunking → Indexation
```

**Configuration Tesseract** :
- Langues : Français (fra) + Anglais (eng) comme fallback
- Mode de segmentation : Automatique (PSM 3)
- Moteur : LSTM + Legacy (OEM 3)

**Limitations** :
- Qualité dépend de la résolution de l'image (300 DPI recommandé)
- Texte manuscrit mal reconnu
- Tableaux et mise en page complexes peuvent être mal interprétés

---

## 7. Configuration

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

# OCR (optionnel)
TESSERACT_PATH=/usr/bin/tesseract  # Chemin vers le binaire
```

### PHP (docker/app/php.ini)

```ini
memory_limit = 512M           # Important pour PDF volumineux
max_execution_time = 600      # 10 minutes max
upload_max_filesize = 50M
post_max_size = 100M
```

### Docker (packages requis)

```dockerfile
# Dans docker/app/Dockerfile
RUN apk add --no-cache \
    poppler-utils \           # pdftotext, pdftoppm
    tesseract-ocr \           # OCR
    tesseract-ocr-data-fra \  # Données françaises
    tesseract-ocr-data-eng    # Données anglaises
```

---

## 8. Architecture Technique

### Fichiers principaux

```
app/Filament/Resources/DocumentResource.php          # CRUD Filament
app/Filament/Resources/DocumentResource/Pages/
  ├── ListDocuments.php                              # Liste + bouton import
  ├── CreateDocument.php                             # Création
  ├── EditDocument.php                               # Édition + remplacement
  ├── ManageChunks.php                               # Gestion des chunks
  └── BulkImportDocuments.php                        # Import en masse
app/Http/Controllers/Admin/DocumentController.php    # Download/View
app/Jobs/ProcessDocumentJob.php                      # Pipeline de traitement
app/Jobs/ProcessBulkImportJob.php                    # Import en masse
app/Services/DocumentExtractorService.php            # Extraction texte + OCR
app/Services/DocumentChunkerService.php              # Découpage
```

### Routes

```php
// Téléchargement
GET /admin/documents/{document}/download

// Visualisation (PDF et images)
GET /admin/documents/{document}/view

// Gestion des chunks
GET /admin/documents/{document}/chunks

// Import en masse
GET /admin/documents/bulk-import
```

---

## 9. Dépannage

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
2. Si vide ou très court : le document est probablement un scan (image)
3. Solution : le système tentera automatiquement l'OCR si le texte est insuffisant

### Erreur OCR "Tesseract not found"

1. Vérifier que Tesseract est installé dans le conteneur **queue** (pas juste app) :
```bash
docker exec aim_queue tesseract --version
```

2. Si non installé, rebuilder tous les containers :
```bash
docker compose build --no-cache app scheduler queue
docker compose up -d
```

### Caractères manquants dans le texte extrait

Si certains mots ont des lettres manquantes (ex: "rénovaon" au lieu de "rénovation") :

1. **Cause probable** : le PDF utilise des ligatures typographiques (ti, fi, fl, etc.)
2. **Le système tente automatiquement l'OCR** si trop de mots tronqués sont détectés
3. **Vérifier les logs** : `docker compose logs queue | grep "OCR"`
4. **Solutions alternatives** :
   - Réexporter le PDF source avec une police sans ligatures
   - Utiliser la page "Gérer les chunks" pour corriger manuellement

### Indexation échoue

1. Vérifier que l'agent a une `Collection Qdrant` configurée
2. Vérifier que Qdrant est accessible (`/admin/ai-status-page`)
3. Consulter les logs : `docker compose logs queue --tail=100`

### Fichier "manquant" dans le formulaire

Le fichier a été supprimé du storage. Options :
1. Remplacer par un nouveau fichier
2. Supprimer le document et le recréer

---

## 10. Bonnes Pratiques

### Préparation des documents

- **PDFs** : Utiliser des PDFs textuels quand possible, le système gère les scans via OCR
- **Images** : Résolution de 300 DPI minimum pour une bonne reconnaissance OCR
- **Taille** : Éviter les documents > 10MB (long à traiter)
- **Structure** : Les documents bien structurés (titres, paragraphes) donnent de meilleurs chunks

### Organisation

- **Catégoriser** les documents (documentation, FAQ, support)
- **Utiliser des titres descriptifs** pour faciliter le debugging
- **Un agent = une thématique** : ne pas mélanger les domaines

### Import en masse

- **Organiser les dossiers** dans le ZIP pour avoir des catégories cohérentes
- **Limiter la profondeur** à 2-3 niveaux pour des catégories lisibles
- **Vérifier les fichiers** avant import (pas de doublons, formats corrects)

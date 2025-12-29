# 17 - Pipelines d'Extraction des Documents

## Objectif

Ce document décrit les 3 pipelines d'extraction disponibles pour traiter les documents dans le système RAG. Chaque pipeline transforme un type de source (PDF, Image, HTML) en texte indexable.

## Accès à la Visualisation des Pipelines

**Menu** : Intelligence Artificielle → Documents RAG → [Sélectionner un document] → **Onglet Extraction**

**URL** : `/admin/documents/{id}/edit`

Dans l'onglet **Extraction**, vous trouverez une section dépliable correspondant au type de document :
- **Pipeline d'extraction OCR** : Pour les PDFs et images traités avec Tesseract
- **Pipeline d'extraction Vision** : Pour les PDFs traités avec un modèle Vision IA (Moondream, LLaVA...)
- **Pipeline d'extraction HTML** : Pour les pages web crawlées

---

## Pipeline OCR (PDF → Images → Texte)

Ce pipeline est utilisé pour les documents PDF et images traités avec Tesseract OCR.

### Étapes du Pipeline

| Étape | Description | Informations affichées |
|-------|-------------|------------------------|
| **1. PDF → Images** | Conversion du PDF en images via `pdftoppm` | Outil utilisé, DPI, nombre de pages, temps de conversion |
| **2. Images → Texte** | Extraction du texte via Tesseract OCR | Moteur OCR, langues (fra+eng), pages traitées, durée totale |
| **3. Détail par page** | Tableau détaillant chaque page | Voir section "Actions par page" |
| **4. Chunking + Indexation** | Découpage et vectorisation | Stratégie, nombre de chunks, vectorisation, base vectorielle |

### Section 3 - Détail par page (OCR)

| Colonne | Description |
|---------|-------------|
| **Page** | Numéro de la page |
| **Image** | Nom du fichier image (page-1.png, page-2.png...) |
| **Taille texte** | Nombre de caractères extraits |
| **Temps OCR** | Durée d'extraction de cette page |
| **Actions** | Boutons d'action |

#### Boutons d'action

| Bouton | Icône | Description |
|--------|-------|-------------|
| **Voir l'image** | 🖼️ (bleu) | Ouvre l'image générée par pdftoppm dans un nouvel onglet |
| **Voir le texte OCR** | 📄 (orange) | Affiche/masque le texte extrait par Tesseract pour cette page |

> **Note** : Le bouton "Voir l'image" n'apparaît que si le stockage est configuré sur **Public** dans les paramètres Vision et que l'image existe sur le disque.

### Section 4 - Chunking + Indexation

Affiche les informations générales et propose une zone dépliable :

| Information | Description |
|-------------|-------------|
| **Stratégie** | Par phrase, Par paragraphe, Taille fixe, Markdown, ou Assisté par LLM |
| **Chunks générés** | Nombre total de chunks créés |
| **Vectorisation** | Service utilisé (Ollama nomic-embed-text) |
| **Base vectorielle** | Base de données vectorielle (Qdrant) |

**Zone dépliable "Voir les X chunks"** :
- Affiche tous les chunks avec leur index, nombre de tokens et statut d'indexation
- Chaque chunk montre un aperçu du contenu (300 premiers caractères)
- Indicateur : ✓ Indexé (vert) / ✗ Non indexé (rouge)

---

## Pipeline Vision (PDF → Images → Markdown)

Ce pipeline utilise un modèle de vision IA (Moondream, LLaVA, Llama3.2-vision) pour extraire le contenu en format Markdown structuré.

### Configuration

**Menu** : Intelligence Artificielle → Extraction Vision (`/admin/vision-settings-page`)

| Paramètre | Description |
|-----------|-------------|
| **Modèle** | moondream, llava:7b, llama3.2-vision, llava:13b |
| **Serveur Ollama** | Host et port du serveur Ollama |
| **DPI** | Résolution de conversion PDF→Image (300 recommandé) |
| **Disque de stockage** | Public (recommandé) ou Local |

### Étapes du Pipeline

| Étape | Description | Informations affichées |
|-------|-------------|------------------------|
| **1. PDF → Images** | Conversion via `pdftoppm` | Outil, DPI utilisé |
| **2. Images → Markdown** | Extraction via modèle Vision Ollama | Bibliothèque, modèle, pages traitées, durée |
| **3. Détail par page** | Tableau détaillant chaque page | Voir section "Actions par page" |
| **4. Chunking + Indexation** | Découpage et vectorisation | Stratégie, chunks, vectorisation |

### Section 3 - Détail par page (Vision)

| Colonne | Description |
|---------|-------------|
| **Page** | Numéro de la page |
| **Image** | Nom du fichier image |
| **Markdown** | Nom du fichier .md généré |
| **Taille MD** | Taille du markdown en caractères |
| **Temps** | Durée de traitement par le modèle vision |
| **Actions** | Boutons d'action |

#### Boutons d'action

| Bouton | Icône | Description |
|--------|-------|-------------|
| **Voir l'image** | 🖼️ (bleu) | Ouvre l'image de la page dans un nouvel onglet |
| **Voir le markdown** | 📄 (violet) | Affiche/masque le markdown généré pour cette page |

### Section 4 - Chunking + Indexation

Identique au pipeline OCR avec :
- Informations générales (stratégie, chunks, vectorisation)
- Bouton **"Gérer les chunks"** : Lien vers la page de gestion complète des chunks
- Zone dépliable **"Voir les X chunks"** : Liste tous les chunks avec aperçu

---

## Pipeline HTML (URL → HTML → Markdown)

Ce pipeline traite les pages web crawlées en les convertissant en Markdown propre.

### Étapes du Pipeline

| Étape | Description | Informations affichées | Bouton |
|-------|-------------|------------------------|--------|
| **1. Récupération HTML** | Fetch de l'URL source | Source, taille HTML, URL | 🔍 Voir HTML original |
| **2. Nettoyage HTML** | Suppression scripts, styles, nav | Taille après nettoyage, compression, éléments supprimés | 🔍 Voir HTML nettoyé |
| **3. Conversion Markdown** | Transformation HTML → Markdown | Convertisseur, taille MD, temps, éléments détectés | 📄 Voir Markdown |
| **4. Chunking + Indexation** | Découpage et vectorisation | Stratégie, chunks, vectorisation | 📋 Voir les chunks |

### Section 1 - Récupération HTML

| Information | Description |
|-------------|-------------|
| **Source** | URL crawlée |
| **Taille HTML** | Taille du HTML brut récupéré |
| **URL** | Lien vers la page source (tronqué si trop long) |

**Bouton "Voir HTML original"** : Affiche le HTML brut récupéré (limité à 5000 caractères) avec bouton "Copier".

### Section 2 - Nettoyage HTML

| Information | Description |
|-------------|-------------|
| **Taille après nettoyage** | Taille du HTML après suppression des éléments inutiles |
| **Compression** | Pourcentage de réduction (ex: 73% = taille divisée par ~4) |
| **Éléments supprimés** | scripts, styles, nav, footer, aside, iframe... |

**Bouton "Voir HTML nettoyé"** : Affiche le HTML après nettoyage avec bouton "Copier".

### Section 3 - Conversion Markdown

| Information | Description |
|-------------|-------------|
| **Convertisseur** | league/html-to-markdown |
| **Taille Markdown** | Taille du texte final |
| **Temps** | Durée de conversion en millisecondes |
| **Éléments détectés** | Titres, Listes, Tableaux, Liens, Images, Paragraphes |

**Bouton "Voir Markdown"** : Affiche le markdown généré avec bouton "Copier".

### Section 4 - Chunking + Indexation

Identique aux autres pipelines avec zone dépliable "Voir les X chunks".

---

## Comparaison des Pipelines

| Critère | OCR (Tesseract) | Vision (Ollama) | HTML |
|---------|-----------------|-----------------|------|
| **Source** | PDF, Images | PDF, Images | Pages web |
| **Sortie** | Texte brut | Markdown structuré | Markdown structuré |
| **Tableaux** | ⚠️ Lecture linéaire | ✅ Structure préservée* | ✅ Structure préservée |
| **GPU requis** | Non | Recommandé | Non |
| **Vitesse** | Rapide | Lent (10-30s/page) | Très rapide |
| **Qualité** | Bonne sur texte simple | Variable selon modèle | Excellente |

*La qualité d'extraction des tableaux en Vision dépend fortement du modèle utilisé. Moondream (1.8B) a des limitations.

---

## Métadonnées de Traçage

Chaque pipeline stocke ses métadonnées dans le champ `extraction_metadata` du document :

### OCR
```json
{
  "ocr_extraction": {
    "source_type": "pdf",
    "pdf_converter": "pdftoppm",
    "dpi": 300,
    "total_pages": 5,
    "pages_processed": 5,
    "ocr_engine": "Tesseract OCR",
    "ocr_languages": "fra+eng",
    "total_processing_time": 45.2,
    "storage_disk": "public",
    "storage_path": "ocr-extraction/abc123",
    "pages": [
      {
        "page": 1,
        "text_length": 2500,
        "processing_time": 8.5,
        "image_path": "ocr-extraction/abc123/page-1.png",
        "text_content": "..."
      }
    ],
    "extracted_at": "2025-12-28T10:30:00+00:00"
  }
}
```

### Vision
```json
{
  "vision_extraction": {
    "pdf_converter": "pdftoppm",
    "dpi": 300,
    "vision_model": "moondream",
    "vision_library": "Ollama API",
    "total_pages": 5,
    "pages_processed": 5,
    "duration_seconds": 125.5,
    "storage_path": "vision-extraction/def456",
    "storage_disk": "public",
    "store_images": true,
    "pages": [
      {
        "page": 1,
        "image_path": "vision-extraction/def456/page-1.png",
        "markdown_path": "vision-extraction/def456/page_1.md",
        "markdown_content": "# Titre...",
        "markdown_length": 1500,
        "processing_time": 25.2
      }
    ]
  }
}
```

### HTML
```json
{
  "html_extraction": {
    "converter": "league/html-to-markdown",
    "html_size": 45000,
    "cleaned_html_size": 35000,
    "markdown_size": 12000,
    "compression_ratio": 73.3,
    "elements_detected": {
      "headings": 5,
      "lists": 3,
      "tables": 1,
      "links": 12,
      "paragraphs": 15
    },
    "original_html": "<!DOCTYPE html>...",
    "cleaned_html": "<main>...</main>",
    "processing_time_ms": 45.2,
    "extracted_at": "2025-12-28T10:30:00+00:00"
  }
}
```

---

## Dépannage

### Images non visibles (404)

1. **Vérifier le disque de stockage** : Doit être sur "Public" dans `/admin/vision-settings-page`
2. **Vérifier le symlink** : `php artisan storage:link`
3. **Retraiter le document** après avoir changé le disque

### Boutons grisés ou absents

- **Bouton image grisé** : L'image n'existe pas sur le disque ou le stockage est sur "Local"
- **Bouton texte/markdown absent** : Le contenu n'a pas été stocké dans les métadonnées

### Pipeline non affiché

La section de pipeline s'affiche uniquement si :
- Le document a été traité avec cette méthode d'extraction
- Les métadonnées de traçage existent (documents traités après mise à jour du système)
- Pour voir le pipeline, utilisez le bouton **"Retraiter"**

---

---

## Stratégie de Chunking Markdown

### Principe

La stratégie `markdown` est **optimisée pour les documents HTML et Markdown**. Elle exploite la structure sémantique des headers (`#`, `##`, `###`...) pour créer des chunks cohérents.

### Avantages

| Aspect | Chunking classique | Chunking Markdown |
|--------|-------------------|-------------------|
| **Découpage** | Arbitraire (taille, ponctuation) | Sémantique (par section) |
| **Contexte** | Peut couper au milieu d'une idée | Chaque chunk = 1 section complète |
| **Titre** | Perdu | Conservé dans le chunk |
| **RAG** | Bruit potentiel | Meilleure pertinence |

### Fonctionnement

```
Document Markdown :
┌─────────────────────────────────────┐
│ # Titre principal                   │
│                                     │
│ Introduction...                     │
│                                     │
│ ## Section 1                        │
│                                     │
│ Contenu de la section 1...          │
│                                     │
│ ## Section 2                        │
│                                     │
│ Contenu de la section 2...          │
└─────────────────────────────────────┘

         ↓ Chunking Markdown

┌─────────────────────────┐
│ Chunk 0 (intro)         │
│ "Introduction..."       │
└─────────────────────────┘

┌─────────────────────────┐
│ Chunk 1                 │
│ "## Section 1           │
│                         │
│ Contenu de la section 1"│
└─────────────────────────┘

┌─────────────────────────┐
│ Chunk 2                 │
│ "## Section 2           │
│                         │
│ Contenu de la section 2"│
└─────────────────────────┘
```

### Métadonnées enrichies

Chaque chunk généré avec la stratégie `markdown` contient des métadonnées supplémentaires :

```json
{
  "strategy": "markdown",
  "section_type": "section",
  "header_level": 2,
  "header_title": "Section 1",
  "document_title": "Mon document",
  "category": "documentation"
}
```

| Champ | Description |
|-------|-------------|
| `section_type` | `intro` (avant le 1er header), `section` (section complète), `section_part` (section découpée) |
| `header_level` | Niveau du header (1 = `#`, 2 = `##`, etc.) |
| `header_title` | Texte du header de la section |

### Gestion des sections longues

Si une section est trop grande (> `max_chunk_size` tokens), elle est découpée en sous-chunks :

1. Le **premier sous-chunk** garde le header original : `## Section 1\n\nContenu...`
2. Les **sous-chunks suivants** ont un préfixe contextuel : `[Section 1]\n\nSuite du contenu...`

Cela garantit que chaque chunk reste compréhensible même hors contexte.

### Fallback automatique

Si le document ne contient **aucun header Markdown** (pas de `#`), la stratégie `markdown` bascule automatiquement sur la stratégie `paragraph`.

### Cas d'usage recommandés

| Source | Stratégie recommandée |
|--------|----------------------|
| **HTML crawlé** | `markdown` (après conversion HTML→MD) |
| **Fichiers .md** | `markdown` |
| **PDF structuré** | `paragraph` ou `recursive` |
| **PDF scanné** | `fixed_size` avec overlap |
| **Documents complexes** | `llm_assisted` |

### Configuration

La stratégie `markdown` peut être sélectionnée :

1. **Par document** : Dans le formulaire d'édition, onglet Indexation
2. **Par agent** : Champ `default_chunk_strategy` dans AgentResource
3. **À l'import** : Dans le formulaire d'import en masse

---

## Voir aussi

- [10_documents_rag.md](./10_documents_rag.md) - Gestion complète des documents RAG
- [14_llm_chunking.md](./14_llm_chunking.md) - Chunking assisté par LLM

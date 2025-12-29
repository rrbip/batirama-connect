# Cahier des Charges : Refonte de la Gestion RAG

> **Statut** : Validé - Prêt pour développement
> **Branche** : `claude/rag-refactor-planning-3F9Bx`
> **Date de création** : 2025-12-29
> **Dernière mise à jour** : 2025-12-29

---

## 1. Contexte et Périmètre

### 1.1 Objectifs de la refonte

1. **Unifier** l'interface de paramétrage RAG dans un écran unique
2. **Simplifier** la création de documents avec un formulaire adaptatif
3. **Clarifier** les pipelines de traitement en cascade
4. **Implémenter** une nouvelle stratégie d'indexation sémantique "Q/R Atomique"
5. **Améliorer le debug** avec visibilité complète sur chaque étape du pipeline

### 1.2 Périmètre

| Élément | Action |
|---------|--------|
| Page `/admin/gestion-rag-page` | **Refonte** - Regroupement des paramètres |
| Page `/admin/documents/create` | **Refonte** - Formulaire adaptatif |
| Page `/admin/documents/{id}/edit` | **Refonte** - Nouveaux onglets |
| Import en masse | **À adapter** |
| Pipelines de traitement | **Nouvelle architecture en cascade** |
| Stratégie de chunking | **Nouvelle** - Q/R Atomique |
| Crawl web | **Inchangé** (fonctionne bien) |

### 1.3 Formats de fichiers

| Format | Traitement |
|--------|------------|
| PDF | ✅ Supporté (pipeline complet) |
| HTML | ✅ Supporté (crawl ou fichier) |
| Images (JPG, PNG, etc.) | ✅ Supporté |
| Markdown | ✅ Supporté (format pivot) |
| DOCX | ❌ Pas traité pour l'instant |
| TXT | ❌ Pas traité pour l'instant |

> **Note :** DOCX et TXT seront traités plus tard avec une IA à fenêtre flottante pour transformer en Markdown structuré.

---

## 2. Interface : Page de Paramétrage RAG

**URL** : `/admin/gestion-rag-page`

### 2.1 Nouvelle structure

Regrouper les onglets actuels "Extraction Vision" et "Chunking LLM" en **un seul écran** avec des **zones dépliables fermées par défaut**.

```
┌─────────────────────────────────────────────────────────────┐
│  ⚙️ PARAMÉTRAGE RAG                                         │
│                                                             │
│  ▶ Configuration Vision (fermé par défaut)                 │
│  ────────────────────────────────────────────────────────  │
│                                                             │
│  ▶ Configuration Chunking LLM (fermé par défaut)           │
│  ────────────────────────────────────────────────────────  │
│                                                             │
│  ▶ Configuration Q/R Atomique (fermé par défaut)           │
│  ────────────────────────────────────────────────────────  │
│                                                             │
│  ▶ Outils par défaut par type de fichier (fermé)           │
│  ────────────────────────────────────────────────────────  │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 Zones dépliables

#### Zone 1 : Configuration Vision
- Modèle vision (utilise la hiérarchie : Déploiement > Agent > Global)
- Paramètres de température
- Prompt système pour description d'images

#### Zone 2 : Configuration Chunking LLM
- Modèle LLM (utilise la hiérarchie : Déploiement > Agent > Global)
- Taille de fenêtre
- Pourcentage de chevauchement
- Prompt système

#### Zone 3 : Configuration Q/R Atomique
- Seuil de caractères pour découpage (défaut: 1500, **configurable**)
- Prompt pour génération Q/R
- Paramètres d'indexation Qdrant

#### Zone 4 : Outils par défaut par type de fichier
Configuration des outils par défaut pour chaque étape du pipeline selon le type de fichier. Utilisé automatiquement lors du crawl de sites.

---

## 3. Interface : Création de Document

**URL** : `/admin/documents/create`

### 3.1 Structure du formulaire

```
┌─────────────────────────────────────────────────────────────┐
│  📄 NOUVEAU DOCUMENT RAG                                    │
│                                                             │
│  ── Agent IA (obligatoire) ────────────────────────────    │
│                                                             │
│  [Sélectionner un agent ▼]                                  │
│                                                             │
│  ── Source (obligatoire : fichier OU url) ─────────────    │
│                                                             │
│  ○ Fichier                                                  │
│    ┌─────────────────────────────────────────────────┐     │
│    │  Glissez un fichier ici ou cliquez              │     │
│    └─────────────────────────────────────────────────┘     │
│                                                             │
│  ○ URL                                                      │
│    [https://exemple.com/page________________]               │
│    ⓘ Lancera un crawl pour récupérer le contenu            │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 3.2 Comportement selon la source

#### Si URL sélectionnée :
1. Lancer un crawl pour récupérer les données
2. **Pré-remplissage automatique** :
   - Titre ← balise `<title>`
   - Description ← meta description
3. Type de fichier résultant : **HTML**

#### Si Fichier uploadé :
1. Précharger le fichier
2. **Pré-remplissage automatique** :
   - Titre ← nom du fichier (sans extension)
   - Description ← vide
3. Type détecté selon MIME

### 3.3 Configuration du pipeline (après détection type)

```
┌─────────────────────────────────────────────────────────────┐
│  ── Configuration du pipeline ─────────────────────────    │
│                                                             │
│  Type détecté : PDF                                         │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Étape 1 : PDF → Image                               │   │
│  │ Outil : [Pdftoppm (défaut) ▼]                       │   │
│  └─────────────────────────────────────────────────────┘   │
│                    ↓                                        │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Étape 2 : Image → Markdown                          │   │
│  │ Outil : [Vision LLM (agent) ▼]                      │   │
│  └─────────────────────────────────────────────────────┘   │
│                    ↓                                        │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Étape 3 : Markdown → Q/R + Indexation               │   │
│  │ Outil : [Q/R Atomique ▼]                            │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ⓘ Les valeurs par défaut sont utilisées                   │
│    automatiquement lors du crawl de sites                  │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 3.4 Métadonnées

```
┌─────────────────────────────────────────────────────────────┐
│  ── Métadonnées ───────────────────────────────────────    │
│                                                             │
│  Titre : [_______________________________]                  │
│          (pré-rempli selon source)                         │
│                                                             │
│  Description : [____________________________]               │
│                (pré-rempli si crawl)                       │
│                                                             │
│  URL source : [_______________________________]             │
│               (auto si crawl)                              │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 3.5 Éléments supprimés

- ❌ **Catégorie du document** : Supprimée (catégorisation au niveau des chunks par l'IA)
- ❌ **Méthode d'extraction** : Supprimée (incluse dans les choix d'outils du pipeline)

---

## 4. Interface : Édition de Document

**URL** : `/admin/documents/{id}/edit`

### 4.1 Structure des onglets

```
┌─────────────────────────────────────────────────────────────┐
│  📋 Informations  │  ⚙️ Pipeline  │  🔍 Indexation  │  📦 Chunks  │
└─────────────────────────────────────────────────────────────┘
```

### 4.2 Onglet : Informations

Garde la structure actuelle **sauf** :
- ❌ Suppression de la méthode d'extraction PDF (déplacée dans Pipeline)

Contenu conservé :
- Fichier actuel (visualisation, téléchargement)
- Remplacement de fichier
- Métadonnées (titre, description, URL source)

### 4.3 Onglet : Pipeline (nouveau - remplace Extraction)

#### 4.3.1 Objectif

Afficher le pipeline complet avec **toutes les informations de debug** pour chaque étape :
- Outil utilisé et sa configuration
- Statut et durée
- Résultat visualisable
- Possibilité de changer d'outil et relancer

#### 4.3.2 Informations stockées par étape

| Information | Description | Exemple |
|-------------|-------------|---------|
| `step_name` | Nom de l'étape | "pdf_to_images", "image_to_markdown" |
| `tool_used` | Outil utilisé | "pdftoppm", "vision_llm" |
| `tool_config` | Config de l'outil | `{"model": "llava:13b", "temperature": 0.3}` |
| `status` | État | "pending", "running", "success", "error" |
| `started_at` | Début traitement | timestamp |
| `completed_at` | Fin traitement | timestamp |
| `duration_ms` | Durée en ms | 2345 |
| `input_summary` | Résumé de l'entrée | "15 pages PDF, 3.2MB" |
| `output_summary` | Résumé de la sortie | "15 images, 12MB total" |
| `output_path` | Chemin stockage résultat | "storage/pipeline/doc_xxx/step1/" |
| `output_data` | Données complètes | Markdown généré, métadonnées, etc. |
| `error_message` | Si erreur | "Timeout après 60s" |
| `error_trace` | Stack trace | Pour debug technique |

> **Stockage :** Toutes les données sont conservées pour le debug. Un traitement d'archivage sera ajouté plus tard pour nettoyer.

#### 4.3.3 Interface visuelle

```
┌─────────────────────────────────────────────────────────────┐
│  ⚙️ PIPELINE DE TRAITEMENT                                  │
│                                                             │
│  Type : PDF | Statut global : ✅ Terminé                   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ ÉTAPE 1 : PDF → Images                     ✅ OK    │   │
│  │                                                       │   │
│  │   Outil : Pdftoppm                                   │   │
│  │   Config : 300 DPI, format PNG                       │   │
│  │   Durée : 2.3s                                       │   │
│  │   Résultat : 15 images générées (12.4 MB)           │   │
│  │                                                       │   │
│  │   [👁️ Voir le résultat]                              │   │
│  │                                                       │   │
│  │   ── Changer l'outil ──────────────────────────     │   │
│  │   ○ Pdftoppm (actuel)                                │   │
│  │   ○ ImageMagick                                      │   │
│  │                                                       │   │
│  │   [🔄 Relancer cette étape]                          │   │
│  └─────────────────────────────────────────────────────┘   │
│                          ↓                                  │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ ÉTAPE 2 : Images → Markdown                ✅ OK    │   │
│  │                                                       │   │
│  │   Outil : Vision LLM                                 │   │
│  │   Config : llava:13b, temp: 0.3                      │   │
│  │   Durée : 45.2s                                      │   │
│  │   Résultat : 15 234 tokens Markdown                 │   │
│  │                                                       │   │
│  │   [👁️ Voir le résultat]                              │   │
│  │                                                       │   │
│  │   ── Changer l'outil ──────────────────────────     │   │
│  │   ○ Vision LLM - llava:13b (actuel)                 │   │
│  │   ○ Vision LLM - llava:34b                          │   │
│  │                                                       │   │
│  │   [🔄 Relancer cette étape]                          │   │
│  └─────────────────────────────────────────────────────┘   │
│                          ↓                                  │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ ÉTAPE 3 : Markdown → Q/R + Indexation      ✅ OK    │   │
│  │                                                       │   │
│  │   Outil : Q/R Atomique                               │   │
│  │   Config : seuil 1500 chars, mistral:7b              │   │
│  │   Durée : 23.1s                                      │   │
│  │   Résultat : 47 chunks, 142 points Qdrant           │   │
│  │                                                       │   │
│  │   [👁️ Voir le résultat]                              │   │
│  │                                                       │   │
│  │   ── Changer l'outil ──────────────────────────     │   │
│  │   ○ Q/R Atomique (actuel)                            │   │
│  │                                                       │   │
│  │   [🔄 Relancer cette étape]                          │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  [🔄 Relancer le pipeline complet]                         │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

#### 4.3.4 Popup "Voir le résultat" par étape

| Étape | Contenu de la popup |
|-------|---------------------|
| **PDF → Images** | Galerie des images générées (miniatures cliquables, zoom) |
| **Image → Markdown** | Texte Markdown avec syntax highlighting |
| **HTML → Markdown** | Texte Markdown avec syntax highlighting |
| **Markdown → Q/R** | Liste des chunks avec leurs Q/R générées |

#### 4.3.5 Workflow de relance d'une étape

```
Utilisateur clique [🔄 Relancer cette étape]
              ↓
    Job async lancé pour cette étape
              ↓
    Interface affiche "⏳ En cours..."
              ↓
    Job terminé, nouveau résultat stocké
              ↓
    Interface met à jour le statut
              ↓
    Utilisateur peut [👁️ Voir le résultat]
              ↓
    Si OK : [✅ Valider et continuer le pipeline]
    Si KO : Changer d'outil et relancer
              ↓
    "Valider et continuer" lance les étapes suivantes
```

> **Note :** On garde uniquement le dernier résultat. Si le nouveau résultat est moins bon, on rechange l'outil et on relance.

#### 4.3.6 Outils disponibles par étape (V1)

| Étape | Outils disponibles | Par défaut |
|-------|-------------------|------------|
| PDF → Images | pdftoppm | pdftoppm |
| Image → Markdown | Vision LLM (modèles configurés) | Vision LLM (agent) |
| HTML → Markdown | Convertisseur HTML | Convertisseur HTML |
| Markdown → Q/R | Q/R Atomique | Q/R Atomique |

> **Note :** Certaines étapes n'ont qu'un seul outil pour l'instant. La structure est prévue pour ajouter des outils plus tard.

### 4.4 Onglet : Indexation (refait)

Affiche le traitement final (Markdown → Qdrant) avec les données LLM brutes.

```
┌─────────────────────────────────────────────────────────────┐
│  🔍 INDEXATION                                              │
│                                                             │
│  ── Statut ────────────────────────────────────────────    │
│                                                             │
│  État : ✅ Indexé                                           │
│  Indexé le : 29/12/2025 14:45                              │
│  Points Qdrant : 142 (47 chunks × 3 points en moyenne)     │
│                                                             │
│  ── Traitement Q/R Atomique ───────────────────────────    │
│                                                             │
│  Modèle utilisé : mistral:7b                               │
│  Chunks traités : 47 / 47                                  │
│  Chunks utiles (useful: true) : 42                         │
│  Chunks ignorés (useful: false) : 5                        │
│                                                             │
│  ── Catégories générées ───────────────────────────────    │
│                                                             │
│  [PRODUITS] x12  [FACTURATION] x8  [GARANTIES] x5          │
│  [CONTACT] x3    [DIVERS] x14                              │
│                                                             │
│  ── Données brutes LLM par chunk ──────────────────────    │
│                                                             │
│  ▶ Chunk #1 - PRODUITS - useful: ✅ (fermé)                │
│  ▶ Chunk #2 - FACTURATION - useful: ✅ (fermé)             │
│  ▼ Chunk #3 - GARANTIES - useful: ✅ (ouvert)              │
│    ┌───────────────────────────────────────────────────┐   │
│    │ {                                                  │   │
│    │   "useful": true,                                  │   │
│    │   "category": "GARANTIES",                         │   │
│    │   "knowledge_units": [                             │   │
│    │     {                                              │   │
│    │       "question": "Quelle est la durée...",       │   │
│    │       "answer": "La garantie est de 2 ans..."     │   │
│    │     }                                              │   │
│    │   ],                                               │   │
│    │   "summary": "Conditions de garantie...",          │   │
│    │   "raw_content_clean": "..."                       │   │
│    │ }                                                  │   │
│    └───────────────────────────────────────────────────┘   │
│  ▶ Chunk #4 - CONTACT - useful: ✅ (fermé)                 │
│  ▶ Chunk #5 - DIVERS - useful: ❌ (non indexé)            │
│  ...                                                        │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 4.5 Onglet : Chunks (reformaté)

Affiche tous les chunks avec les données complètes retournées par le LLM.

```
┌─────────────────────────────────────────────────────────────┐
│  📦 CHUNKS                                                  │
│                                                             │
│  47 chunks | 142 points Qdrant | 5 non indexés             │
│                                                             │
│  ── Filtres ───────────────────────────────────────────    │
│                                                             │
│  Catégorie : [Toutes ▼]  Utile : [Tous ▼]                  │
│  Recherche : [_______________________]                      │
│                                                             │
│  ── Liste des chunks ──────────────────────────────────    │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ #1 | [PRODUITS] | useful: ✅ | 3 Q/R | 4 points     │   │
│  │ ─────────────────────────────────────────────────── │   │
│  │                                                       │   │
│  │ 📝 Résumé :                                          │   │
│  │ Présentation des gammes de produits disponibles     │   │
│  │                                                       │   │
│  │ ❓ Questions/Réponses générées :                     │   │
│  │                                                       │   │
│  │ Q1: Quels sont les produits disponibles ?           │   │
│  │ R1: Notre gamme comprend des solutions pour...      │   │
│  │                                                       │   │
│  │ Q2: Existe-t-il des packs ?                         │   │
│  │ R2: Oui, nous proposons des packs découverte...     │   │
│  │                                                       │   │
│  │ Q3: Quelles sont les nouveautés 2025 ?              │   │
│  │ R3: Cette année, nous lançons...                    │   │
│  │                                                       │   │
│  │ 📄 Contenu source :                                  │   │
│  │ "Notre gamme de produits comprend des solutions..." │   │
│  │                                                       │   │
│  │ 🔗 Contexte : Catalogue > Produits                  │   │
│  │                                                       │   │
│  │ [✏️ Éditer] [🗑️ Supprimer]                          │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ #5 | [DIVERS] | useful: ❌ | Non indexé             │   │
│  │ ─────────────────────────────────────────────────── │   │
│  │                                                       │   │
│  │ ⚠️ Ce chunk n'a pas été jugé utile par le LLM       │   │
│  │                                                       │   │
│  │ 📄 Contenu source :                                  │   │
│  │ "Copyright 2024 - Tous droits réservés..."          │   │
│  │                                                       │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 5. Pipelines de Traitement

### 5.1 Philosophie : Architecture en cascade

Tous les formats convergent vers **Markdown** comme format pivot avant l'indexation finale.

```
PDF ──────────→ Image ──────────→ Markdown ──────────→ Q/R + Qdrant
                                      ↑
HTML ─────────────────────────────────┤
                                      ↑
Image ────────────────────────────────┘
```

### 5.2 Pipeline Markdown (Pipeline de base)

C'est le pipeline terminal utilisé par tous les autres.

```
┌─────────────────────────────────────────────────────────────┐
│  PIPELINE MARKDOWN                                          │
│                                                             │
│  Source : Fichier .md ou sortie des autres pipelines       │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 1. DÉCOUPE STRUCTURELLE                              │   │
│  │    • Découpe par hiérarchie Markdown (### niveau 3) │   │
│  │    • Si chunk > 1500 chars → découpe par paragraphe │   │
│  │    • Préservation contexte parent (breadcrumbs)     │   │
│  │    • Seuil configurable dans les paramètres         │   │
│  └─────────────────────────────────────────────────────┘   │
│                          ↓                                  │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 2. GÉNÉRATION Q/R (LLM)                              │   │
│  │    • Modèle : Déploiement > Agent > Global          │   │
│  │    • Catégories : utilise DocumentCategory existant │   │
│  │    • Génère : questions, réponses, catégorie,       │   │
│  │      résumé, contenu nettoyé                        │   │
│  │    • Filtre : useful = true/false                   │   │
│  │    • Si nouvelle catégorie → ajout automatique      │   │
│  └─────────────────────────────────────────────────────┘   │
│                          ↓                                  │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 3. INDEXATION QDRANT                                 │   │
│  │    • Si useful=true : N points Q/R + 1 source       │   │
│  │    • Si useful=false : chunk gardé en base,         │   │
│  │      NON indexé dans Qdrant                         │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 5.3 Pipeline HTML

```
┌─────────────────────────────────────────────────────────────┐
│  PIPELINE HTML                                              │
│                                                             │
│  Source : Fichier .html ou résultat de crawl               │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 1. HTML → MARKDOWN                                   │   │
│  │    Outil : Convertisseur existant (déjà implémenté) │   │
│  │    • Nettoyage des balises                          │   │
│  │    • Préservation de la structure (h1, h2, h3...)   │   │
│  │    • Extraction du texte visible                    │   │
│  └─────────────────────────────────────────────────────┘   │
│                          ↓                                  │
│                  [PIPELINE MARKDOWN]                        │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 5.4 Pipeline Images

```
┌─────────────────────────────────────────────────────────────┐
│  PIPELINE IMAGE                                             │
│                                                             │
│  Types : JPG, PNG, GIF, BMP, TIFF, WEBP                    │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 1. IMAGE → MARKDOWN                                  │   │
│  │    Outil : Vision LLM (déjà implémenté)             │   │
│  │    • Modèle : Déploiement > Agent > Global          │   │
│  │    • Génère du Markdown structuré depuis l'image    │   │
│  └─────────────────────────────────────────────────────┘   │
│                          ↓                                  │
│                  [PIPELINE MARKDOWN]                        │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 5.5 Pipeline PDF

```
┌─────────────────────────────────────────────────────────────┐
│  PIPELINE PDF                                               │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 1. PDF → IMAGES                                      │   │
│  │    Outil : pdftoppm                                  │   │
│  │    • Conversion de chaque page en image             │   │
│  │    • Traitement SÉQUENTIEL (page par page)          │   │
│  │    • Résolution : 300 DPI                           │   │
│  │    • Stockage : toutes les images conservées        │   │
│  └─────────────────────────────────────────────────────┘   │
│                          ↓                                  │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 2. IMAGES → MARKDOWN                                 │   │
│  │    • Traitement SÉQUENTIEL de chaque image          │   │
│  │    • Concaténation du Markdown final                │   │
│  └─────────────────────────────────────────────────────┘   │
│                          ↓                                  │
│                  [PIPELINE MARKDOWN]                        │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 5.6 Récapitulatif des pipelines

| Type source | Étape 1 | Étape 2 | Étape 3 |
|-------------|---------|---------|---------|
| **Markdown** | Découpe structurelle | Génération Q/R (LLM) | Indexation Qdrant |
| **HTML** | HTML → Markdown | → Pipeline Markdown | |
| **Image** | Image → Markdown (Vision) | → Pipeline Markdown | |
| **PDF** | PDF → Images | Images → Markdown | → Pipeline Markdown |

---

## 6. Stratégie d'Indexation : Q/R Atomique

### 6.1 Objectif

Transformer les documents en une base de connaissances vectorielle où chaque chunk génère plusieurs "unités de savoir" composées d'un vecteur (question) et d'un texte de réponse pré-rédigé.

### 6.2 Phase 1 : Découpe Structurelle

**Logique de découpe :**
- Découpe selon la hiérarchie Markdown
- Chaque chunk conserve son **ascendance (breadcrumbs)** pour le contexte

**Règles :**
- Découper à chaque titre de niveau 3 (`###`)
- Si un `###` contient > 1500 caractères → découpe par paragraphe
- Seuil configurable dans `/admin/gestion-rag-page`
- Contexte propagé : `Titre 1 > Titre 2 > [Contenu]`

**Implémentation :** PHP Regex ou Parseur Markdown

### 6.3 Phase 2 : Génération de Savoir Synthétique (LLM)

**Catégories :**
- Utiliser la table `DocumentCategory` existante
- Proposer les catégories existantes au LLM dans le prompt
- Si le LLM propose une nouvelle catégorie → l'ajouter automatiquement

**Prompt LLM :**
> "Analyse ce texte et génère des paires Question/Réponse. La réponse doit être autonome et ne pas faire référence au texte (ex: ne pas dire 'Comme indiqué dans le document'). Si le texte n'a aucune valeur informative, réponds useful: false. Catégories existantes : [liste]."

**Format JSON attendu :**

```json
{
  "useful": true,
  "category": "FACTURATION",
  "knowledge_units": [
    {
      "question": "Comment créer un acompte sur Zoombat ?",
      "answer": "Pour créer un acompte, allez dans l'onglet Projets, sélectionnez votre devis validé et cliquez sur 'Générer acompte'."
    },
    {
      "question": "Quel est le pourcentage d'acompte par défaut ?",
      "answer": "Le logiciel propose par défaut 30%, mais vous pouvez modifier ce montant manuellement lors de la génération."
    }
  ],
  "summary": "Procédure de création d'acomptes et gestion des pourcentages.",
  "raw_content_clean": "Texte original nettoyé..."
}
```

### 6.4 Phase 3 : Structuration Qdrant

#### Si `useful: true` → N + 1 points

**Points "Q/R" (1 à N) :**

| Champ | Valeur |
|-------|--------|
| **Vecteur** | `embedding(question)` |
| **Payload** | Voir ci-dessous |

```json
{
  "type": "qa_pair",
  "category": "FACTURATION",
  "display_text": "RÉPONSE_IA",
  "source_doc": "manuel_v2.md",
  "parent_context": "Titre 1 > Titre 2",
  "chunk_id": "uuid-du-chunk",
  "document_id": "uuid-du-document"
}
```

**Point "Référence" (dernier) :**

| Champ | Valeur |
|-------|--------|
| **Vecteur** | `embedding(summary + raw_content_clean)` |
| **Payload** | Voir ci-dessous |

```json
{
  "type": "source_material",
  "category": "FACTURATION",
  "display_text": "TEXTE_ORIGINAL",
  "source_doc": "manuel_v2.md",
  "chunk_id": "uuid-du-chunk",
  "document_id": "uuid-du-document"
}
```

#### Si `useful: false` → 0 points

- Chunk conservé en base de données (pour audit)
- **Non indexé** dans Qdrant
- Visible dans l'interface avec mention "Non indexé"

### 6.5 Utilisation des champs `type` et `category`

| Cas d'usage | Filtre | Bénéfice |
|-------------|--------|----------|
| **Pré-filtre par catégorie** | `category == 'FACTURATION'` | Précision 100% dans un contexte donné |
| **Réponse directe (Chatbot)** | `type == 'qa_pair'` | Réponse prête, pas besoin de rappeler le LLM |
| **Recherche globale** | Tous les types | Résultats plus larges |

### 6.6 Exemple concret

**Markdown source :**
```markdown
# Gestion des Documents
## Factures et Acomptes
### Les Acomptes
Le système Zoombat permet de générer des factures d'acompte. Une fois le devis signé, le bouton "Acompte" apparaît. Vous pouvez choisir entre un montant fixe ou un pourcentage. Attention, l'acompte doit être validé pour être déduit de la facture finale.
```

**Processus :**

1. **Découpe** : Texte sous "Les Acomptes" isolé, contexte = "Gestion des Documents > Factures et Acomptes"

2. **LLM** génère 2 questions :
   - Q1: "Comment générer une facture d'acompte ?" → R1: "Le bouton Acompte apparaît après la signature du devis..."
   - Q2: "Peut-on faire un acompte en montant fixe ?" → R2: "Oui, Zoombat permet de choisir entre montant fixe ou pourcentage..."

3. **Qdrant** :
   - Point 1 : Vecteur(Q1) | Payload(R1, type: "qa_pair")
   - Point 2 : Vecteur(Q2) | Payload(R2, type: "qa_pair")
   - Point 3 : Vecteur(Résumé) | Payload(Texte Brut, type: "source_material")

---

## 7. Import en Masse

### 7.1 Adaptation au nouveau système

```
┌─────────────────────────────────────────────────────────────┐
│  📦 IMPORT EN MASSE                                         │
│                                                             │
│  ── Agent IA (obligatoire) ────────────────────────────    │
│                                                             │
│  [Sélectionner un agent ▼]                                  │
│                                                             │
│  ── Source ────────────────────────────────────────────    │
│                                                             │
│  ○ Fichiers multiples                                       │
│    ┌─────────────────────────────────────────────────┐     │
│    │  Glissez plusieurs fichiers ici (max 100)       │     │
│    │  ou un fichier ZIP                              │     │
│    │  Formats : PDF, HTML, Images, Markdown          │     │
│    └─────────────────────────────────────────────────┘     │
│                                                             │
│  ○ Crawl de site                                           │
│    URL de départ : [https://exemple.com_______]            │
│    Profondeur max : [3 ▼]                                  │
│    Limite pages : [100 ▼]                                  │
│                                                             │
│  ── Configuration pipeline ────────────────────────────    │
│                                                             │
│  ☑ Utiliser les outils par défaut (recommandé)             │
│                                                             │
│  ○ Configuration personnalisée (par type de fichier)       │
│    ▶ PDF : [Configurer...]                                 │
│    ▶ HTML : [Configurer...]                                │
│    ▶ Images : [Configurer...]                              │
│                                                             │
│  [🚀 Lancer l'import]                                      │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 7.2 Comportement

1. **Crawl de site** : utilise les outils par défaut configurés dans `/admin/gestion-rag-page`
2. **Fichiers multiples** :
   - Détecte le type de chaque fichier
   - Applique le pipeline correspondant avec les outils par défaut
   - Pré-remplit le titre avec le nom de fichier
3. **Traitement asynchrone** via jobs Laravel (un job par document)

---

## 8. Architecture Technique

### 8.1 Priorité des modèles LLM

```
Déploiement (config_overlay.model)
    ↓ si non défini
Agent IA (model)
    ↓ si non défini
Config globale (config/ai.php → default_model)
```

### 8.2 Base de données

#### Migration Document

```php
Schema::table('documents', function (Blueprint $table) {
    // Suppression
    $table->dropColumn('extraction_method');
    $table->dropColumn('category');

    // Ajout
    $table->json('pipeline_steps')->nullable();       // Résultats par étape
    $table->string('source_type')->default('file');   // 'file' ou 'url'
});
```

#### Migration DocumentChunk

```php
Schema::table('document_chunks', function (Blueprint $table) {
    // Modification
    // qdrant_point_id → qdrant_point_ids (JSON array)

    // Ajout pour Q/R Atomique
    $table->boolean('useful')->default(true);
    $table->json('knowledge_units')->nullable();      // Q/R générées
    $table->string('parent_context')->nullable();     // Breadcrumbs
    $table->integer('qdrant_points_count')->default(0);
});
```

### 8.3 Structure JSON `pipeline_steps`

```json
{
  "steps": [
    {
      "step_name": "pdf_to_images",
      "tool_used": "pdftoppm",
      "tool_config": {"dpi": 300, "format": "png"},
      "status": "success",
      "started_at": "2025-12-29T14:30:00Z",
      "completed_at": "2025-12-29T14:30:02Z",
      "duration_ms": 2345,
      "input_summary": "15 pages PDF, 3.2MB",
      "output_summary": "15 images, 12MB total",
      "output_path": "storage/pipeline/doc_xxx/step1/",
      "output_data": null,
      "error_message": null,
      "error_trace": null
    },
    {
      "step_name": "images_to_markdown",
      "tool_used": "vision_llm",
      "tool_config": {"model": "llava:13b", "temperature": 0.3},
      "status": "success",
      "output_data": "# Titre\n\nContenu markdown...",
      ...
    },
    {
      "step_name": "markdown_to_qr",
      "tool_used": "qr_atomique",
      "tool_config": {"threshold": 1500, "model": "mistral:7b"},
      "status": "success",
      "output_summary": "47 chunks, 142 points Qdrant",
      ...
    }
  ]
}
```

### 8.4 Jobs par étape (async)

| Job | Description |
|-----|-------------|
| `ProcessPdfToImagesJob` | PDF → Images (pdftoppm) |
| `ProcessImagesToMarkdownJob` | Images → Markdown (Vision LLM, séquentiel) |
| `ProcessHtmlToMarkdownJob` | HTML → Markdown |
| `ProcessMarkdownToQrJob` | Markdown → Découpe + Q/R + Indexation |

**Orchestration :**
- En mode automatique (création/crawl) : chaque job dispatch le suivant
- En mode manuel (relance) : job isolé, attend validation pour continuer

### 8.5 Nouveaux services

| Service | Description |
|---------|-------------|
| `PipelineOrchestratorService` | Orchestre l'exécution du pipeline |
| `MarkdownChunkerService` | Découpe structurelle du Markdown |
| `QrGeneratorService` | Génération Q/R via LLM |
| `QdrantMultiPointService` | Indexation multi-points (Q/R + source) |

---

## 9. Récapitulatif des Décisions

| Sujet | Décision |
|-------|----------|
| Formats supportés | PDF, HTML, Images, Markdown (DOCX/TXT plus tard) |
| `useful: false` | Chunk gardé en base, NON indexé dans Qdrant |
| IDs Qdrant multiples | JSON array `qdrant_point_ids` |
| Priorité modèle LLM | Déploiement > Agent > Global |
| Seuil découpage | 1500 chars par défaut, configurable |
| Catégories | Utilise `DocumentCategory`, enrichissement auto |
| PDF multi-pages | Traitement séquentiel |
| Prévisualisation Q/R | Non, on indexe direct et corrige après |
| Stockage debug | Tout conserver, archivage plus tard |
| Pipeline steps | Jobs async séparés par étape |
| Relance étape | Isolée + validation avant suite |
| Historique résultats | Dernier uniquement |

---

## 10. Fichiers à Modifier/Créer

### 10.1 Fichiers existants à modifier

| Fichier | Modification |
|---------|-------------|
| `app/Filament/Pages/GestionRagPage.php` | Refonte zones dépliables |
| `app/Filament/Resources/DocumentResource.php` | Nouveau formulaire création |
| `app/Filament/Resources/DocumentResource/Pages/EditDocument.php` | Nouveaux onglets |
| `app/Models/Document.php` | Nouveaux champs, suppression anciens |
| `app/Models/DocumentChunk.php` | Nouveaux champs Q/R |
| `app/Jobs/ProcessDocumentJob.php` | Utilise orchestrateur |
| `config/documents.php` | Paramètres pipeline |

### 10.2 Nouveaux fichiers à créer

| Fichier | Description |
|---------|-------------|
| `app/Services/Pipeline/PipelineOrchestratorService.php` | Orchestrateur |
| `app/Services/Pipeline/MarkdownChunkerService.php` | Découpe Markdown |
| `app/Services/Pipeline/QrGeneratorService.php` | Génération Q/R |
| `app/Services/Pipeline/PdfToImagesService.php` | Conversion PDF |
| `app/Jobs/Pipeline/ProcessPdfToImagesJob.php` | Job étape 1 |
| `app/Jobs/Pipeline/ProcessImagesToMarkdownJob.php` | Job étape 2 |
| `app/Jobs/Pipeline/ProcessHtmlToMarkdownJob.php` | Job HTML |
| `app/Jobs/Pipeline/ProcessMarkdownToQrJob.php` | Job final |
| `database/migrations/xxx_refactor_documents_for_pipeline.php` | Migration |

---

## 11. Ordre de Développement

1. **Migration DB** et modèles (Document, DocumentChunk)
2. **Services pipeline** (Orchestrateur, PdfToImages, MarkdownChunker, QrGenerator)
3. **Jobs async** par étape
4. **Page paramétrage** (`/admin/gestion-rag-page`)
5. **Interface création** (`/admin/documents/create`)
6. **Interface édition** (onglets Pipeline, Indexation, Chunks)
7. **Adaptation import masse**
8. **Tests**

---

> **Statut : Cahier des charges validé. Prêt pour développement.**

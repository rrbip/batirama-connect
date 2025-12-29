# Cahier des Charges : Refonte de la Gestion RAG

> **Statut** : En cours de validation
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
- Modèle vision (Ollama / Déploiements)
- Paramètres de température
- Prompt système pour description d'images

#### Zone 2 : Configuration Chunking LLM
- Modèle LLM (Ollama / Déploiements)
- Taille de fenêtre
- Pourcentage de chevauchement
- Prompt système

#### Zone 3 : Configuration Q/R Atomique
- Seuil de caractères pour découpage (défaut: 1500)
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

Affiche le pipeline spécifique au type de document avec pour chaque étape :
- L'outil utilisé pour le traitement
- Le statut (en attente / en cours / terminé / erreur)
- Un bouton **"Voir"** pour afficher le résultat post-traitement dans une popup
- Une **checkbox** pour choisir un outil différent (liste des outils disponibles)

```
┌─────────────────────────────────────────────────────────────┐
│  ⚙️ PIPELINE DE TRAITEMENT                                  │
│                                                             │
│  Type : PDF                                                 │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ ☑ Étape 1 : PDF → Image                    ✅ OK    │   │
│  │   Outil : Pdftoppm                                   │   │
│  │   Durée : 2.3s | 15 pages générées                  │   │
│  │                                        [👁️ Voir]    │   │
│  │                                                       │   │
│  │   Changer l'outil : [Pdftoppm ▼] [ImageMagick]      │   │
│  └─────────────────────────────────────────────────────┘   │
│                    ↓                                        │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ ☑ Étape 2 : Image → Markdown               ✅ OK    │   │
│  │   Outil : Vision LLM (llava:13b)                    │   │
│  │   Durée : 45.2s | 15 234 tokens générés             │   │
│  │                                        [👁️ Voir]    │   │
│  │                                                       │   │
│  │   Changer l'outil : [Vision LLM ▼]                  │   │
│  └─────────────────────────────────────────────────────┘   │
│                    ↓                                        │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ ☑ Étape 3 : Markdown → Indexation          ✅ OK    │   │
│  │   Outil : Q/R Atomique                              │   │
│  │   Durée : 23.1s | 47 chunks, 142 points Qdrant      │   │
│  │                                        [👁️ Voir]    │   │
│  │                                                       │   │
│  │   Changer l'outil : [Q/R Atomique ▼]                │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  [🔄 Relancer le pipeline complet]                         │
│  [🔄 Relancer à partir de l'étape 2]                       │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

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
│  ── Données brutes LLM par chunk ──────────────────────    │
│                                                             │
│  ▶ Chunk #1 - Catégorie: PRODUITS (fermé)                  │
│  ▶ Chunk #2 - Catégorie: FACTURATION (fermé)               │
│  ▼ Chunk #3 - Catégorie: GARANTIES (ouvert)                │
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
│  ▶ Chunk #4 - Catégorie: CONTACT (fermé)                   │
│  ...                                                        │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

**Note** : La stratégie de chunking n'apparaît plus ici, elle fait partie des choix d'outils dans l'onglet Pipeline.

### 4.5 Onglet : Chunks (reformaté)

Affiche tous les chunks avec les données complètes retournées par le LLM.

```
┌─────────────────────────────────────────────────────────────┐
│  📦 CHUNKS                                                  │
│                                                             │
│  47 chunks | 142 points Qdrant                             │
│                                                             │
│  ── Filtres ───────────────────────────────────────────    │
│                                                             │
│  Catégorie : [Toutes ▼]  Utile : [Tous ▼]                  │
│  Recherche : [_______________________]                      │
│                                                             │
│  ── Liste des chunks ──────────────────────────────────    │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ #1 | [PRODUITS] | useful: ✅                         │   │
│  │ ─────────────────────────────────────────────────── │   │
│  │                                                       │   │
│  │ 📝 Résumé :                                          │   │
│  │ Présentation des gammes de produits disponibles     │   │
│  │                                                       │   │
│  │ ❓ Questions/Réponses générées (3) :                 │   │
│  │                                                       │   │
│  │ Q: Quels sont les produits disponibles ?            │   │
│  │ R: Notre gamme comprend des solutions pour...       │   │
│  │                                                       │   │
│  │ Q: Existe-t-il des packs ?                          │   │
│  │ R: Oui, nous proposons des packs découverte...      │   │
│  │                                                       │   │
│  │ Q: Quelles sont les nouveautés 2025 ?               │   │
│  │ R: Cette année, nous lançons...                     │   │
│  │                                                       │   │
│  │ 📄 Contenu source :                                  │   │
│  │ "Notre gamme de produits comprend des solutions..." │   │
│  │                                                       │   │
│  │ 🔗 Contexte parent : Catalogue > Produits           │   │
│  │                                                       │   │
│  │ [✏️ Éditer] [🗑️ Supprimer]                          │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 5. Pipelines de Traitement

### 5.1 Philosophie : Architecture en cascade

Tous les formats convergent vers **Markdown** comme format pivot avant l'indexation finale.

```
PDF ──────────→ Image ──────────→ Markdown ──────────→ Indexation
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
│  └─────────────────────────────────────────────────────┘   │
│                          ↓                                  │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 2. GÉNÉRATION Q/R (LLM)                              │   │
│  │    • Modèle : Agent IA (Ollama) ou Déploiements     │   │
│  │    • Génère : questions, réponses, catégorie,       │   │
│  │      résumé, contenu nettoyé                        │   │
│  │    • Filtre : useful = true/false                   │   │
│  └─────────────────────────────────────────────────────┘   │
│                          ↓                                  │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 3. INDEXATION QDRANT                                 │   │
│  │    • N points Q/R : vecteur(question) + réponse     │   │
│  │    • 1 point référence : vecteur(résumé) + source   │   │
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
│  │    • Modèle : Agent IA ou Déploiements              │   │
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
│  │    Outil : À vérifier (pdftoppm existant ?)         │   │
│  │    • Conversion de chaque page en image             │   │
│  │    • Résolution : 300 DPI recommandé                │   │
│  └─────────────────────────────────────────────────────┘   │
│                          ↓                                  │
│                   [PIPELINE IMAGE]                          │
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
| **PDF** | PDF → Images | → Pipeline Image | → Pipeline Markdown |

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
- Contexte propagé : `Titre 1 > Titre 2 > [Contenu]`

**Implémentation :** PHP Regex ou Parseur Markdown

### 6.3 Phase 2 : Génération de Savoir Synthétique (LLM)

**Prompt LLM :**
> "Analyse ce texte et génère des paires Question/Réponse. La réponse doit être autonome et ne pas faire référence au texte (ex: ne pas dire 'Comme indiqué dans le document'). Si le texte n'a aucune valeur informative, réponds useful: false."

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

Pour un chunk validé (`useful: true`), on crée **N + 1 points** dans Qdrant :

#### Points "Q/R" (1 à N)

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
  "parent_context": "Titre 1 > Titre 2"
}
```

#### Point "Référence" (dernier)

| Champ | Valeur |
|-------|--------|
| **Vecteur** | `embedding(summary + raw_content_clean)` |
| **Payload** | Voir ci-dessous |

```json
{
  "type": "source_material",
  "category": "FACTURATION",
  "display_text": "TEXTE_ORIGINAL",
  "source_doc": "manuel_v2.md"
}
```

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

L'import en masse doit être adapté pour fonctionner avec les nouveaux pipelines.

**Proposition :**

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
│  ── Options ───────────────────────────────────────────    │
│                                                             │
│  ☑ Traitement asynchrone (file d'attente)                  │
│  ☐ Notifier par email à la fin                             │
│                                                             │
│  [🚀 Lancer l'import]                                      │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 7.2 Comportement

1. Pour le **crawl de site** : utilise les outils par défaut configurés dans `/admin/gestion-rag-page`
2. Pour les **fichiers multiples** :
   - Détecte le type de chaque fichier
   - Applique le pipeline correspondant avec les outils par défaut
   - Pré-remplit le titre avec le nom de fichier
3. **Traitement asynchrone** via jobs Laravel

---

## 8. Modifications Techniques

### 8.1 Base de données

```php
// Migration : Adaptation du modèle Document
Schema::table('documents', function (Blueprint $table) {
    // Suppression
    $table->dropColumn('extraction_method');
    $table->dropColumn('category');

    // Ajout
    $table->json('pipeline_config')->nullable();      // Configuration du pipeline
    $table->json('pipeline_results')->nullable();     // Résultats par étape
    $table->string('source_type')->default('file');   // 'file' ou 'url'
});

// Migration : Adaptation du modèle DocumentChunk
Schema::table('document_chunks', function (Blueprint $table) {
    // Ajout pour Q/R Atomique
    $table->boolean('useful')->default(true);
    $table->json('knowledge_units')->nullable();      // Q/R générées
    $table->string('parent_context')->nullable();     // Breadcrumbs
    $table->integer('qdrant_points_count')->default(0);
});
```

### 8.2 Nouveaux services

| Service | Description |
|---------|-------------|
| `PipelineOrchestratorService` | Orchestre l'exécution du pipeline selon le type |
| `MarkdownChunkerService` | Découpe structurelle du Markdown |
| `QrGeneratorService` | Génération Q/R via LLM |
| `QdrantMultiPointService` | Indexation multi-points (Q/R + source) |

### 8.3 Jobs modifiés

| Job | Modification |
|-----|--------------|
| `ProcessDocumentJob` | Appelle le `PipelineOrchestratorService` |
| `IndexDocumentChunksJob` | Supporte la création multi-points |

---

## 9. Questions et Points à Clarifier

Avant de passer au développement, j'ai besoin de clarifications sur les points suivants :

### 9.1 Fichiers DOCX et TXT

**Question :** Les fichiers DOCX et TXT ne sont pas mentionnés dans les pipelines. Comment les traiter ?

**Propositions :**
- **DOCX** → Pipeline dédié DOCX → Markdown (extraction XML) → Pipeline Markdown
- **TXT** → Traité directement comme Markdown (structure plate)

### 9.2 Pipeline PDF multi-pages

**Question :** Pour un PDF de 50 pages, on génère 50 images puis 50 passages Vision LLM. Comment gérer la concaténation ?

**Propositions :**
- Option A : Traiter page par page, concaténer le Markdown final
- Option B : Traiter par lot de N pages
- Option C : Paralléliser les appels Vision

### 9.3 Gestion du `useful: false`

**Question :** Quand le LLM retourne `useful: false`, que fait-on ?

**Propositions :**
- Option A : Ne rien indexer du tout (pas de point Qdrant)
- Option B : Indexer quand même le point "source_material" pour référence
- Option C : Garder le chunk en base mais sans l'indexer (pour audit)

### 9.4 Seuil des 1500 caractères

**Question :** Ce seuil est-il fixe ou configurable dans `/admin/gestion-rag-page` ?

### 9.5 Catégories dynamiques existantes

**Question :** Le système actuel de `DocumentCategory` (table séparée avec usage_count) reste-t-il ? Ou on utilise uniquement la catégorie en string dans le JSON LLM ?

**Considération :** Si on garde les catégories existantes, on peut les proposer au LLM dans le prompt pour cohérence.

### 9.6 Points Qdrant multiples

**Question :** Actuellement `DocumentChunk` a un champ `qdrant_point_id` (un seul ID). Avec N+1 points par chunk, faut-il :
- Option A : Stocker les IDs dans un JSON `qdrant_point_ids`
- Option B : Créer une table de liaison `chunk_qdrant_points`
- Option C : Ne stocker que le premier ID et dériver les autres

### 9.7 Modèle LLM pour Q/R

**Question :** "celui paramétré sur l'agent IA" vs "celui paramétré sur les déploiements" - quelle est la priorité ? Où configure-t-on le fallback ?

### 9.8 Prévisualisation avant indexation

**Question :** Veut-on permettre de prévisualiser les Q/R générées avant l'indexation finale pour validation manuelle ?

### 9.9 Statistiques RAG

**Question :** Doit-on tracker les requêtes impliquant chaque document pour les statistiques d'utilisation (onglet RAG dans ma version précédente) ?

---

## 10. Suggestions d'Améliorations

### 10.1 Mode "Dry Run"

Permettre de lancer le pipeline sans indexation finale pour valider les Q/R générées.

### 10.2 Édition des Q/R

Dans l'onglet Chunks, permettre d'éditer manuellement les questions/réponses générées avant ré-indexation.

### 10.3 Fusion intelligente

Détecter les chunks adjacents avec la même catégorie et proposer une fusion.

### 10.4 Export des Q/R

Exporter les paires Q/R en JSON/CSV pour review externe ou fine-tuning.

### 10.5 Monitoring du pipeline

Dashboard temps réel pour suivre l'avancement des traitements en file d'attente.

---

## 11. Références Techniques

### 11.1 Fichiers existants à modifier

| Fichier | Type de modification |
|---------|---------------------|
| `app/Filament/Pages/GestionRagPage.php` | Refonte complète |
| `app/Filament/Resources/DocumentResource.php` | Refonte formulaires |
| `app/Filament/Resources/DocumentResource/Pages/*` | Refonte onglets |
| `app/Models/Document.php` | Ajout/suppression colonnes |
| `app/Models/DocumentChunk.php` | Nouveaux champs Q/R |
| `app/Jobs/ProcessDocumentJob.php` | Nouvelle orchestration |
| `config/documents.php` | Nouveaux paramètres pipeline |

### 11.2 Nouveaux fichiers à créer

| Fichier | Description |
|---------|-------------|
| `app/Services/Pipeline/PipelineOrchestratorService.php` | Orchestrateur |
| `app/Services/Pipeline/MarkdownChunkerService.php` | Découpe Markdown |
| `app/Services/Pipeline/QrGeneratorService.php` | Génération Q/R |
| `app/Services/Pipeline/PdfToImageService.php` | Conversion PDF |
| `database/migrations/xxx_refactor_documents_for_pipeline.php` | Migration |

---

## 12. Prochaines Étapes

Une fois les questions clarifiées :

1. **Validation** du cahier des charges
2. **Migration DB** et modèles
3. **Services pipeline** (orchestrateur + étapes)
4. **Interface création** (`/admin/documents/create`)
5. **Interface édition** (onglets Pipeline, Indexation, Chunks)
6. **Page paramétrage** (`/admin/gestion-rag-page`)
7. **Adaptation import masse**
8. **Tests et documentation**

---

> **En attente de validation et réponses aux questions avant développement.**

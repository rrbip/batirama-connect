# Marketplace BTP - Notes Préparatoires (DRAFT)

> **Statut** : BROUILLON - Ne pas ajouter à l'index
> **Date** : Décembre 2025
> **Objectif** : Capturer les décisions techniques pour ne pas les oublier

---

## ⚠️ Document de travail

Ce document capture les réflexions et décisions techniques pour le futur développement de la marketplace. Il sera formalisé en cahier des charges complet quand le développement sera planifié.

---

## 1. Contexte

### 1.1 Écosystème Batirama

```
┌─────────────────────────────────────────────────────────────────┐
│                      ÉCOSYSTÈME BATIRAMA                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────────┐     ┌──────────────────┐                 │
│  │   AI-Manager     │     │   Marketplace    │                 │
│  │   (Ce projet)    │     │   BTP (Future)   │                 │
│  │                  │     │                  │                 │
│  │  • Admin Filament│     │  • Catalogue     │                 │
│  │  • API Partners  │     │  • Recherche     │                 │
│  │  • Agents IA     │◄───►│  • Fiches produit│                 │
│  │  • RAG/Qdrant    │     │  • Devis         │                 │
│  └──────────────────┘     │  • SEO optimisé  │                 │
│                           └──────────────────┘                 │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 1.2 Objectifs Marketplace

1. **Catalogue produits BTP** : Matériaux, outillage, équipements
2. **Recherche performante** : Filtres métier, recherche fulltext
3. **Fiches produits SEO** : Référencement Google prioritaire
4. **Intégration IA** : Assistant achat via agents IA
5. **Multi-fournisseurs** : Agrégation de catalogues partenaires

---

## 2. Décision Technique Majeure : SEO First

### 2.1 Pourquoi SEO est prioritaire

- **Acquisition organique** : Le BTP recherche beaucoup sur Google
- **Fiches produits** : Doivent être indexables et riches (schema.org)
- **Long tail keywords** : "prix béton armé m3 2025", "parpaing creux 20x20x50"
- **Concurrence** : Les marketplaces BTP existantes sont bien référencées

### 2.2 Implications techniques

| Besoin SEO | Implication |
|------------|-------------|
| Contenu indexable | SSR obligatoire (pas de SPA pure) |
| URLs propres | `/materiaux/beton/beton-arme-c25-30` |
| Meta tags dynamiques | Title, description, Open Graph par produit |
| Schema.org | Product, Offer, AggregateRating |
| Sitemap XML | Génération automatique |
| Performance | Core Web Vitals (LCP, FID, CLS) |
| Mobile First | Responsive, AMP optionnel |

---

## 3. Options Technologiques Évaluées

### 3.1 Option A : Livewire + Blade (Recommandé)

**Architecture :**
```
Laravel (même projet)
├── /admin/*        → Filament (déjà prévu)
├── /api/*          → API REST (existe)
└── /marketplace/*  → Livewire + Blade (nouveau)
    ├── Catalogue
    ├── Recherche
    ├── Fiches produits
    └── Panier/Devis
```

**Avantages :**
- ✅ SSR natif = SEO excellent
- ✅ Même stack que l'admin (cohérence)
- ✅ Pas de build JS complexe
- ✅ Partage des Models/Services
- ✅ Une seule base de données
- ✅ Déploiement simplifié

**Inconvénients :**
- ⚠️ Moins "moderne" qu'un framework JS
- ⚠️ Interactivité limitée vs React/Vue

**Verdict : RECOMMANDÉ pour V1**

### 3.2 Option B : Nuxt.js (Vue) - Frontend Séparé

**Architecture :**
```
┌─────────────────┐      ┌─────────────────┐
│  Laravel API    │ ◄──► │   Nuxt.js       │
│  (AI-Manager)   │      │  (Marketplace)  │
│                 │      │                 │
│  /api/*         │      │  SSR/SSG        │
│  /admin/*       │      │  SEO optimisé   │
└─────────────────┘      └─────────────────┘
```

**Avantages :**
- ✅ SSR/SSG performant
- ✅ Écosystème Vue mature
- ✅ Séparation des concerns
- ✅ Équipes frontend/backend séparées possibles

**Inconvénients :**
- ⚠️ Deux projets à maintenir
- ⚠️ Duplication de logique
- ⚠️ Complexité déploiement
- ⚠️ Latence API supplémentaire

**Verdict : À considérer si équipe frontend dédiée**

### 3.3 Option C : Next.js (React) - Frontend Séparé

Similaire à Option B mais avec React.

**Verdict : Seulement si préférence React dans l'équipe**

### 3.4 Option D : Inertia.js + Vue/React

**Architecture :**
```
Laravel
├── Inertia.js (pont)
└── Vue/React (frontend)
```

**Avantages :**
- ✅ SPA-like mais avec Laravel
- ✅ Routing Laravel conservé

**Inconvénients :**
- ⚠️ SSR nécessite config supplémentaire
- ⚠️ SEO moins bon par défaut
- ⚠️ Complexité ajoutée vs Livewire

**Verdict : Pas idéal pour SEO prioritaire**

---

## 4. Décision Retenue

### Choix : Option A - Livewire + Blade

**Raisons :**

1. **SEO natif** : HTML complet au premier rendu, parfait pour Google
2. **Cohérence stack** : Même technologie que le reste du projet
3. **Rapidité développement** : Pas besoin d'apprendre nouveau framework
4. **Maintenance simplifiée** : Un seul projet, une seule CI/CD
5. **Coût réduit** : Pas besoin de développeur frontend spécialisé

**Composants prévus :**
```
app/Livewire/Marketplace/
├── Catalog/
│   ├── ProductList.php
│   ├── ProductCard.php
│   ├── ProductFilters.php
│   └── CategoryNav.php
├── Product/
│   ├── ProductDetail.php
│   ├── ProductGallery.php
│   ├── ProductSpecs.php
│   └── RelatedProducts.php
├── Search/
│   ├── SearchBar.php
│   └── SearchResults.php
├── Cart/
│   ├── CartSummary.php
│   └── QuoteRequest.php
└── Shared/
    ├── Breadcrumb.php
    └── Pagination.php
```

---

## 5. Considérations SEO Détaillées

### 5.1 Structure URLs

```
/marketplace                          # Page d'accueil marketplace
/marketplace/categories               # Toutes les catégories
/marketplace/c/{slug}                 # Catégorie (ex: /c/beton)
/marketplace/c/{slug}/{subcat}        # Sous-catégorie
/marketplace/p/{slug}                 # Fiche produit
/marketplace/marques/{slug}           # Page marque
/marketplace/recherche?q=xxx          # Résultats recherche
```

### 5.2 Meta Tags Dynamiques

```php
// Exemple pour fiche produit
<title>{{ $product->name }} - Prix et caractéristiques | Batirama</title>
<meta name="description" content="{{ $product->meta_description }}">
<meta property="og:title" content="{{ $product->name }}">
<meta property="og:image" content="{{ $product->image_url }}">
<link rel="canonical" href="{{ route('marketplace.product', $product->slug) }}">
```

### 5.3 Schema.org (JSON-LD)

```json
{
  "@context": "https://schema.org",
  "@type": "Product",
  "name": "Béton armé C25/30",
  "description": "Béton prêt à l'emploi...",
  "sku": "BAT-C25-30",
  "image": "https://...",
  "brand": {
    "@type": "Brand",
    "name": "Lafarge"
  },
  "offers": {
    "@type": "Offer",
    "price": "95.00",
    "priceCurrency": "EUR",
    "availability": "https://schema.org/InStock"
  }
}
```

### 5.4 Sitemap XML

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://batirama.com/marketplace/p/beton-arme-c25-30</loc>
    <lastmod>2025-12-23</lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.8</priority>
  </url>
  <!-- ... -->
</urlset>
```

---

## 6. Intégration avec AI-Manager

### 6.1 Assistant Achat IA

```
┌─────────────────────────────────────────────────────────────────┐
│                    Fiche Produit                                │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  [Image produit]     Béton armé C25/30                         │
│                      Prix: 95€/m³                               │
│                      ★★★★☆ (127 avis)                          │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ 🤖 Assistant IA                                          │   │
│  │                                                          │   │
│  │ "Quelle quantité de béton pour une dalle de 20m² ?"     │   │
│  │                                                          │   │
│  │ [Agent expert-btp répond avec calcul + recommandation]  │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  [Ajouter au devis]  [Demander conseil]                        │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 6.2 APIs à utiliser

- `POST /api/c/{token}/message` : Chat avec agent IA
- RAG sur collection `agent_btp_ouvrages` : Contexte produits
- Webhooks : Notification de devis aux partenaires

---

## 7. Questions Ouvertes (À résoudre plus tard)

1. **Gestion des stocks** : Temps réel ou batch ?
2. **Multi-vendeurs** : Commission, paiement, logistique ?
3. **Comparateur prix** : Agrégation fournisseurs ?
4. **Avis clients** : Modération, vérification achat ?
5. **Paiement** : Stripe, PayPal, virement BTP ?
6. **Livraison** : Intégration transporteurs BTP ?

---

## 8. Estimation Effort (Très Approximatif)

| Phase | Description | Effort estimé |
|-------|-------------|---------------|
| Setup | Structure Livewire, layouts, routes | 1-2 jours |
| Catalogue | Liste, filtres, catégories | 3-5 jours |
| Fiches produits | Détail, galerie, specs, SEO | 3-4 jours |
| Recherche | Fulltext, filtres avancés | 2-3 jours |
| Panier/Devis | Ajout, modification, envoi | 2-3 jours |
| Intégration IA | Widget chat sur fiches | 2 jours |
| SEO | Sitemap, schema.org, meta | 1-2 jours |
| **Total estimé** | | **15-20 jours** |

*Note : Estimation très approximative, à affiner avec cahier des charges détaillé*

---

## 9. Prochaines Étapes (Quand on sera prêts)

1. [ ] Finaliser l'admin Filament (Phase 1.5 actuelle)
2. [ ] Définir le périmètre exact V1 marketplace
3. [ ] Créer le cahier des charges formel (comme 06_admin_panel.md)
4. [ ] Valider les maquettes/wireframes
5. [ ] Planifier le développement
6. [ ] Ajouter ce document à l'index

---

**Ce document sera transformé en cahier des charges formel quand le développement marketplace sera priorisé.**

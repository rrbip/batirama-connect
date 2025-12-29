# 15 - Catalogues Fabricants

## Objectif

Le système de catalogues fabricants permet aux utilisateurs fabricants de gérer automatiquement leurs catalogues produits via crawl de leur site web. Les produits sont extraits automatiquement depuis les pages crawlées et peuvent être détectés avec leur langue.

**Fonctionnalité clé** : Liaison entre un compte utilisateur fabricant et un WebCrawl pour extraire automatiquement les fiches produits.

---

## 1. Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                       User (Fabricant)                          │
│                  (compte utilisateur fabricant)                 │
└─────────────────────────┬───────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│                    FabricantCatalog                             │
│              (configuration du catalogue)                       │
│                                                                 │
│  • fabricant_id → User                                          │
│  • web_crawl_id → WebCrawl                                      │
│  • extraction_config (patterns produits, LLM, locales)          │
│  • status (pending/crawling/extracting/completed/failed)        │
│  • refresh_frequency (daily/weekly/monthly/manual)              │
└─────────────────────────┬───────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│                    FabricantProduct                             │
│                  (produits extraits)                            │
│                                                                 │
│  • catalog_id → FabricantCatalog                                │
│  • crawl_url_id → WebCrawlUrl (source)                          │
│  • locale (langue détectée)                                     │
│  • sku, ean, name, description, price_ht...                     │
│  • extraction_method (selector/llm/manual)                      │
│  • duplicate_of_id (détection doublons)                         │
└─────────────────────────────────────────────────────────────────┘
```

---

## 2. Modèle FabricantCatalog

### Champs principaux

| Champ | Type | Description |
|-------|------|-------------|
| `uuid` | UUID | Identifiant unique public |
| `fabricant_id` | FK | Utilisateur fabricant propriétaire |
| `web_crawl_id` | FK | Crawl web associé |
| `name` | VARCHAR | Nom du catalogue |
| `description` | TEXT | Description optionnelle |
| `website_url` | VARCHAR | URL du site fabricant |
| `extraction_config` | JSONB | Configuration d'extraction (voir ci-dessous) |
| `status` | VARCHAR | pending / crawling / extracting / completed / failed |
| `products_found` | INT | Nombre de produits trouvés |
| `products_updated` | INT | Nombre de produits mis à jour |
| `products_failed` | INT | Nombre d'échecs d'extraction |
| `last_crawl_at` | TIMESTAMP | Dernier crawl |
| `last_extraction_at` | TIMESTAMP | Dernière extraction |
| `last_error` | TEXT | Dernier message d'erreur |
| `refresh_frequency` | VARCHAR | daily / weekly / monthly / manual |
| `next_refresh_at` | TIMESTAMP | Prochain rafraîchissement planifié |

### Configuration d'extraction

La configuration d'extraction est stockée en JSONB et contient :

```php
[
    'product_url_patterns' => [
        '*/produit/*',
        '*/fiche-technique/*',
        '*/product/*',
        '*/article/*',
    ],
    'use_llm_extraction' => true,
    'selectors' => [
        'name' => 'h1, .product-title, .product-name',
        'price' => '.price, .product-price, [itemprop="price"]',
        'sku' => '.sku, .reference, [itemprop="sku"]',
        'description' => '.description, .product-description, [itemprop="description"]',
        'image' => '.product-image img, .gallery img, [itemprop="image"]',
        'specs' => '.specifications, .technical-specs, .caractéristiques',
    ],
    'locale_detection' => [
        'enabled' => true,
        'methods' => [
            'url' => true,      // Détection via patterns URL (/fr/, /en/, etc.)
            'sku' => true,      // Détection via patterns SKU (-FR, -EN, etc.)
            'content' => true,  // Détection via analyse du contenu
        ],
        'allowed_locales' => [], // Vide = toutes les locales disponibles
        'default_locale' => null, // Forcer une locale spécifique
    ],
]
```

### Statuts du catalogue

| Statut | Description |
|--------|-------------|
| `pending` | En attente de traitement |
| `crawling` | Crawl web en cours |
| `extracting` | Extraction des produits en cours |
| `completed` | Extraction terminée avec succès |
| `failed` | Échec du crawl ou de l'extraction |

### Fréquences de rafraîchissement

| Fréquence | Description |
|-----------|-------------|
| `daily` | Re-crawl automatique quotidien |
| `weekly` | Re-crawl automatique hebdomadaire |
| `monthly` | Re-crawl automatique mensuel |
| `manual` | Pas de rafraîchissement automatique |

---

## 3. Modèle FabricantProduct

### Champs principaux

| Champ | Type | Description |
|-------|------|-------------|
| `uuid` | UUID | Identifiant unique public |
| `catalog_id` | FK | Catalogue parent |
| `crawl_url_id` | FK | URL source du crawl |
| `duplicate_of_id` | FK | Produit original si doublon |
| `locale` | VARCHAR(10) | Langue détectée (fr, en, de, bg...) |
| `sku` | VARCHAR | Référence fabricant |
| `ean` | VARCHAR | Code EAN/GTIN |
| `manufacturer_ref` | VARCHAR | Référence constructeur |
| `name` | VARCHAR | Nom du produit |
| `description` | TEXT | Description longue |
| `short_description` | TEXT | Description courte |
| `brand` | VARCHAR | Marque |
| `category` | VARCHAR | Catégorie |
| `price_ht` | DECIMAL | Prix hors taxes |
| `price_ttc` | DECIMAL | Prix TTC (calculé automatiquement) |
| `tva_rate` | DECIMAL | Taux de TVA (défaut: 20%) |
| `currency` | VARCHAR | Devise (défaut: EUR) |
| `price_unit` | VARCHAR | Unité de prix (m², ml, U, etc.) |
| `availability` | VARCHAR | in_stock / out_of_stock / on_order / discontinued |
| `stock_quantity` | INT | Quantité en stock |
| `min_order_quantity` | INT | Quantité minimum de commande |
| `lead_time` | VARCHAR | Délai de livraison |
| `images` | JSONB | Liste des URLs d'images |
| `main_image_url` | VARCHAR | Image principale |
| `documents` | JSONB | Fiches techniques, PDF |
| `specifications` | JSONB | Caractéristiques techniques |
| `weight_kg` | DECIMAL | Poids en kg |
| `width_cm` | DECIMAL | Largeur en cm |
| `height_cm` | DECIMAL | Hauteur en cm |
| `depth_cm` | DECIMAL | Profondeur en cm |
| `source_url` | VARCHAR | URL de la page source |
| `source_hash` | VARCHAR | Hash du contenu source |
| `extraction_method` | VARCHAR | selector / llm / manual |
| `extraction_confidence` | FLOAT | Confiance de l'extraction (0-1) |
| `status` | VARCHAR | active / inactive / pending_review / archived |
| `is_verified` | BOOLEAN | Vérifié par un humain |
| `verified_at` | TIMESTAMP | Date de vérification |
| `marketplace_visible` | BOOLEAN | Visible sur la marketplace |
| `marketplace_metadata` | JSONB | Métadonnées marketplace |

### Statuts des produits

| Statut | Description |
|--------|-------------|
| `active` | Produit actif et disponible |
| `inactive` | Produit désactivé |
| `pending_review` | En attente de validation humaine |
| `archived` | Produit archivé (doublon ou obsolète) |

---

## 4. Détection des doublons

Le système détecte automatiquement les doublons au sein d'un catalogue. Un produit est considéré comme doublon si :

1. **Même SKU** : Référence fabricant identique
2. **Même EAN** : Code EAN/GTIN identique
3. **Même source_hash** : Contenu extrait identique
4. **Même nom + même locale** : Nom identique dans la même langue

**Important** : Les produits avec des locales différentes ne sont PAS considérés comme des doublons. Ce sont des variantes linguistiques du même produit.

### Statistiques de doublons

```php
FabricantProduct::getDuplicateStats($catalogId);

// Retourne :
[
    'total_products' => 1250,
    'duplicate_skus' => 15,           // Doublons par SKU
    'duplicate_sku_products' => 30,   // Produits concernés
    'duplicate_names' => 25,          // Doublons par nom (même locale)
    'duplicate_name_products' => 50,  // Produits concernés
    'duplicate_hashes' => 5,          // Doublons par hash
    'duplicate_hash_products' => 10,  // Produits concernés
    'language_variants' => 45,        // Produits avec variantes linguistiques
]
```

---

## 5. Jobs de traitement

### ExtractFabricantProductsJob

Extrait les produits depuis les URLs crawlées d'un catalogue.

```php
class ExtractFabricantProductsJob implements ShouldQueue
{
    public int $tries = 3;
    public int $timeout = 3600; // 1 heure

    public function handle(ProductMetadataExtractor $extractor): void
    {
        // Vérifie que le crawl est terminé
        // Traite toutes les URLs
        // Extrait les métadonnées produits
        // Crée/met à jour les FabricantProduct
    }
}
```

### DetectProductLocalesJob

Détecte et met à jour les locales de tous les produits d'un catalogue.

```php
class DetectProductLocalesJob implements ShouldQueue
{
    public int $timeout = 1800; // 30 minutes

    public function handle(LanguageDetector $detector): void
    {
        // Traite les produits par lots de 100
        // Détecte la locale via URL, SKU et contenu
        // Met à jour le champ locale
    }
}
```

---

## 6. Interface d'administration

### Menu

**Chemin** : Marketplace > Catalogues Fabricants

**URL** : `/admin/fabricant-catalogs`

### Actions disponibles

| Action | Description |
|--------|-------------|
| **Créer** | Nouveau catalogue avec configuration |
| **Modifier** | Modifier la configuration d'extraction |
| **Voir** | Détail du catalogue et liste des produits |
| **Lancer le crawl** | Démarre le crawl du site |
| **Extraire produits** | Lance l'extraction des produits |
| **Détecter langues** | Lance la détection des locales |
| **Supprimer** | Supprime le catalogue et ses produits |

### Filtres produits

- Par statut (active, pending_review, archived...)
- Par locale (fr, en, de, bg...)
- Par disponibilité (in_stock, out_of_stock...)
- Par prix (avec/sans prix)
- Par vérification (vérifié/non vérifié)

---

## 7. Workflow complet

### Création d'un catalogue

1. **Sélectionner le fabricant** : Utilisateur avec rôle fabricant
2. **Configurer le crawl** : URL de départ, profondeur, limites
3. **Configurer l'extraction** : Patterns URL produits, sélecteurs CSS
4. **Configurer les locales** : Méthodes de détection, locales autorisées
5. **Planifier** : Fréquence de rafraîchissement

### Traitement

```
1. Création FabricantCatalog (status: pending)
           │
           ▼
2. Lancement du WebCrawl (status: crawling)
           │
           ▼
3. Crawl terminé → ExtractFabricantProductsJob (status: extracting)
           │
           ▼
4. Produits extraits → DetectProductLocalesJob
           │
           ▼
5. Catalogue terminé (status: completed)
```

### Re-crawl automatique

Si `refresh_frequency` != `manual` :

1. Le scheduler vérifie `next_refresh_at`
2. Si date passée, lance un nouveau crawl
3. Les produits sont mis à jour (pas de doublons)
4. `next_refresh_at` est recalculée

---

## 8. Relations avec le système de crawl

Le système utilise l'architecture existante du WebCrawl :

- **WebCrawl** : Cache du site web (HTML, images, PDF)
- **WebCrawlUrl** : URLs individuelles avec contenu stocké
- **FabricantCatalog** : Configuration spécifique fabricant
- **FabricantProduct** : Produits extraits liés aux WebCrawlUrl

Un même WebCrawl peut être utilisé par :
- Un FabricantCatalog (extraction produits)
- Plusieurs AgentWebCrawl (indexation RAG)

---

## 9. Sécurité

- Les credentials de crawl sont chiffrés (`encrypt()`)
- Seul le fabricant propriétaire peut voir son catalogue
- Les administrateurs ont accès à tous les catalogues
- Les produits `marketplace_visible: false` ne sont pas publics

---

## 10. Limitations actuelles

- **Sites JavaScript/SPA** : Non supportés (contenu dynamique)
- **Extraction LLM** : Coûteux en ressources pour gros catalogues
- **Pas de planification** : Le re-crawl automatique n'est pas encore implémenté
- **Validation manuelle** : Les produits `pending_review` nécessitent une action humaine

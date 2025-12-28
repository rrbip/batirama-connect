# 16 - Détection de Langue sur les Documents

## Objectif

Le système de détection de langue permet d'identifier automatiquement la langue des documents crawlés (pages HTML, PDF, etc.) et des produits fabricants. Cette détection permet ensuite de filtrer les contenus par langue lors de l'indexation RAG.

**Fonctionnalités** :
- Détection automatique lors du crawl
- Détection manuelle via bouton d'action
- Filtrage par langue dans l'interface admin
- Sélection des langues à indexer par agent

---

## 1. Service LanguageDetector

Le service `App\Services\Marketplace\LanguageDetector` est le composant central de détection.

### Langues supportées

Le système supporte **80+ langues** organisées par continent :

| Continent | Exemples de langues |
|-----------|---------------------|
| **Europe** | Français, English, Deutsch, Español, Italiano, Nederlands, Polski, Čeština, Български, Hrvatski, Română, Ελληνικά, Türkçe... |
| **Asie** | 中文, 日本語, 한국어, हिन्दी, العربية, עברית, ไทย, Tiếng Việt, Bahasa Indonesia... |
| **Afrique** | Kiswahili, አማርኛ, Hausa, Yorùbá, IsiZulu, Afrikaans... |
| **Amériques** | Português (Brasil), Español (México), English (US), Français (Canada), Kreyòl ayisyen... |
| **Océanie** | English (Australia), English (New Zealand), Te Reo Māori, Gagana Samoa... |

### Méthodes de détection

#### 1. Attribut HTML `lang` (priorité maximale)

```html
<html lang="fr">
<html lang="bg-BG">
<html lang="en-US">
```

La méthode `detectFromHtmlLangAttribute()` extrait l'attribut `lang` de la balise `<html>`.

#### 2. Patterns URL (priorité haute)

```
https://example.com/fr/produit      → fr
https://example.com/en-gb/product   → en
https://example.bg/article          → bg (via TLD .bg)
```

La méthode `detectFromUrl()` analyse :
- Les patterns de chemin (`/fr/`, `/de-de/`, `/english/`, etc.)
- Les TLD de domaine (`.fr`, `.bg`, `.de`, etc.)

#### 3. Patterns SKU (pour les produits)

```
REF-12345-FR    → fr
SKU_EN_4567     → en
PROD/BG/890     → bg
```

La méthode `detectFromSku()` détecte les suffixes/préfixes de langue dans les références.

#### 4. Analyse du contenu (fallback)

```php
$detector->detectFromContent($text);
```

Cette méthode utilise :
- **Détection de script** : Cyrillique (bg, ru, uk), Grec, Arabe, Hébreu, Thai, Hangul, Kanji...
- **Analyse de mots communs** : "le", "la", "и", "на", "the", "and"...

---

## 2. Détection sur les URLs de Crawl

### Modèle WebCrawlUrl

Le champ `locale` (VARCHAR 10) stocke la langue détectée.

```php
// Migration
$table->string('locale', 10)->nullable()->after('content_type')->index();

// Fillable
protected $fillable = [
    // ...
    'locale',
];
```

### Méthodes de détection

```php
class WebCrawlUrl extends Model
{
    /**
     * Détecte la locale du contenu.
     * Pour HTML : attribut lang, patterns URL, analyse contenu.
     * Pour autres : patterns URL, analyse contenu.
     */
    public function detectLocale(): ?string
    {
        $detector = app(LanguageDetector::class);
        $locale = null;

        // Pour HTML, essayer l'attribut lang en priorité
        if ($this->isHtml()) {
            $html = $this->getContent();
            if (!empty($html)) {
                $locale = $detector->detectFromHtmlLangAttribute($html);
            }
        }

        // Patterns URL (tous types de contenu)
        if (!$locale) {
            $locale = $detector->detectFromUrl($this->url);
        }

        // Analyse du contenu texte (fallback)
        if (!$locale) {
            $content = $this->getTextContent();
            if (!empty($content)) {
                $locale = $detector->detectFromContent(mb_substr($content, 0, 5000));
            }
        }

        return $locale;
    }

    /**
     * Détecte et sauvegarde la locale.
     */
    public function detectAndSaveLocale(): ?string
    {
        $locale = $this->detectLocale();
        if ($locale && $locale !== $this->locale) {
            $this->update(['locale' => $locale]);
        }
        return $locale;
    }
}
```

### Détection automatique lors du crawl

Dans `CrawlUrlJob`, après stockage du contenu HTML :

```php
// Détecter la langue du contenu HTML
if ($crawlUrl->isHtml()) {
    $crawlUrl->detectAndSaveLocale();
}
```

---

## 3. Job de détection en masse

### DetectCrawlUrlLocalesJob

Permet de lancer la détection sur toutes les URLs d'un crawl qui n'ont pas encore de locale.

```php
class DetectCrawlUrlLocalesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600; // 1 heure max

    public function __construct(private WebCrawl $crawl)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        // Récupérer les URLs sans locale avec contenu stocké
        $urls = WebCrawlUrl::query()
            ->whereHas('crawls', fn ($q) => $q->where('web_crawls.id', $this->crawl->id))
            ->whereNotNull('storage_path')
            ->whereNull('locale')
            ->cursor(); // Memory efficient

        foreach ($urls as $url) {
            $url->detectAndSaveLocale();
        }
    }
}
```

### Lancement depuis l'interface

Le bouton "Détecter langues" est **toujours visible** dans la page de détail d'un crawl :

```php
Actions\Action::make('detect_locales')
    ->label('Détecter langues')
    ->icon('heroicon-o-language')
    ->color('info')
    ->requiresConfirmation()
    ->action(function () {
        DetectCrawlUrlLocalesJob::dispatch($this->record);
        Notification::make()
            ->title('Détection lancée')
            ->body('La détection des langues est en cours en arrière-plan.')
            ->success()
            ->send();
    }),
```

---

## 4. Filtrage par locale dans l'interface

### Filtre de la liste des URLs

```php
SelectFilter::make('locale')
    ->label('Langue')
    ->multiple()
    ->options(fn () => $this->getLocaleOptionsForFilter())
```

La méthode `getLocaleOptionsForFilter()` récupère dynamiquement les locales présentes dans le crawl :

```php
private function getLocaleOptionsForFilter(): array
{
    $locales = WebCrawlUrl::query()
        ->whereHas('crawls', fn ($q) => $q->where('web_crawls.id', $this->record->id))
        ->whereNotNull('locale')
        ->distinct()
        ->pluck('locale')
        ->toArray();

    $detector = app(LanguageDetector::class);
    $options = [];
    foreach ($locales as $locale) {
        $options[$locale] = $detector->getLocaleName($locale);
    }
    return $options;
}
```

### Colonne dans le tableau

```php
Tables\Columns\TextColumn::make('locale')
    ->label('Langue')
    ->badge()
    ->formatStateUsing(fn ($state) => $state
        ? app(LanguageDetector::class)->getLocaleName($state)
        : null)
    ->sortable(),
```

---

## 5. Filtrage par locale pour l'indexation RAG

### Configuration par agent

Le modèle `AgentWebCrawl` a un champ `allowed_locales` (JSON) :

```php
// Migration
$table->json('allowed_locales')->nullable()->after('content_types');

// Casts
protected $casts = [
    'allowed_locales' => 'array',
];
```

### Vérification lors de l'indexation

```php
class AgentWebCrawl extends Model
{
    /**
     * Vérifie si une locale doit être indexée.
     */
    public function shouldIndexLocale(?string $locale): bool
    {
        $allowedLocales = $this->allowed_locales ?? [];

        // Array vide = toutes les locales autorisées
        if (empty($allowedLocales)) {
            return true;
        }

        // Locale null = inclure (URL sans locale détectée)
        if ($locale === null) {
            return true;
        }

        return in_array($locale, $allowedLocales, true);
    }
}
```

### IndexAgentUrlJob

Le job vérifie la locale avant d'indexer :

```php
// Vérifier la locale
if (!$this->agentConfig->shouldIndexLocale($this->crawlUrl->locale)) {
    $this->markSkipped($urlEntry, 'locale_not_allowed');
    return;
}
```

### Sélection des langues dans le formulaire

Les langues sont présentées par continent avec des checkboxes :

```php
private function getLocaleCheckboxesByContinent(): array
{
    $detector = app(LanguageDetector::class);
    $byContinent = LanguageDetector::getLocalesByContinent();

    $fields = [];
    foreach ($byContinent as $key => $continent) {
        $fields[] = Forms\Components\CheckboxList::make("locales_{$key}")
            ->label($continent['label'])
            ->options($continent['locales'])
            ->columns(4)
            ->gridDirection('row');
    }
    return $fields;
}
```

---

## 6. Détection sur les produits fabricants

### Modèle FabricantProduct

Le champ `locale` stocke la langue détectée.

### Méthodes de détection

```php
class FabricantProduct extends Model
{
    /**
     * Détecte la locale via URL, SKU et contenu.
     */
    public function detectLocale(): ?string
    {
        $detector = app(LanguageDetector::class);
        return $detector->detect(
            $this->source_url,
            $this->sku,
            $this->description ?? $this->name
        );
    }

    /**
     * Détecte et sauvegarde la locale.
     */
    public function detectAndSaveLocale(): void
    {
        $locale = $this->detectLocale();
        if ($locale && $locale !== $this->locale) {
            $this->update(['locale' => $locale]);
        }
    }
}
```

### DetectProductLocalesJob

Détection en masse pour un catalogue :

```php
class DetectProductLocalesJob implements ShouldQueue
{
    public function __construct(
        public FabricantCatalog $catalog,
        public bool $overwrite = false
    ) {}

    public function handle(LanguageDetector $detector): void
    {
        $query = FabricantProduct::where('catalog_id', $this->catalog->id);

        if (!$this->overwrite) {
            $query->whereNull('locale');
        }

        $query->chunkById(100, function ($products) use ($detector) {
            foreach ($products as $product) {
                // Priorité 1: HTML brut (attribut lang)
                if ($product->crawl_url_id) {
                    $rawHtml = $product->crawlUrl?->getContent();
                    if ($rawHtml) {
                        $locale = $detector->detectFromHtmlLangAttribute($rawHtml);
                        if ($locale) {
                            $product->update(['locale' => $locale]);
                            continue;
                        }
                    }
                }

                // Priorité 2: URL, SKU, contenu
                $locale = $detector->detect(
                    $product->source_url,
                    $product->sku,
                    $product->description ?? $product->name
                );

                if ($locale) {
                    $product->update(['locale' => $locale]);
                }
            }
        });
    }
}
```

---

## 7. Priorité de détection (récapitulatif)

### Pour les pages HTML

1. **Attribut `<html lang="xx">`** : Plus fiable (déclaré par le site)
2. **Patterns URL** : `/fr/`, `/en-gb/`, etc.
3. **TLD du domaine** : `.fr`, `.bg`, `.de`
4. **Analyse du contenu** : Mots communs, scripts Unicode

### Pour les documents non-HTML (PDF, etc.)

1. **Patterns URL** : `/fr/`, `/en-gb/`, etc.
2. **TLD du domaine** : `.fr`, `.bg`, `.de`
3. **Analyse du contenu extrait** : Mots communs, scripts

### Pour les produits

1. **Attribut `lang` de la page source** (via WebCrawlUrl)
2. **Patterns URL** de la source
3. **Patterns SKU** : `-FR`, `_EN`, `/BG`
4. **Analyse du contenu** : Nom, description

---

## 8. Gestion des variantes linguistiques

Les produits avec la **même référence mais des locales différentes** ne sont **pas considérés comme des doublons**. Ce sont des variantes linguistiques.

```php
// findPotentialDuplicates() exclut les locales différentes
$query->where(function ($q) {
    $q->where('locale', $this->locale)
      ->orWhereNull('locale');
});

// Statistiques : comptage séparé
'language_variants' => $languageVariants->count(),
```

---

## 9. Performance

### Optimisations

- **Cursor** : Traitement par cursor pour éviter les problèmes mémoire
- **Chunk** : Traitement par lots de 100 pour les produits
- **Limite de contenu** : Analyse limitée aux 5000 premiers caractères
- **Index** : Colonne `locale` indexée pour filtrage rapide

### Durée typique

| Volume | Durée estimée |
|--------|---------------|
| 100 URLs | ~30 secondes |
| 1 000 URLs | ~5 minutes |
| 10 000 URLs | ~45 minutes |

---

## 10. Exemple d'utilisation

### Crawler un site multilingue

1. **Créer le crawl** avec l'URL de départ
2. **Lancer le crawl** (détection automatique pour HTML)
3. **Cliquer "Détecter langues"** pour les documents non-HTML
4. **Filtrer par langue** dans la liste des URLs
5. **Configurer l'agent** : Sélectionner uniquement `fr` et `en`
6. **Lancer l'indexation** : Seules les pages FR et EN sont indexées

### Vérifier la détection

```php
// Via Tinker
$url = WebCrawlUrl::find(123);
$url->detectLocale(); // Retourne 'bg'
$url->getLocaleNameAttribute(); // Retourne 'Български'
```

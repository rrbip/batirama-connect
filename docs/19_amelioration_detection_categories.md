# Amélioration de la détection de catégories RAG

## Problème actuel

La détection de catégorie pour le filtrage RAG est défaillante quand le matching par mots-clés échoue :

```
Question: "comment et pourquoi diagnostiquer un batiment?"
Catégorie attendue: DIAGNOSTIC
Catégorie trouvée (embedding): Configuration des Cookies, Mises à jour, Isolation thermique
```

### Flux actuel

```
Question utilisateur
       ↓
1. Keyword matching (rapide)
   - Cherche si un mot de la question = nom de catégorie
   - Stemming basique ajouté (diagnostiquer → diagnostic)
   - Si trouvé → confiance 90%
       ↓
2. Si pas de match → Embedding matching (lent + peu fiable)
   - Génère embedding de la question
   - Génère embedding de chaque catégorie (juste le nom !)
   - Compare par cosine similarity
   - Seuil: 0.45 (trop bas)
   - Résultat: catégories aléatoires
```

### Pourquoi l'embedding échoue

1. **Noms de catégories trop courts** : "DIAGNOSTIC" = 1 mot → embedding peu représentatif
2. **Pas de description** : L'embedding est basé uniquement sur le nom
3. **Modèle local limité** : nomic-embed-text n'est pas optimal pour le français
4. **Seuil trop permissif** : 0.45 laisse passer des faux positifs

---

## Solution proposée

### Phase 1 : Enrichir les catégories

#### 1.1 Migration base de données

```php
// Ajouter colonne keywords à document_categories
Schema::table('document_categories', function (Blueprint $table) {
    $table->json('keywords')->nullable()->after('description');
    $table->vector('embedding', 768)->nullable()->after('keywords'); // optionnel
});
```

#### 1.2 Commande d'enrichissement

```bash
php artisan category:enrich [--category=ID] [--force]
```

**Processus :**
1. Pour chaque catégorie avec `usage_count > 0`
2. Récupérer 10-20 chunks associés (échantillon représentatif)
3. Envoyer au LLM avec prompt :

```
Tu es un expert en classification de documents.

Voici des extraits de documents classés dans la catégorie "{category_name}":
---
{chunk_samples}
---

Génère :
1. Une description courte (1-2 phrases) de cette catégorie
2. Une liste de 10-15 mots-clés/synonymes qui permettraient de détecter qu'une question concerne cette catégorie

Réponds en JSON :
{
  "description": "...",
  "keywords": ["mot1", "mot2", ...]
}
```

4. Sauvegarder description + keywords en base

#### 1.3 Exemple de résultat attendu

```
Catégorie: DIAGNOSTIC
Description: "Diagnostic technique de bâtiment, inspection de l'état général,
              évaluation des structures et des installations"
Keywords: ["diagnostiquer", "diagnostic", "inspection", "expertise",
           "évaluation", "état des lieux", "contrôle", "vérification",
           "analyser", "examiner", "audit", "bilan"]
```

---

### Phase 2 : Améliorer le CategoryDetectionService

#### 2.1 Nouveau flux de détection

```
Question utilisateur
       ↓
1. Keyword matching ÉTENDU
   - Nom de catégorie
   - + Keywords de la catégorie
   - + Stemming basique
   - Si trouvé → confiance 90%
       ↓
2. Si pas de match → Embedding matching AMÉLIORÉ
   - Embedding de : nom + description + keywords (texte riche)
   - Seuil relevé : 0.55 minimum
   - Si trouvé → confiance = score embedding
       ↓
3. Si toujours pas de match → PAS de filtre catégorie
   - Recherche RAG sans filtre
   - Log warning pour analyse
```

#### 2.2 Code modifié

```php
private function detectByKeywords(string $question, Collection $categories): Collection
{
    $questionLower = Str::lower($question);
    $questionWords = preg_split('/\s+/', $questionLower);
    $matches = collect();

    foreach ($categories as $category) {
        // 1. Match sur le nom (existant)
        // ...

        // 2. NOUVEAU: Match sur les keywords
        $keywords = $category->keywords ?? [];
        foreach ($keywords as $keyword) {
            $keywordLower = Str::lower($keyword);

            // Match exact
            if (in_array($keywordLower, $questionWords)) {
                $matches->push($category);
                break;
            }

            // Match partiel (stemming)
            foreach ($questionWords as $qWord) {
                if (strlen($qWord) >= 4 && strlen($keywordLower) >= 4) {
                    if (Str::contains($qWord, $keywordLower) ||
                        Str::contains($keywordLower, $qWord)) {
                        $matches->push($category);
                        break 2;
                    }
                }
            }
        }
    }

    return $matches->unique('id');
}

private function detectByEmbedding(string $question, Collection $categories): Collection
{
    // Construire texte RICHE pour chaque catégorie
    foreach ($categories as $category) {
        $categoryText = $category->name;

        if ($category->description) {
            $categoryText .= '. ' . $category->description;
        }

        if (!empty($category->keywords)) {
            $categoryText .= '. Mots-clés: ' . implode(', ', $category->keywords);
        }

        // Embedding sur texte enrichi
        $categoryVector = $this->embeddingService->embed($categoryText);
        // ...
    }

    // Seuil relevé
    if ($similarity >= 0.55) { // était 0.45
        // ...
    }
}
```

---

### Phase 3 : Intégration à l'indexation

#### Question : Le LLM d'indexation doit-il connaître les catégories existantes ?

**Option A : Catégorisation libre (actuel)**
- LLM génère la catégorie qu'il veut
- Peut créer des doublons ("Diagnostic" vs "DIAGNOSTIC" vs "Diagnostics")
- Simple mais inconsistant

**Option B : Catégorisation guidée (recommandé)**
- LLM reçoit la liste des catégories existantes + descriptions
- Doit choisir parmi elles OU proposer "NOUVELLE: xxx"
- Plus cohérent, permet de contrôler le vocabulaire

```
Prompt d'indexation modifié:
---
Catégories existantes :
- DIAGNOSTIC : Diagnostic technique de bâtiment, inspection...
- ISOLATION : Isolation thermique et acoustique...
- ELECTRICITE : Installation électrique, normes NF C 15-100...
[...]

Assigne une catégorie à ce chunk.
Choisis parmi les catégories existantes si pertinent.
Si aucune ne convient, propose "NOUVELLE: [nom]".
---
```

#### Workflow de nouvelle catégorie

```
LLM propose "NOUVELLE: Plomberie"
       ↓
1. Créer catégorie en BDD (usage_count = 1)
2. Pas de keywords/description (sera enrichi plus tard)
3. Ou : demander au LLM de générer description + keywords immédiatement
```

---

## Plan d'implémentation

### Étape 1 : Migration + Commande enrichissement
- [ ] Migration `add_keywords_to_document_categories`
- [ ] Commande `category:enrich`
- [ ] Test sur quelques catégories

### Étape 2 : Améliorer CategoryDetectionService
- [ ] Modifier `detectByKeywords()` pour utiliser keywords
- [ ] Modifier `detectByEmbedding()` pour utiliser texte enrichi
- [ ] Relever le seuil à 0.55
- [ ] Ajouter logs détaillés pour debug

### Étape 3 : Intégrer à l'indexation (optionnel)
- [ ] Modifier le prompt d'enrichissement pour inclure liste catégories
- [ ] Gérer création de nouvelles catégories
- [ ] Auto-enrichir les nouvelles catégories

### Étape 4 : UI d'administration
- [ ] Afficher keywords dans Filament (DocumentCategoryResource)
- [ ] Permettre édition manuelle des keywords
- [ ] Bouton "Regénérer description/keywords" par catégorie

---

## Questions ouvertes

1. **Faut-il stocker l'embedding de la catégorie en BDD ?**
   - Pro : Évite de recalculer à chaque requête
   - Con : Doit être regénéré si description/keywords changent

2. **Combien de keywords par catégorie ?**
   - Suggestion : 10-20 mots-clés
   - Trop = faux positifs, trop peu = manque de couverture

3. **Que faire si aucune catégorie détectée ?**
   - Option A : Recherche sans filtre (actuel)
   - Option B : Demander à l'utilisateur de préciser
   - Option C : Utiliser un LLM pour classifier la question

4. **Comment gérer les catégories multi-mots ?**
   - "Isolation thermique" vs "Isolation acoustique"
   - Keywords différents pour chaque sous-catégorie ?

---

## Fichiers concernés

- `app/Services/AI/CategoryDetectionService.php` - Logique de détection
- `app/Models/DocumentCategory.php` - Model avec keywords
- `app/Console/Commands/CategoryEnrichCommand.php` - Nouvelle commande
- `app/Jobs/EnrichDocumentChunksJob.php` - Prompt d'indexation (phase 3)
- `database/migrations/xxx_add_keywords_to_document_categories.php`

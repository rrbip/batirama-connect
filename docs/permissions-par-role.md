# Permissions par Rôle - Admin Filament

> **Statut** : Spécification - En attente de développement
> **Date de création** : 2025-12-30
> **Branche** : `claude/rag-refactor-planning-3F9Bx`

---

## 1. Vue d'ensemble

Ce document définit les permissions d'accès au panel admin Filament selon les rôles utilisateurs.

### 1.1 Rôles existants

| Rôle | Slug | Description |
|------|------|-------------|
| Super Admin | `super-admin` | Accès total à toutes les fonctionnalités |
| Admin | `admin` | Administration générale |
| Fabricant | `fabricant` | Gestion de son catalogue produits |
| Éditeur | `editeur` | Gestion des déploiements d'agents |
| Artisan | `artisan` | Utilisateur final (pas d'accès admin) |
| Particulier | `particulier` | Utilisateur final (pas d'accès admin) |
| Métreur | `metreur` | Utilisateur spécialisé |

### 1.2 Principe d'accès au panel

```php
// App\Models\User::canAccessPanel()
public function canAccessPanel(Panel $panel): bool
{
    // Admins : accès total
    if ($this->hasRole('super-admin') || $this->hasRole('admin')) {
        return true;
    }

    // Fabricants : accès limité à leur catalogue
    if ($this->hasRole('fabricant')) {
        return true;
    }

    // Autres rôles : pas d'accès au panel admin
    return false;
}
```

---

## 2. Matrice des Permissions par Ressource

### 2.1 Légende

- ✅ Accès complet (CRUD)
- 👁️ Lecture seule
- 🔒 Filtré (voit uniquement ses propres données)
- ❌ Pas d'accès

### 2.2 Ressources Administration

| Ressource | Super Admin | Admin | Fabricant |
|-----------|-------------|-------|-----------|
| **Users** | ✅ | ✅ | ❌ |
| **Roles** | ✅ | 👁️ | ❌ |
| **Tenants** | ✅ | ✅ | ❌ |
| **Settings** | ✅ | ✅ | ❌ |
| **Audit Logs** | ✅ | 👁️ | ❌ |

### 2.3 Ressources IA / RAG

| Ressource | Super Admin | Admin | Fabricant |
|-----------|-------------|-------|-----------|
| **AI Agents** | ✅ | ✅ | ❌ |
| **Documents** | ✅ | ✅ | ❌ |
| **Document Categories** | ✅ | ✅ | ❌ |
| **AI Sessions** | ✅ | ✅ | ❌ |
| **Gestion RAG** | ✅ | ✅ | ❌ |

### 2.4 Ressources Marketplace

| Ressource | Super Admin | Admin | Fabricant |
|-----------|-------------|-------|-----------|
| **Fabricant Catalogs** | ✅ | ✅ | 🔒 Ses catalogues |
| **Fabricant Products** | ✅ | ✅ | 🔒 Ses produits |
| **Agent Deployments** | ✅ | ✅ | ❌ |
| **User Editor Links** | ✅ | ✅ | ❌ |

### 2.5 Ressources Crawl

| Ressource | Super Admin | Admin | Fabricant |
|-----------|-------------|-------|-----------|
| **Web Crawls** | ✅ | ✅ | ❌ |

---

## 3. Implémentation Technique

### 3.1 Méthode `canAccess()` sur les ressources

Chaque ressource Filament doit implémenter une méthode `canAccess()` pour contrôler la visibilité :

```php
// Exemple : App\Filament\Resources\UserResource.php

public static function canAccess(): bool
{
    $user = auth()->user();

    // Seuls les admins peuvent gérer les utilisateurs
    return $user->hasRole('super-admin') || $user->hasRole('admin');
}
```

### 3.2 Filtrage des données pour les fabricants

Pour les ressources où le fabricant a un accès filtré, utiliser `getEloquentQuery()` :

```php
// Exemple : App\Filament\Resources\FabricantCatalogResource.php

public static function canAccess(): bool
{
    $user = auth()->user();

    return $user->hasRole('super-admin')
        || $user->hasRole('admin')
        || $user->hasRole('fabricant');
}

public static function getEloquentQuery(): Builder
{
    $query = parent::getEloquentQuery();
    $user = auth()->user();

    // Les fabricants ne voient que leurs propres catalogues
    if ($user->hasRole('fabricant') && !$user->hasRole('admin') && !$user->hasRole('super-admin')) {
        $query->where('fabricant_id', $user->id);
    }

    return $query;
}
```

### 3.3 Liste des ressources à modifier

| Fichier | Action |
|---------|--------|
| `UserResource.php` | Ajouter `canAccess()` → admin only |
| `RoleResource.php` | Ajouter `canAccess()` → admin only |
| `TenantResource.php` | Ajouter `canAccess()` → admin only |
| `AiAgentResource.php` | Ajouter `canAccess()` → admin only |
| `DocumentResource.php` | Ajouter `canAccess()` → admin only |
| `DocumentCategoryResource.php` | Ajouter `canAccess()` → admin only |
| `AiSessionResource.php` | Ajouter `canAccess()` → admin only |
| `WebCrawlResource.php` | Ajouter `canAccess()` → admin only |
| `AgentDeploymentResource.php` | Ajouter `canAccess()` → admin only |
| `UserEditorLinkResource.php` | Ajouter `canAccess()` → admin only |
| `FabricantCatalogResource.php` | Ajouter `canAccess()` + filtre `fabricant_id` |
| `FabricantProductResource.php` | Ajouter `canAccess()` + filtre via catalogue |

### 3.4 Pages personnalisées

| Page | Action |
|------|--------|
| `GestionRagPage.php` | Ajouter `canAccess()` → admin only |
| Autres pages admin | Vérifier et restreindre si nécessaire |

---

## 4. Interface Fabricant

### 4.1 Menu de navigation

Le fabricant ne doit voir que :

```
📦 Mon Catalogue
   └── Mes Produits
   └── Mes Catalogues
```

### 4.2 Dashboard personnalisé (optionnel - v2)

Un dashboard spécifique pour les fabricants pourrait afficher :
- Nombre de produits
- Nombre de catalogues
- Statistiques d'utilisation
- Dernières commandes (si applicable)

---

## 5. Tests à effectuer

### 5.1 Tests de connexion

| Test | Résultat attendu |
|------|------------------|
| Login super-admin | ✅ Accès total |
| Login admin | ✅ Accès total |
| Login fabricant | ✅ Accès limité (catalogue) |
| Login artisan | ❌ Refusé |
| Login particulier | ❌ Refusé |

### 5.2 Tests de visibilité des ressources

| Test | Résultat attendu |
|------|------------------|
| Fabricant accède à /admin/users | ❌ 403 Forbidden |
| Fabricant accède à /admin/fabricant-catalogs | ✅ Voit ses catalogues |
| Fabricant crée un catalogue | ✅ `fabricant_id` = son ID |
| Fabricant modifie catalogue d'un autre | ❌ 404 ou 403 |

### 5.3 Tests de filtrage des données

| Test | Résultat attendu |
|------|------------------|
| Admin liste les catalogues | Voit tous les catalogues |
| Fabricant A liste les catalogues | Voit uniquement ses catalogues |
| Fabricant A accède à l'URL du catalogue de B | ❌ 404 |

---

## 6. Checklist de développement

- [ ] Ajouter `canAccess()` sur toutes les ressources admin-only
- [ ] Ajouter filtrage `getEloquentQuery()` sur `FabricantCatalogResource`
- [ ] Ajouter filtrage sur `FabricantProductResource` (via relation catalogue)
- [ ] Vérifier les pages personnalisées (GestionRagPage, etc.)
- [ ] Tester avec un compte fabricant
- [ ] Tester avec un compte admin
- [ ] Documenter les tests effectués

---

## 7. Notes de sécurité

1. **Ne jamais faire confiance au frontend** - Toutes les vérifications doivent être côté serveur
2. **Vérifier les policies Laravel** - En plus de `canAccess()`, les policies peuvent ajouter une couche de sécurité
3. **Auditer les accès** - Logger les tentatives d'accès non autorisées
4. **Tester les URLs directes** - Un utilisateur peut essayer d'accéder directement à `/admin/users/1/edit`

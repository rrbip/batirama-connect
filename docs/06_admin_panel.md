# Panneau d'Administration - Cahier des Charges

> **Référence** : [00_index.md](./00_index.md)
> **Statut** : Phase 1 Implémentée ✅
> **Version** : 1.1.0
> **Date** : Décembre 2025

---

## 1. Contexte et Objectifs

### 1.1 Situation Actuelle

L'application AI-Manager CMS dispose actuellement :
- ✅ Backend API complet (Partners API, Public Chat API)
- ✅ Services IA fonctionnels (Ollama, Qdrant, RAG)
- ✅ Modèles de données complets (Users, Roles, Agents, Sessions, etc.)
- ✅ Seeders avec données de test (utilisateurs, agents, ouvrages)
- ✅ **Panneau d'administration Filament v3** (Phase 1)

### 1.2 Objectifs du Panneau Admin

1. **Gestion No-Code des Agents IA** : Créer, configurer et tester les agents sans toucher au code
2. **Monitoring des Conversations** : Visualiser les sessions, messages et performances
3. **Apprentissage Supervisé** : Valider/corriger les réponses pour améliorer l'IA
4. **Gestion des Utilisateurs** : Administrer les accès et permissions
5. **Configuration Système** : Gérer les paramètres globaux de l'application

---

## 2. Choix Technologique

### 2.1 Framework Recommandé : Filament v3

| Critère | Filament v3 | Livewire Custom |
|---------|-------------|-----------------|
| Temps de développement | ⭐⭐⭐⭐⭐ Rapide | ⭐⭐ Long |
| Fonctionnalités intégrées | CRUD, Auth, Widgets | À développer |
| Personnalisation | Très flexible | Totale |
| Maintenance | Communauté active | À notre charge |
| Courbe d'apprentissage | Moyenne | Faible (Laravel) |

**Décision** : Filament v3 pour sa rapidité de mise en œuvre et ses fonctionnalités intégrées.

### 2.2 Dépendances à Ajouter

```json
{
    "require": {
        "filament/filament": "^3.2",
        "filament/spatie-laravel-settings-plugin": "^3.2",
        "bezhansalleh/filament-shield": "^3.2"
    }
}
```

---

## 3. Architecture du Panneau Admin

### 3.1 Structure des Fichiers

```
app/
├── Filament/
│   ├── Resources/
│   │   ├── UserResource.php
│   │   ├── RoleResource.php
│   │   ├── AgentResource.php
│   │   ├── AiSessionResource.php
│   │   ├── OuvrageResource.php
│   │   ├── PartnerResource.php
│   │   └── DocumentResource.php
│   ├── Pages/
│   │   ├── Dashboard.php
│   │   ├── AgentTester.php
│   │   └── SystemSettings.php
│   ├── Widgets/
│   │   ├── StatsOverview.php
│   │   ├── SessionsChart.php
│   │   ├── AgentPerformance.php
│   │   └── PendingFeedback.php
│   └── AdminPanelProvider.php
```

### 3.2 URL et Accès

| Route | Description | Accès |
|-------|-------------|-------|
| `/admin` | Tableau de bord | Authentifié |
| `/admin/login` | Page de connexion | Public |
| `/admin/users` | Gestion utilisateurs | Super Admin |
| `/admin/roles` | Gestion rôles | Super Admin |
| `/admin/agents` | Gestion agents IA | Admin |
| `/admin/sessions` | Sessions IA | Admin, Validator |
| `/admin/ouvrages` | Base ouvrages BTP | Admin |
| `/admin/partners` | Partenaires API | Super Admin |

---

## 4. Fonctionnalités Détaillées

### 4.1 Tableau de Bord (Dashboard)

**Widgets à implémenter :**

1. **StatsOverview** - Statistiques globales
   - Nombre total de sessions aujourd'hui/semaine/mois
   - Nombre de messages traités
   - Taux de satisfaction (feedbacks positifs)
   - Agents actifs

2. **SessionsChart** - Graphique des sessions
   - Courbe des sessions par jour (30 derniers jours)
   - Répartition par agent

3. **AgentPerformance** - Performance des agents
   - Temps de réponse moyen par agent
   - Nombre de messages par agent
   - Score de satisfaction par agent

4. **PendingFeedback** - Feedbacks en attente
   - Liste des réponses à valider
   - Accès rapide à la validation

### 4.2 Gestion des Utilisateurs (UserResource)

**Champs :**
- UUID (auto-généré)
- Nom
- Email
- Mot de passe (hashé)
- Tenant (multi-tenant)
- Rôles (relation many-to-many)
- Date de vérification email
- Statut actif/inactif

**Actions :**
- Créer, Modifier, Supprimer (soft delete)
- Réinitialiser mot de passe
- Assigner rôles
- Voir sessions IA de l'utilisateur

### 4.3 Gestion des Rôles (RoleResource)

**Rôles par défaut :**

| Rôle | Slug | Permissions |
|------|------|-------------|
| Super Admin | `super-admin` | Toutes |
| Admin | `admin` | Gestion agents, sessions, ouvrages |
| Validator | `validator` | Validation feedbacks uniquement |
| Viewer | `viewer` | Lecture seule |
| Partner | `partner` | Accès API uniquement |
| Agent User | `agent-user` | Utilisation agents IA |

**Permissions existantes :**
- `manage-users`, `manage-roles`
- `manage-agents`, `manage-prompts`
- `view-sessions`, `manage-sessions`
- `validate-responses`, `manage-learning`
- `manage-ouvrages`, `manage-partners`
- `view-analytics`, `manage-settings`

### 4.4 Gestion des Agents IA (AgentResource)

**Champs éditables :**

```php
[
    'name' => 'Expert BTP',
    'slug' => 'expert-btp',
    'description' => 'Agent spécialisé ouvrages BTP',
    'is_active' => true,

    // Configuration IA
    'model' => 'mistral:7b',
    'system_prompt' => '...', // Éditeur riche
    'temperature' => 0.7,
    'max_tokens' => 2048,

    // Configuration RAG
    'retrieval_mode' => 'SQL_HYDRATION', // ou TEXT_ONLY
    'qdrant_collection' => 'agent_btp_ouvrages',
    'similarity_threshold' => 0.75,
    'max_results' => 5,

    // Configuration visuelle
    'avatar' => '...', // Upload image
    'welcome_message' => 'Bonjour, comment puis-je vous aider ?',
    'placeholder' => 'Posez votre question...',
]
```

**Actions spéciales :**
- **Tester l'agent** : Ouvrir une interface de chat pour tester
- **Réindexer** : Relancer l'indexation Qdrant
- **Voir statistiques** : Performances de cet agent
- **Historique prompts** : Versions précédentes du system_prompt

### 4.5 Monitoring des Sessions (AiSessionResource)

**Vue liste :**
- ID Session
- Agent utilisé
- Utilisateur/Partner
- Nombre de messages
- Durée
- Statut (active, completed, abandoned)
- Date création

**Vue détail :**
- Fil de conversation complet
- Sources RAG utilisées (documents, ouvrages)
- Métriques (temps de réponse, tokens)
- Feedbacks associés

**Filtres :**
- Par agent
- Par période
- Par statut
- Par source (partner, direct)

### 4.6 Gestion des Ouvrages BTP (OuvrageResource)

**Champs :**
- Code unique
- Libellé
- Description
- Unité (m², ml, U, etc.)
- Prix unitaire
- Type (simple, composé)
- Catégorie
- Données techniques (JSON)

**Actions :**
- Import CSV/Excel
- Export
- Réindexer dans Qdrant

### 4.7 Validation des Réponses (Learning)

**Interface de validation :**

1. Liste des messages avec feedback négatif ou en attente
2. Pour chaque message :
   - Question originale
   - Réponse de l'IA
   - Sources utilisées
   - Feedback utilisateur
3. Actions :
   - ✅ Valider la réponse (correct)
   - ✏️ Corriger et sauvegarder (ajoute à learned_responses)
   - ❌ Rejeter (ne pas apprendre)

### 4.8 Page de Test d'Agent (AgentTester)

**Interface interactive :**
- Sélecteur d'agent
- Zone de chat en temps réel
- Affichage des sources RAG utilisées
- Métriques (tokens, temps)
- Mode debug (voir le prompt complet envoyé)

### 4.9 Paramètres Système (SystemSettings)

**Sections :**

1. **Général**
   - Nom de l'application
   - URL de base
   - Timezone

2. **IA & Modèles**
   - Host Ollama
   - Modèle par défaut
   - Modèle d'embeddings
   - Paramètres par défaut (temperature, max_tokens)

3. **Qdrant**
   - Host Qdrant
   - Collections par défaut
   - Seuils de similarité

4. **Webhooks**
   - URLs de callback
   - Secret de signature
   - Événements activés

---

## 5. Sécurité

### 5.1 Authentification

- Login par email/mot de passe
- Sessions sécurisées (Laravel Sanctum)
- Timeout de session configurable
- Protection CSRF

### 5.2 Autorisation

- Middleware Filament Shield pour les permissions
- Vérification des rôles sur chaque ressource
- Audit log des actions admin (optionnel phase 2)

### 5.3 Protection des Données

- Mots de passe hashés (bcrypt)
- Soft delete pour traçabilité
- Pas d'affichage de données sensibles (API keys masquées)

---

## 6. Plan de Développement

### Phase 1 : Fondations (Priorité Haute)

| Tâche | Effort | Description |
|-------|--------|-------------|
| Installation Filament | 1h | composer require + install |
| Configuration AdminPanelProvider | 1h | Branding, navigation, auth |
| UserResource | 2h | CRUD utilisateurs |
| RoleResource | 1h | CRUD rôles avec permissions |
| Dashboard basique | 2h | Widgets stats simples |

**Livrable** : Admin fonctionnel avec gestion users/roles

### Phase 2 : Gestion Agents (Priorité Haute)

| Tâche | Effort | Description |
|-------|--------|-------------|
| AgentResource | 3h | CRUD complet agents |
| AgentTester page | 4h | Interface de test chat |
| SystemPromptVersions | 2h | Historique des prompts |

**Livrable** : Création et test d'agents via l'admin

### Phase 3 : Monitoring (Priorité Moyenne)

| Tâche | Effort | Description |
|-------|--------|-------------|
| AiSessionResource | 3h | Vue sessions avec messages |
| Dashboard avancé | 3h | Graphiques, métriques |
| Filtres et exports | 2h | Filtrage avancé, CSV |

**Livrable** : Suivi complet des conversations IA

### Phase 4 : Apprentissage (Priorité Moyenne)

| Tâche | Effort | Description |
|-------|--------|-------------|
| Interface validation | 4h | Validation/correction réponses |
| Learned responses | 2h | Gestion des réponses apprises |

**Livrable** : Amélioration continue de l'IA

### Phase 5 : Données Métier (Priorité Basse)

| Tâche | Effort | Description |
|-------|--------|-------------|
| OuvrageResource | 2h | CRUD ouvrages |
| Import/Export | 3h | CSV, réindexation |
| PartnerResource | 2h | Gestion partenaires API |
| DocumentResource | 2h | Gestion documents RAG |

**Livrable** : Gestion complète des données

---

## 7. Maquettes Fonctionnelles

### 7.1 Dashboard

```
┌─────────────────────────────────────────────────────────────────┐
│  AI-Manager CMS                              [User ▼] [Logout]  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌────────┐ │
│  │  Sessions    │ │  Messages    │ │ Satisfaction │ │ Agents │ │
│  │    127       │ │    1,543     │ │    87%       │ │   3    │ │
│  │  aujourd'hui │ │  cette sem.  │ │  (positif)   │ │ actifs │ │
│  └──────────────┘ └──────────────┘ └──────────────┘ └────────┘ │
│                                                                 │
│  ┌─────────────────────────────────┐ ┌────────────────────────┐ │
│  │     Sessions (30 jours)         │ │  Feedbacks en attente  │ │
│  │  ▄▄▄                            │ │                        │ │
│  │ ▄███▄▄                          │ │  • "Réponse incorrecte │ │
│  │▄██████▄▄▄                       │ │     sur prix béton"    │ │
│  │███████████▄                     │ │  • "Manque détails     │ │
│  └─────────────────────────────────┘ │     techniques"        │ │
│                                      └────────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
```

### 7.2 Gestion Agent

```
┌─────────────────────────────────────────────────────────────────┐
│  Agents > Expert BTP                         [Tester] [Sauver]  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Informations générales                                         │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ Nom: [Expert BTP                    ]  Slug: [expert-btp  ] ││
│  │ Description: [Agent spécialisé dans les ouvrages BTP      ] ││
│  │ [✓] Actif                                                   ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
│  Configuration IA                                               │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ Modèle: [mistral:7b        ▼]  Temperature: [0.7    ]       ││
│  │ Max Tokens: [2048    ]                                      ││
│  │                                                             ││
│  │ System Prompt:                                              ││
│  │ ┌─────────────────────────────────────────────────────────┐ ││
│  │ │ Tu es un expert en ouvrages du BTP. Tu aides les        │ ││
│  │ │ professionnels à trouver des informations sur les       │ ││
│  │ │ matériaux, les prix et les techniques de construction.  │ ││
│  │ │ ...                                                     │ ││
│  │ └─────────────────────────────────────────────────────────┘ ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
│  Configuration RAG                                              │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ Mode: [SQL_HYDRATION ▼]  Collection: [agent_btp_ouvrages  ] ││
│  │ Seuil similarité: [0.75   ]  Max résultats: [5    ]        ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 7.3 Test d'Agent

```
┌─────────────────────────────────────────────────────────────────┐
│  Tester l'Agent: Expert BTP                            [Fermer] │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────────────────────┐ ┌────────────────────────────┐ │
│  │                             │ │ Sources RAG utilisées:     │ │
│  │  [Bot] Bonjour ! Comment   │ │                            │ │
│  │  puis-je vous aider ?      │ │ • Ouvrage #1234 (0.89)     │ │
│  │                             │ │   "Béton armé fondation"   │ │
│  │  [Vous] Quel est le prix   │ │                            │ │
│  │  du béton armé pour une    │ │ • Ouvrage #1235 (0.82)     │ │
│  │  fondation ?               │ │   "Ferraillage standard"   │ │
│  │                             │ │                            │ │
│  │  [Bot] Le prix du béton    │ ├────────────────────────────┤ │
│  │  armé pour fondation varie │ │ Métriques:                 │ │
│  │  entre 150€ et 200€/m³...  │ │ • Temps: 2.3s              │ │
│  │                             │ │ • Tokens: 847              │ │
│  │                             │ │ • Sources: 2               │ │
│  ├─────────────────────────────┤ └────────────────────────────┘ │
│  │ [Tapez votre message...  ] │                                │
│  │                    [Envoyer]│                                │
│  └─────────────────────────────┘                                │
│                                                                 │
│  [✓] Mode debug (voir prompt complet)                          │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## 8. Critères d'Acceptation

### 8.1 Phase 1 - Fondations ✅ IMPLÉMENTÉE

- [x] L'admin est accessible sur `/admin`
- [x] Le login fonctionne avec les utilisateurs existants
- [x] Les super-admins peuvent gérer les utilisateurs
- [x] Les rôles et permissions sont respectés
- [x] Le dashboard affiche des statistiques basiques
- [x] Journal d'audit des actions admin

### 8.2 Phase 2 - Agents

- [ ] Création d'un nouvel agent via l'interface
- [ ] Modification du system_prompt sauvegardée
- [ ] Test de l'agent dans l'interface intégrée
- [ ] Historique des versions de prompts

### 8.3 Phase 3 - Monitoring

- [ ] Liste des sessions avec filtres
- [ ] Détail d'une session avec tous les messages
- [ ] Graphiques de tendance sur le dashboard
- [ ] Export CSV des sessions

### 8.4 Phase 4 - Apprentissage

- [ ] Liste des feedbacks négatifs
- [ ] Interface de correction des réponses
- [ ] Sauvegarde dans learned_responses
- [ ] Impact visible sur les futures réponses

---

## 9. Risques et Mitigations

| Risque | Impact | Probabilité | Mitigation |
|--------|--------|-------------|------------|
| Conflits avec code existant | Moyen | Faible | Filament isolé dans son namespace |
| Performance dashboard | Moyen | Moyenne | Cache des statistiques |
| Sécurité admin exposé | Haut | Faible | Middleware auth + rate limiting |
| Complexité system_prompt | Moyen | Moyenne | Éditeur avec aide/exemples |

---

## 10. Décisions Prises

| Question | Décision | Notes |
|----------|----------|-------|
| Thème visuel | Default Filament | Personnalisation reportée |
| Multi-langue | FR uniquement | International beaucoup plus tard |
| Audit log | ✅ Oui dès Phase 1 | Implémenté avec trait Auditable |
| 2FA | Production seulement | À implémenter en fin de dev |

---

## Validation

- [x] Cahier des charges validé par le client
- [x] Priorités confirmées
- [x] Phase 1 implémentée

**Commentaires :**

_Phase 1 validée et implémentée le 23 décembre 2025._

---

## 11. Notes d'Implémentation Phase 1

### Fichiers Créés

```
app/
├── Filament/
│   ├── Resources/
│   │   ├── UserResource.php          # CRUD utilisateurs
│   │   ├── UserResource/Pages/       # Pages list/create/edit/view
│   │   ├── RoleResource.php          # CRUD rôles + permissions
│   │   ├── RoleResource/Pages/       # Pages list/create/edit/view
│   │   ├── AuditLogResource.php      # Visualisation logs d'audit
│   │   └── AuditLogResource/Pages/   # Pages list/view
│   └── Widgets/
│       ├── StatsOverview.php         # Stats globales dashboard
│       └── RecentActivity.php        # Dernières actions audit
├── Models/
│   └── AuditLog.php                  # Modèle logs d'audit
├── Traits/
│   └── Auditable.php                 # Trait pour audit automatique
└── Providers/Filament/
    └── AdminPanelProvider.php        # Configuration panneau
```

### Accès Admin

- **URL** : `/admin`
- **Login** : `admin@ai-manager.local` / `password`
- **Rôle requis** : super-admin ou admin (production)

### Fonctionnalités Phase 1

1. **Gestion Utilisateurs**
   - Liste avec recherche et filtres
   - CRUD complet avec soft delete
   - Assignation de rôles multiples
   - Vérification email

2. **Gestion Rôles**
   - Liste avec compteurs (users, permissions)
   - CRUD avec protection rôles système
   - Assignation permissions avec checkboxes

3. **Journal d'Audit**
   - Log automatique create/update/delete
   - Filtrage par action, type, date
   - Visualisation old/new values

4. **Dashboard**
   - Stats: utilisateurs, agents, sessions, messages
   - Tableau activité récente

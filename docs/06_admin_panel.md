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

    // Configuration RAG (globale)
    'retrieval_mode' => 'SQL_HYDRATION', // ou TEXT_ONLY
    'qdrant_collection' => 'agent_btp_ouvrages',
    'similarity_threshold' => 0.75,
    'max_results' => 5,

    // Configuration RAG avancée (par agent, avec fallback sur config globale)
    'min_rag_score' => 0.5,          // Score minimum pour inclure un document RAG
    'max_learned_responses' => 3,     // Nombre max de réponses apprises à inclure
    'learned_min_score' => 0.75,      // Score minimum pour les réponses apprises
    'context_token_limit' => 4000,    // Limite de tokens pour le contexte RAG
    'strict_mode' => false,           // Si true, l'agent ne répond QU'avec les infos du contexte

    // Configuration visuelle
    'avatar' => '...', // Upload image
    'welcome_message' => 'Bonjour, comment puis-je vous aider ?',
    'placeholder' => 'Posez votre question...',
]
```

**Mode Strict (strict_mode)** :
Quand activé, l'agent ajoute des garde-fous dans son prompt pour :
- Ne répondre QU'avec les informations présentes dans le contexte fourni
- Dire "Je n'ai pas cette information" si la réponse n'est pas dans le contexte
- Ne jamais inventer ou extrapoler d'informations
- Citer les sources utilisées pour chaque affirmation

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

### 4.8 Page de Test d'Agent (TestAgent)

**Interface interactive asynchrone :**
- Zone de chat en temps réel avec polling (500ms)
- Affichage du statut de traitement (en file, position, génération...)
- Persistance de session (la dernière session est restaurée automatiquement)
- Bouton "Nouvelle session" pour recommencer
- Contexte RAG affiché sous le message utilisateur (visible même en cas d'erreur)
- Bouton "Réessayer" sur les messages en échec
- Métriques (tokens, temps de génération, modèle utilisé)

**Fonctionnement unifié avec l'API publique :**
- Utilise `dispatchAsync()` comme l'API publique `/c/{token}/message`
- Les messages passent par la queue `ai-messages`
- Visibles dans la page de statut des services IA
- Même comportement de retry et gestion d'erreurs

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

### 7.3 Test d'Agent (Async avec Polling)

```
┌─────────────────────────────────────────────────────────────────┐
│  Console de test             [En file #2 (5s)] [Nouvelle session]│
├─────────────────────────────────────────────────────────────────┤
│  Session: a1b2c3d4                                              │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │                                                             ││
│  │  [Bot] Bonjour ! Comment puis-je vous aider ?       10:32  ││
│  │                                                             ││
│  │  [Vous] Quel est le prix du béton armé ?            10:33  ││
│  │         [📄 Voir le contexte envoyé à l'IA] (5)            ││
│  │                                                             ││
│  │  [Bot] Le prix du béton armé pour fondation         10:33  ││
│  │        varie entre 150€ et 200€/m³...                      ││
│  │        mistral:7b • 847 tokens • 2.3s                      ││
│  │                                                             ││
│  │  [Vous] Et pour un mur porteur ?                    10:35  ││
│  │         [📄 Voir le contexte envoyé à l'IA] (4)            ││
│  │                                                             ││
│  │  [Bot] ⚠️ Erreur de traitement                             ││
│  │        Connection timeout to Ollama                        ││
│  │        [🔄 Réessayer]                                      ││
│  │                                                             ││
│  └─────────────────────────────────────────────────────────────┘│
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ [Tapez votre message...                           ] [Envoyer]││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
```

### 7.4 Popup Modale Contexte IA (Plein Écran)

Le bouton "Voir le contexte envoyé à l'IA" ouvre une modale plein écran :

```
┌─────────────────────────────────────────────────────────────────┐
│  Contexte envoyé à l'IA                                    [✕]  │
│  2 source(s) documentaire(s) • 4 message(s) d'historique        │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ 🔧 1. Prompt système                              [▼ ouvert] │ │
│  ├────────────────────────────────────────────────────────────┤ │
│  │ Tu es un expert BTP. Tu aides les professionnels...        │ │
│  │                                                            │ │
│  │ Consignes:                                                 │ │
│  │ - Réponds de manière concise                              │ │
│  │ - Cite tes sources                                        │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ 🕐 2. Historique de conversation (4 msg)      [▼ ouvert]   │ │
│  │     (fenêtre: 5 échanges max)                              │ │
│  ├────────────────────────────────────────────────────────────┤ │
│  │  [👤 User] Bonjour                              10:30      │ │
│  │  [🤖 Bot] Bonjour ! Comment puis-je...          10:30      │ │
│  │  [👤 User] Prix du béton ?                      10:31      │ │
│  │  [🤖 Bot] Le prix varie entre...                10:32      │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ 📄 3. Documents indexés - RAG (2)             [▼ ouvert]   │ │
│  ├────────────────────────────────────────────────────────────┤ │
│  │  ▸ Document #1 - beton.pdf                      [92%]      │ │
│  │  ▸ Document #2 - tarifs.pdf                     [87%]      │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ 🎓 4. Sources d'apprentissage (1)             [▸ fermé]    │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ 💻 5. Données brutes (JSON)                   [▸ fermé]    │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                  │
├─────────────────────────────────────────────────────────────────┤
│                                                      [Fermer]   │
└─────────────────────────────────────────────────────────────────┘
```

**Légende :**
- Le contexte RAG s'affiche dans une **popup modale plein écran** pour une meilleure lisibilité
- **5 sections** avec couleurs distinctes (émeraude, violet, cyan, ambre, gris)
- Section **Historique de conversation** : affiche la fenêtre glissante de messages
- Texte avec **bon contraste** et **sauts de ligne préservés**
- Chaque section est dépliable indépendamment
- En cas d'erreur, le contexte reste visible pour le debug
- Le statut async (position file, temps) s'affiche uniquement dans l'en-tête
- La session persiste 7 jours et est restaurée automatiquement
- L'UI optimiste affiche le message utilisateur immédiatement

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

---

## 12. Fonctionnalités Avancées (Décembre 2025)

### 12.1 Test d'Agent avec Analyse RAG

La page `/admin/agents/{id}/test` a été enrichie avec :

#### Section "Filtrage par catégorie"
- **Méthode de détection** : keyword ou embedding
- **Confiance** : pourcentage de confiance de la détection
- **Catégories détectées** : liste des catégories identifiées
- **Résultats filtrés/total** : nombre de chunks correspondant à la catégorie
- **Fallback utilisé** : indique si le système a dû compléter avec des résultats non filtrés

#### Section "Rapport pour analyse"
Génère un rapport complet copiable pour debug/analyse :
- Question posée
- Agent utilisé et ses paramètres RAG
- Détails du filtrage par catégorie
- Sources RAG avec scores, catégories, résumés et contenus

### 12.2 Gestion des Chunks

La page `/admin/documents/{id}/chunks` permet maintenant :
- **Affichage des catégories** avec badges colorés
- **Modification de catégorie** pour chaque chunk
- **Affichage des résumés et mots-clés** générés par le LLM
- **Ré-indexation** après modification de catégorie

### 12.3 Configuration Agent RAG

L'onglet "Paramètres RAG" dans `AgentResource` inclut :

| Option | Description |
|--------|-------------|
| `use_category_filtering` | Active le filtrage par catégorie |
| `default_chunk_strategy` | Stratégie de chunking par défaut (incl. `llm_assisted`) |
| `min_rag_score` | Score minimum pour les résultats RAG |

### 12.4 Page de Statut IA

La page `/admin/ai-status-page` affiche maintenant :
- **Queues séparées** : `ai-messages` et `llm-chunking`
- **Bouton Stop/Cancel** pour annuler un job en cours
- **Bouton Delete** pour supprimer un message en échec
- **Navigation par clic** sur les lignes des datatables

### 12.5 Gestion RAG Globale

La page `/admin/gestion-rag` inclut :
- **Navigation cliquable** vers les documents et agents
- **Actions de masse** : tout supprimer avec confirmation
- **Filtrage par agent** et statut d'indexation

### 12.6 Page FAQs - Gestion des Réponses Apprises

**Route** : `/admin/faqs`
**Menu** : Intelligence Artificielle → FAQs

La page FAQs permet de gérer les questions/réponses stockées dans la collection Qdrant `learned_responses`. Ces FAQ sont utilisées par l'IA pour améliorer ses réponses.

#### Fonctionnalités

| Fonctionnalité | Description |
|----------------|-------------|
| **Sélection d'agent** | Dropdown pour filtrer par agent (actualisation automatique) |
| **Recherche** | Recherche en temps réel dans les questions et réponses |
| **Pagination** | Navigation par pages (10 FAQs par page) |
| **Ajout manuel** | Formulaire pour ajouter une Q&A manuellement (admin) |
| **Suppression** | Supprimer une FAQ de la base d'apprentissage (admin) |

#### Sources des FAQs

Les FAQs proviennent de trois sources :
1. **Validation** : Quand un admin valide une réponse IA (badge "Validé")
2. **Correction** : Quand un admin corrige une réponse IA (badge "Validé")
3. **Manuel** : Ajout direct depuis la page FAQs (badge "Manuel")

#### Interface

```
┌─────────────────────────────────────────────────────────────────┐
│  FAQs - Questions/Réponses                                       │
├─────────────────────────────────────────────────────────────────┤
│  ┌──────────────────────────┐  ┌─────────────────────────┐      │
│  │ Agent: [Assistant ▼]    │  │ + Ajouter une FAQ       │      │
│  └──────────────────────────┘  └─────────────────────────┘      │
├─────────────────────────────────────────────────────────────────┤
│  🔍 [Rechercher dans les questions et réponses...]              │
├─────────────────────────────────────────────────────────────────┤
│  Questions/Réponses apprises - Assistant          12 / 45 FAQs  │
├─────────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ Q: Comment fonctionne le parrainage ?        [Manuel] 🗑│    │
│  ├─────────────────────────────────────────────────────────┤    │
│  │ R: Le parrainage permet de bénéficier de...             │    │
│  │    Ajoutée le 27/12/2025 14:30                          │    │
│  └─────────────────────────────────────────────────────────┘    │
│  ...                                                             │
├─────────────────────────────────────────────────────────────────┤
│  Affichage de 1 à 10 sur 12 FAQs                                │
│  [← Précédent] [1] [2] [Suivant →]                              │
└─────────────────────────────────────────────────────────────────┘
```

#### Code source

- **Page** : `App\Filament\Pages\FaqsPage`
- **Vue** : `resources/views/filament/pages/faqs-page.blade.php`
- **Collection Qdrant** : `learned_responses`

### 12.7 Validation = Apprentissage

**Important** : L'action "Valider" dans les sessions IA indexe maintenant automatiquement la réponse dans la base d'apprentissage.

Avant (ancienne logique) :
- "Valider" → Marque comme `validated` (pas d'impact sur les futures réponses)
- "Corriger" → Marque comme `learned` + indexe dans Qdrant

Après (nouvelle logique) :
- "Valider" → Marque comme `learned` + indexe la réponse originale dans Qdrant
- "Corriger" → Marque comme `learned` + indexe la version corrigée dans Qdrant
- "Rejeter" → Marque comme `rejected` (pas d'apprentissage)

Le `LearningService::validate()` appelle maintenant `validateAndLearn()` pour indexer la réponse validée.

### 12.8 Texte Extrait Éditable

La page d'édition d'un document (`/admin/documents/{id}/edit`) permet maintenant :

#### Édition du texte extrait
- Le champ "Texte extrait" est maintenant **éditable**
- Permet de nettoyer le texte avant le chunking
- Utile pour corriger les erreurs d'OCR ou supprimer du contenu non pertinent

#### Action "Re-chunker"
Bouton disponible quand le document a du texte extrait :
- **Re-découpe** le texte sans ré-extraire le document
- Supprime les anciens chunks
- Crée de nouveaux chunks selon la stratégie configurée
- Lance la ré-indexation automatique

Comportement selon la stratégie :
| Stratégie | Comportement |
|-----------|--------------|
| `sentence`, `paragraph`, `fixed` | Chunking synchrone immédiat |
| `llm_assisted` | Job asynchrone sur queue `llm-chunking` |

Workflow typique :
1. Importer un document PDF
2. Vérifier le texte extrait
3. Nettoyer si nécessaire (supprimer headers, footers, etc.)
4. Cliquer sur "Re-chunker" pour appliquer les modifications
## 12. Page État des Services IA (AiStatusPage)

> **Statut** : ✅ IMPLÉMENTÉE
> **Fichier** : `app/Filament/Pages/AiStatusPage.php`
> **URL** : `/admin/ai-status`

### 12.1 Description

Page de monitoring en temps réel de tous les services IA et du système de files d'attente. Permet de superviser l'état de santé de l'infrastructure et d'intervenir en cas de problème.

### 12.2 Services Monitorés

| Service | Indicateurs | Actions |
|---------|-------------|---------|
| **Ollama (LLM)** | Statut, nombre de modèles | Redémarrer, Installer/Supprimer modèles |
| **Qdrant (Vector DB)** | Statut, collections, nombre de points | Redémarrer, Diagnostic |
| **Embedding Service** | Statut, dimension des vecteurs | - |
| **Queue Worker** | Statut, jobs en attente/échoués | Redémarrer |

### 12.3 Gestion des Modèles Ollama

**Fonctionnalités :**
- Liste des modèles installés avec détails (taille, famille, quantization)
- Installation de nouveaux modèles depuis une liste ou nom personnalisé
- Suppression de modèles inutilisés
- Synchronisation de la liste des modèles disponibles

### 12.4 Monitoring Documents RAG

**Statistiques affichées :**
- Total documents
- En attente (pending)
- En traitement (processing)
- Terminés (completed)
- Échoués (failed)
- Indexés dans Qdrant

**Actions :**
- Traiter tous les documents en attente
- Relancer les documents échoués
- Voir les détails d'erreur

### 12.5 Monitoring Messages IA Asynchrones

**Statistiques :**
- Messages en attente/en file/en traitement
- Complétés/échoués aujourd'hui
- Temps moyen de génération

**File d'attente :**
- Position dans la file
- Agent concerné
- Temps d'attente
- Statut de traitement

**Actions :**
- Relancer un message échoué
- Voir le contexte d'erreur complet

### 12.6 Gestion des Jobs Échoués

- Liste des 10 derniers jobs échoués
- Nom du job, queue, message d'erreur
- Actions : Relancer, Supprimer
- Action globale : Vider tous les jobs échoués

### 12.7 Actions Disponibles (Header)

| Action | Description |
|--------|-------------|
| `Actualiser` | Rafraîchir tous les statuts |
| `Traiter documents en attente` | Traitement synchrone des pending |
| `Relancer tous les échecs` | Relance tous les documents failed |
| `Vider les jobs échoués` | Supprime tous les failed_jobs |
| `Diagnostic Qdrant` | Affiche le détail de chaque collection |

### 12.8 Maquette

```
┌─────────────────────────────────────────────────────────────────┐
│  État des Services IA                      [Actualiser] [...]   │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────────┐ ┌──────────────────┐ ┌──────────────────┐ │
│  │ 🟢 Ollama (LLM)  │ │ 🟢 Qdrant        │ │ 🟢 Embedding     │ │
│  │ 3 modèle(s)      │ │ 2 collections    │ │ Dimension: 768   │ │
│  │ [Redémarrer]     │ │ 1,234 points     │ │                  │ │
│  └──────────────────┘ └──────────────────┘ └──────────────────┘ │
│                                                                 │
│  ═══════════════════════════════════════════════════════════    │
│  📄 Documents RAG                                               │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ Total: 45 | Pending: 2 | Processing: 1 | ✅ 40 | ❌ 2      ││
│  │                                                             ││
│  │ Documents échoués:                                          ││
│  │  • rapport.pdf - Erreur extraction      [🔄 Réessayer]      ││
│  │  • plan.dwg - Format non supporté       [🔄 Réessayer]      ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
│  ═══════════════════════════════════════════════════════════    │
│  🤖 Messages IA (Async)                                         │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ En file: 3 | Traitement: 1 | ✅ 127 aujourd'hui | ❌ 2     ││
│  │ Temps moyen: 2.3s                                           ││
│  │                                                             ││
│  │ File d'attente:                                             ││
│  │  #1 | Expert BTP | queued | 5s                              ││
│  │  #2 | Support    | pending | 12s                            ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
│  ═══════════════════════════════════════════════════════════    │
│  🔧 Modèles Ollama                                              │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ mistral:7b    | 4.1 GB | Q4_0          [🗑️ Supprimer]      ││
│  │ llama3.3:70b  | 40 GB  | Q4_K_M        [🗑️ Supprimer]      ││
│  │ nomic-embed   | 274 MB | embeddings    [🗑️ Supprimer]      ││
│  │                                                             ││
│  │ Installer: [mistral-small    ▼] [📥 Installer]              ││
│  │ Ou: [nom-personnalisé        ] [📥 Installer]               ││
│  └─────────────────────────────────────────────────────────────┘│
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

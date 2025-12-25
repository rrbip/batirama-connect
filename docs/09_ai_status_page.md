# 09 - Page État des Services IA

## Objectif

La page **État des Services IA** (`/admin/ai-status-page`) est un tableau de bord de monitoring permettant de :
- Surveiller l'état des services IA (Ollama, Qdrant, Embedding, Queue Worker)
- Visualiser les statistiques de traitement des documents RAG
- Diagnostiquer et résoudre les problèmes (documents/jobs échoués)
- Redémarrer les services défaillants

## Accès

**Menu** : Intelligence Artificielle → État des Services

**URL** : `/admin/ai-status-page`

**Permissions** : Accessible aux administrateurs

---

## 1. Sections de la page

### 1.1 Services IA

Affiche l'état de chaque service avec un code couleur :

| Statut | Couleur | Description |
|--------|---------|-------------|
| Online | Vert | Service opérationnel |
| Warning | Orange | Service dégradé (ex: jobs bloqués) |
| Offline | Rouge | Service inaccessible |
| Unknown | Gris | État inconnu |

#### Services surveillés

| Service | Description | Redémarrable |
|---------|-------------|--------------|
| **Ollama (LLM)** | Serveur de modèles de langage | Oui (`docker restart ollama`) |
| **Qdrant (Vector DB)** | Base de données vectorielle | Oui (`docker restart qdrant`) |
| **Embedding Service** | Service de génération d'embeddings | Non (dépend d'Ollama) |
| **Queue Worker** | Worker de traitement asynchrone | Oui (`supervisorctl restart`) |

#### Actions disponibles

- **Bouton Redémarrer** : Visible si le service est offline/warning et redémarrable
- Les services non-redémarrables affichent leurs dépendances

### 1.2 File d'attente

Statistiques de la queue Laravel :

- **En attente** : Nombre de jobs dans la queue
- **Échoués** : Nombre de jobs dans `failed_jobs`
- **Driver** : Type de connexion (`sync`, `database`, `redis`)

> **Alerte** : Si le driver est `database`, un message indique la commande pour lancer le worker.

### 1.3 Documents RAG

Vue d'ensemble du pipeline de traitement :

| Métrique | Description |
|----------|-------------|
| Total | Nombre total de documents |
| Indexés | Documents indexés dans Qdrant |
| Échoués | Documents en erreur |

**Barre de progression** visualisant :
- Terminés (vert)
- En cours (orange)
- En attente (gris)
- Échoués (rouge)

### 1.4 Documents en échec

Tableau des 10 derniers documents échoués :

| Colonne | Description |
|---------|-------------|
| Document | Nom du fichier |
| Erreur | Message d'erreur (extensible) |
| Date | Date de l'échec |
| Actions | Bouton "Relancer" |

### 1.5 Jobs en échec

Tableau des 10 derniers jobs échoués :

| Colonne | Description |
|---------|-------------|
| Job | Nom de la classe du job |
| Queue | Nom de la queue |
| Erreur | Message + stacktrace (extensible) |
| Date | Date de l'échec |
| Actions | Relancer / Supprimer |

### 1.6 Collections Qdrant (détaillé)

Section dépliable affichant pour chaque collection vectorielle :

| Métrique | Description |
|----------|-------------|
| **Points** | Nombre de chunks/documents indexés |
| **Vecteurs** | Nombre de vecteurs dans la collection |
| **Statut** | État de la collection (actif/inactif) |

Cette section permet de vérifier :
- Si les documents ont bien été indexés
- Quelle collection contient combien de données
- Si une collection est vide (problème d'indexation)

**Total points** affiché dans le titre de la section pour un aperçu rapide.

### 1.7 Modèles Ollama

Section dépliable permettant de **gérer les modèles LLM** sur le serveur Ollama.

#### Modèles installés

Affiche la liste des modèles actuellement installés avec :
- **Nom** du modèle
- **Taille** sur le disque
- **Bouton supprimer** pour libérer de l'espace disque

> **Attention** : La suppression d'un modèle est définitive. Le modèle devra être retéléchargé si nécessaire.

#### Installation de nouveaux modèles

Deux méthodes disponibles :

1. **Modèles recommandés** : Liste pré-configurée de modèles testés et compatibles
   - Modèles de chat (Llama, Mistral, Gemma, Phi, Qwen...)
   - Modèles d'embedding (Nomic, MXBai, All-MiniLM)
   - Modèles de code (Code Llama, DeepSeek Coder)

2. **Installation personnalisée** : Entrer manuellement le nom d'un modèle depuis [ollama.com/library](https://ollama.com/library)

#### Synchronisation de la liste

Le bouton **"Synchroniser"** met à jour la liste des modèles disponibles depuis :

1. **URL personnalisée** (si configurée) - Format JSON
2. **API Ollama** - Interroge le serveur pour les modèles populaires
3. **Configuration** - Utilise la liste dans `config/ai.php` (fallback)

Les modèles synchronisés sont **cachés 24h**. L'interface affiche :
- Date de dernière synchronisation
- Source utilisée (url, ollama, config)

### 1.8 Messages IA (Async)

Statistiques des messages IA traités de manière asynchrone :

| Métrique | Description |
|----------|-------------|
| En file | Messages en attente de traitement |
| En cours | Messages actuellement traités |
| Complétés (j) | Messages complétés aujourd'hui |
| Échoués (j) | Messages en échec aujourd'hui |
| Temps moyen | Temps de génération moyen |

---

## 2. Actions globales (Header)

| Action | Description | Condition d'affichage |
|--------|-------------|----------------------|
| **Actualiser** | Rafraîchit tous les statuts | Toujours |
| **Traiter documents en attente** | Exécute les jobs en mode synchrone | Si documents pending > 0 |
| **Relancer tous les échecs** | Retraite tous les documents failed | Si documents failed > 0 |
| **Vider les jobs échoués** | Supprime tous les failed_jobs | Si failed_jobs > 0 |

---

## 3. Architecture technique

### 3.1 Fichiers

```
app/Filament/Pages/AiStatusPage.php          # Controller Livewire
resources/views/filament/pages/ai-status-page.blade.php  # Vue Blade
```

### 3.2 Méthodes principales

```php
class AiStatusPage extends Page
{
    // Données
    public array $services = [];
    public array $queueStats = [];
    public array $documentStats = [];
    public array $failedDocuments = [];
    public array $failedJobs = [];

    // Modèles Ollama
    public array $ollamaModels = [];
    public array $availableModels = [];
    public array $lastSyncInfo = [];

    // Vérification des services
    protected function checkServices(): array;
    protected function getQueueStats(): array;
    protected function getDocumentStats(): array;
    protected function getFailedDocuments(): array;
    protected function getFailedJobs(): array;

    // Gestion des modèles Ollama
    protected function loadOllamaModels(): void;
    protected function getAvailableModels(): array;
    public function syncAvailableModels(): void;
    public function deleteOllamaModel(string $modelName): void;
    public function installOllamaModel(?string $modelName = null): void;

    // Actions
    public function refreshStatus(): void;
    public function restartService(string $serviceKey): void;
    public function retryDocument(int $documentId): void;
    public function retryFailedJob(string $uuid): void;
    public function deleteFailedJob(string $uuid): void;
}
```

### 3.3 Détection des problèmes

**Queue Worker bloqué** :
- Vérifie si des jobs sont en attente depuis > 5 minutes
- Affiche un warning avec la durée d'attente

**Embedding Service** :
- Test réel de génération d'embedding avec le mot "test"
- Affiche la dimension du vecteur si OK

---

## 4. Commandes CLI associées

### Traitement manuel des documents

```bash
# Traiter les documents pending + failed
php artisan documents:process

# Seulement les pending
php artisan documents:process --pending

# Seulement les failed
php artisan documents:process --failed

# Un document spécifique
php artisan documents:process --id=5

# Retraiter tous les documents
php artisan documents:process --all
```

### Gestion des queues

```bash
# Lancer un worker
php artisan queue:work --daemon

# Voir les jobs échoués
php artisan queue:failed

# Relancer un job échoué
php artisan queue:retry <uuid>

# Supprimer tous les jobs échoués
php artisan queue:flush
```

---

## 5. Configuration

### Services Docker

Les commandes de redémarrage sont configurées dans `AiStatusPage.php` :

```php
'ollama' => [
    'restart_command' => 'docker restart ollama',
],
'qdrant' => [
    'restart_command' => 'docker restart qdrant',
],
'queue' => [
    'restart_command' => 'supervisorctl restart laravel-worker:*',
],
```

### Variables d'environnement

```env
# Queue
QUEUE_CONNECTION=database  # ou sync, redis

# Ollama
OLLAMA_HOST=ollama
OLLAMA_PORT=11434
OLLAMA_MODELS_LIST_URL=  # URL optionnelle pour synchroniser la liste des modèles

# Qdrant
QDRANT_HOST=qdrant
QDRANT_PORT=6333
```

### Liste des modèles Ollama

La liste des modèles recommandés est configurée dans `config/ai.php` :

```php
'ollama' => [
    'available_models' => [
        'mistral:7b' => [
            'name' => 'Mistral 7B',
            'size' => '~4.1 GB',
            'type' => 'chat',          // chat, embedding, code
            'description' => 'Excellent pour le français',
        ],
        // ...
    ],
],
```

### URL de synchronisation personnalisée

Si `OLLAMA_MODELS_LIST_URL` est configurée, le bouton "Synchroniser" récupérera la liste depuis cette URL.

Format JSON attendu :
```json
{
  "llama3.1:8b": {
    "name": "Llama 3.1 8B",
    "size": "~4.7 GB",
    "type": "chat",
    "description": "Recommandé pour production"
  },
  "nomic-embed-text": {
    "name": "Nomic Embed",
    "size": "~274 MB",
    "type": "embedding",
    "description": "Embeddings, recommandé"
  }
}
```

---

## 6. Dépannage

### Le service Embedding est offline

1. Vérifier que Ollama est online
2. Vérifier que le modèle d'embedding est installé :
   ```bash
   docker exec ollama ollama list
   ```
3. Si le modèle manque :
   ```bash
   docker exec ollama ollama pull nomic-embed-text
   ```

### Les documents restent en "En attente"

1. Vérifier le driver de queue dans `.env`
2. Si `QUEUE_CONNECTION=database`, lancer un worker :
   ```bash
   php artisan queue:work --daemon
   ```
3. Ou utiliser le bouton "Traiter documents en attente" (mode synchrone)

### Erreur "Could not translate host name"

Les services Docker ne sont pas accessibles. Vérifier :
```bash
docker-compose ps
docker-compose logs ollama
docker-compose logs qdrant
```

### Le RAG ne trouve pas les documents

1. **Vérifier la collection** : Dans la section "Collections Qdrant", le nombre de points doit être > 0
2. **Vérifier l'agent** : L'agent doit avoir une `Collection Qdrant` configurée dans ses paramètres
3. **Vérifier les logs** : Activer les logs RAG pour voir les scores de recherche :
   ```bash
   docker compose logs -f app 2>&1 | grep -i "RAG"
   ```
4. **Score minimum** : Le score minimum est configuré dans `config/ai.php` :
   ```php
   'rag' => [
       'min_score' => env('RAG_MIN_SCORE', 0.5), // 50% similaire minimum
   ],
   ```
5. **Retraiter le document** : Si le document a 0 chunks, utiliser le bouton "Retraiter"

### Erreur mémoire lors du traitement PDF

Vérifier que le php.ini personnalisé est utilisé :
```bash
docker compose exec app php -i | grep memory_limit
# Doit afficher: memory_limit => 512M
```

Si la limite est 128M, rebuilder le conteneur :
```bash
docker compose build app queue --no-cache
docker compose up -d
```

### L'installation d'un modèle échoue

1. Vérifier que le nom du modèle est correct sur [ollama.com/library](https://ollama.com/library)
2. Vérifier l'espace disque disponible :
   ```bash
   docker exec ollama df -h /root/.ollama
   ```
3. Vérifier les logs Ollama :
   ```bash
   docker compose logs ollama
   ```

### La suppression d'un modèle ne libère pas d'espace

L'espace est libéré immédiatement sur le serveur Ollama. Si l'espace ne semble pas libéré, vérifier les modèles restants :
```bash
docker exec ollama ollama list
```

### La synchronisation ne trouve pas de nouveaux modèles

1. Si `OLLAMA_MODELS_LIST_URL` est configurée, vérifier que l'URL est accessible et retourne un JSON valide
2. Sinon, la synchronisation utilise l'API Ollama qui vérifie seulement les modèles populaires pré-définis
3. Pour ajouter de nouveaux modèles à la liste, modifier `config/ai.php` → `ollama.available_models`

---

## 7. Évolutions futures

- [ ] Auto-refresh périodique (polling)
- [ ] Notifications push en cas de service down
- [ ] Historique des temps de réponse
- [ ] Graphiques de performance
- [ ] Alertes email/Slack
- [ ] Progression en temps réel du téléchargement des modèles

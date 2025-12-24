# Liste des Tâches - Implémentation Messages IA Asynchrones

> **Document de référence** : [11_async_ai_messages.md](./11_async_ai_messages.md)
> **Estimation totale** : ~15-20 fichiers à créer/modifier

---

## Phase 1 : Base de données et Model

### 1.1 Migration
- [ ] Créer la migration `add_processing_columns_to_ai_messages_table`
  - Fichier : `database/migrations/xxxx_add_processing_columns_to_ai_messages_table.php`
  - Colonnes : `processing_status`, `queued_at`, `processing_started_at`, `processing_completed_at`, `processing_error`, `job_id`, `retry_count`
  - Index sur `processing_status` + `role`
  - Index sur `queued_at`

### 1.2 Modèle AiMessage
- [ ] Modifier `app/Models/AiMessage.php`
  - Ajouter les nouveaux champs dans `$fillable`
  - Ajouter les `$casts` pour les dates
  - Ajouter les constantes de statut
  - Ajouter les scopes (`scopePending()`, `scopeProcessing()`, `scopeFailed()`, `scopeInQueue()`)
  - Ajouter méthode `markAsQueued()`, `markAsProcessing()`, `markAsCompleted()`, `markAsFailed()`

---

## Phase 2 : Job de Traitement

### 2.1 Créer le Job
- [ ] Créer `app/Jobs/ProcessAiMessageJob.php`
  - Propriétés : `$tries = 3`, `$backoff = 30`, `$timeout = 300`
  - Queue dédiée : `ai-messages`
  - Méthode `handle(RagService $ragService)`
  - Méthode `failed(\Throwable $exception)`
  - Méthode `uniqueId()` pour éviter les doublons
  - Méthode `tags()` pour monitoring Horizon

### 2.2 Configuration Queue
- [ ] Modifier `config/queue.php` si nécessaire
- [ ] Créer/modifier la configuration Supervisor pour la queue `ai-messages`

---

## Phase 3 : Services et Dispatcher

### 3.1 Modifier DispatcherService
- [ ] Modifier `app/Services/AI/DispatcherService.php`
  - Ajouter méthode `dispatchAsync()`
  - Garder méthode `dispatch()` synchrone pour rétrocompatibilité
  - Ajouter paramètre `$async = true` par défaut

### 3.2 Modifier les Controllers
- [ ] Modifier `app/Http/Controllers/Api/PublicChatController.php`
  - Méthode `sendMessage()` : utiliser `dispatchAsync()` et retourner le message_id
  - Ajouter le status dans la réponse

---

## Phase 4 : API de Polling

### 4.1 Nouveau Controller ou Routes
- [ ] Créer ou modifier controller pour les endpoints :
  - `GET /api/messages/{uuid}/status` - Récupérer le statut
  - `POST /api/messages/{uuid}/retry` - Relancer un message failed
  - Possiblement dans `app/Http/Controllers/Api/AiMessageController.php`

### 4.2 Routes
- [ ] Modifier `routes/api.php`
  - Ajouter route `messages/{uuid}/status`
  - Ajouter route `messages/{uuid}/retry`

### 4.3 Resource/Response
- [ ] Créer `app/Http/Resources/AiMessageStatusResource.php` (optionnel)

---

## Phase 5 : Page de Monitoring (AiStatusPage)

### 5.1 Nouvelles données
- [ ] Modifier `app/Filament/Pages/AiStatusPage.php`
  - Ajouter propriété `$aiMessageStats`
  - Ajouter propriété `$aiMessageQueue`
  - Ajouter propriété `$failedAiMessages`
  - Ajouter méthode `getAiMessageStats()`
  - Ajouter méthode `getAiMessageQueue()`
  - Ajouter méthode `getFailedAiMessages()`

### 5.2 Actions
- [ ] Ajouter action `retry_ai_message` (relancer un message)
- [ ] Ajouter action `clear_failed_ai_messages` (vider les échecs)
- [ ] Ajouter action `diagnose_ai_queue` (diagnostic)

### 5.3 Vue Blade
- [ ] Modifier `resources/views/filament/pages/ai-status-page.blade.php`
  - Section "Messages IA en cours" avec compteurs
  - Section "File d'attente IA" avec tableau (position, agent, status, temps d'attente)
  - Section "Messages IA échoués" avec détails et bouton retry

---

## Phase 6 : Frontend (Polling)

### 6.1 JavaScript
- [ ] Créer `resources/js/ai-message-poller.js`
  - Class `AiMessagePoller`
  - Méthodes : `start()`, `stop()`, `poll()`
  - Callbacks : `onUpdate`, `onComplete`, `onError`

### 6.2 Vue Chat Public
- [ ] Modifier la vue/composant Livewire du chat public
  - Implémenter le polling après envoi de message
  - Afficher indicateur de chargement avec position dans la queue
  - Afficher l'erreur avec option de retry si failed

---

## Phase 7 : Commandes Artisan

### 7.1 Commande de diagnostic
- [ ] Créer `app/Console/Commands/AiQueueStatusCommand.php`
  - Commande : `php artisan ai:queue-status`
  - Affiche : pending, queued, processing, failed (today)
  - Affiche : oldest pending, avg processing time, fail rate

### 7.2 Commande de nettoyage
- [ ] Créer `app/Console/Commands/AiQueueCleanCommand.php` (optionnel)
  - Commande : `php artisan ai:queue-clean`
  - Supprime les messages failed > X jours

---

## Phase 8 : Tests

### 8.1 Tests Unitaires
- [ ] Créer `tests/Unit/Jobs/ProcessAiMessageJobTest.php`
  - Test dispatch to correct queue
  - Test status updates
  - Test failure handling

### 8.2 Tests Feature
- [ ] Créer `tests/Feature/Api/AiMessageStatusTest.php`
  - Test get status endpoint
  - Test retry endpoint
  - Test queue position calculation

---

## Phase 9 : Documentation

### 9.1 Mise à jour docs
- [ ] ✅ Créer `docs/11_async_ai_messages.md` (fait)
- [ ] ✅ Mettre à jour `docs/00_index.md` (fait)
- [ ] Mettre à jour `docs/09_ai_status_page.md` avec les nouvelles sections

---

## Ordre d'implémentation recommandé

```
1. Migration + Modèle              (Phase 1)        ← Fondation
2. Job ProcessAiMessageJob         (Phase 2)        ← Traitement async
3. DispatcherService.dispatchAsync (Phase 3.1)      ← Orchestration
4. API endpoints status/retry      (Phase 4)        ← Polling backend
5. AiStatusPage sections           (Phase 5)        ← Monitoring admin
6. Frontend polling                (Phase 6)        ← UX client
7. PublicChatController update     (Phase 3.2)      ← Activation
8. Commandes Artisan              (Phase 7)        ← Outils
9. Tests                          (Phase 8)        ← Qualité
```

---

## Checklist de validation

Avant de considérer l'implémentation terminée :

- [ ] Un message envoyé via l'API retourne immédiatement avec `status: queued`
- [ ] Le polling sur `/api/messages/{uuid}/status` fonctionne
- [ ] Le statut passe de `queued` → `processing` → `completed`
- [ ] En cas d'erreur, le statut passe à `failed` avec le message d'erreur
- [ ] La position dans la queue est correctement calculée
- [ ] La page `/admin/ai-status-page` affiche les messages en cours
- [ ] Les messages failed peuvent être relancés
- [ ] Les métriques (temps moyen, taux d'échec) sont visibles
- [ ] Le worker peut être configuré en multi-instance
- [ ] Les tests passent

---

## Notes techniques

### Queue dédiée
La queue `ai-messages` permet de :
- Prioriser les messages IA séparément des autres jobs
- Limiter le nombre de workers IA (éviter de surcharger Ollama)
- Monitorer spécifiquement les performances IA

### Timeout
Le timeout de 300s (5 min) dans le job est supérieur au timeout Ollama (120s) pour permettre :
- Le temps RAG (embedding + recherche Qdrant)
- Les retries internes
- Le logging et la mise à jour du statut

### Rétrocompatibilité
La méthode `dispatch()` synchrone reste disponible pour :
- Les tests automatisés
- Les environnements sans worker
- La migration progressive

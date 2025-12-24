# 11 - Traitement Asynchrone des Messages IA

## Objectif

Transformer le traitement des messages chat IA de **synchrone** à **asynchrone** pour :
- Éviter les timeouts côté client (proxy, navigateur)
- Permettre le monitoring de tous les appels Ollama
- Offrir une visibilité complète sur l'état de chaque message
- Gérer les erreurs de manière robuste

---

## 1. Architecture Actuelle (Synchrone)

```
┌─────────────────────────────────────────────────────────────────┐
│                    FLUX ACTUEL (SYNCHRONE)                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Client ──► Controller ──► DispatcherService ──► RagService     │
│                                                      │           │
│                                           OllamaService::chat()  │
│                                           (BLOQUANT 120s max)    │
│                                                      │           │
│  Client ◄─────────────── Réponse ◄──────────────────┘           │
│                                                                  │
│  ⚠️ PROBLÈMES:                                                   │
│  - Timeout proxy (60s)                                           │
│  - Pas de visibilité sur les requêtes en cours                  │
│  - Erreurs silencieuses                                          │
│  - Impossible de monitorer                                       │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 2. Architecture Cible (Asynchrone)

```
┌─────────────────────────────────────────────────────────────────┐
│                    FLUX CIBLE (ASYNCHRONE)                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Client ──► Controller ──► DispatcherService                    │
│                              │                                   │
│                              ├─► Crée AiMessage (status=pending) │
│                              ├─► Dispatch ProcessAiMessageJob    │
│                              └─► Retourne immédiatement          │
│                                   {message_id, status: "pending"}│
│                                                                  │
│  Client ◄─── Réponse immédiate (< 100ms)                        │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │                    QUEUE WORKER                           │   │
│  ├──────────────────────────────────────────────────────────┤   │
│  │                                                           │   │
│  │  ProcessAiMessageJob                                      │   │
│  │     │                                                     │   │
│  │     ├─► Update status = "processing"                      │   │
│  │     ├─► RagService::query() → Qdrant + Ollama            │   │
│  │     ├─► Update status = "completed" + content            │   │
│  │     └─► Ou status = "failed" + error                     │   │
│  │                                                           │   │
│  └──────────────────────────────────────────────────────────┘   │
│                                                                  │
│  Client ──► GET /messages/{id}/status ──► Polling               │
│  Client ◄─── {status, content, position_in_queue, ...}          │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 3. Schéma de Base de Données

### Modification de la table `ai_messages`

Ajouter les colonnes pour le tracking asynchrone :

```sql
ALTER TABLE ai_messages ADD COLUMN IF NOT EXISTS
    processing_status VARCHAR(20) DEFAULT 'pending';
-- Valeurs : 'pending', 'queued', 'processing', 'completed', 'failed'

ALTER TABLE ai_messages ADD COLUMN IF NOT EXISTS
    queued_at TIMESTAMP NULL;

ALTER TABLE ai_messages ADD COLUMN IF NOT EXISTS
    processing_started_at TIMESTAMP NULL;

ALTER TABLE ai_messages ADD COLUMN IF NOT EXISTS
    processing_completed_at TIMESTAMP NULL;

ALTER TABLE ai_messages ADD COLUMN IF NOT EXISTS
    processing_error TEXT NULL;

ALTER TABLE ai_messages ADD COLUMN IF NOT EXISTS
    job_id VARCHAR(36) NULL;  -- UUID du job Laravel

ALTER TABLE ai_messages ADD COLUMN IF NOT EXISTS
    retry_count INTEGER DEFAULT 0;

-- Index pour les requêtes de monitoring
CREATE INDEX idx_ai_messages_processing_status
    ON ai_messages(processing_status)
    WHERE role = 'assistant';

CREATE INDEX idx_ai_messages_queued
    ON ai_messages(queued_at)
    WHERE processing_status IN ('pending', 'queued', 'processing');
```

### États du message

| Status | Description | Transitions possibles |
|--------|-------------|----------------------|
| `pending` | Message créé, pas encore en queue | → queued |
| `queued` | Job dispatché, en attente de worker | → processing |
| `processing` | Worker en cours de traitement | → completed, failed |
| `completed` | Réponse générée avec succès | (final) |
| `failed` | Erreur lors du traitement | → queued (retry) |

---

## 4. Job de Traitement

### Fichier : `app/Jobs/ProcessAiMessageJob.php`

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiMessage;
use App\Services\AI\RagService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAiMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;        // 30s entre les retries
    public int $timeout = 300;       // 5 minutes max
    public int $maxExceptions = 3;

    public function __construct(
        public AiMessage $message,
        public string $userContent
    ) {
        $this->onQueue('ai-messages');  // Queue dédiée
    }

    public function handle(RagService $ragService): void
    {
        $session = $this->message->session;
        $agent = $session->agent;

        // Marquer comme "processing"
        $this->message->update([
            'processing_status' => 'processing',
            'processing_started_at' => now(),
        ]);

        Log::info('Processing AI message', [
            'message_id' => $this->message->id,
            'session_id' => $session->id,
            'agent' => $agent->slug,
        ]);

        try {
            // Exécuter le RAG complet
            $response = $ragService->query($agent, $this->userContent, $session);

            // Mettre à jour le message avec la réponse
            $this->message->update([
                'content' => $response->content,
                'processing_status' => 'completed',
                'processing_completed_at' => now(),
                'model_used' => $response->model,
                'tokens_prompt' => $response->tokensPrompt,
                'tokens_completion' => $response->tokensCompletion,
                'generation_time_ms' => $response->generationTimeMs,
                'rag_context' => $response->raw['context'] ?? null,
            ]);

            Log::info('AI message processed successfully', [
                'message_id' => $this->message->id,
                'generation_time_ms' => $response->generationTimeMs,
            ]);

        } catch (\Exception $e) {
            Log::error('AI message processing failed', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            throw $e;  // Laisse Laravel gérer les retries
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AI message job failed definitively', [
            'message_id' => $this->message->id,
            'error' => $exception->getMessage(),
        ]);

        $this->message->update([
            'processing_status' => 'failed',
            'processing_completed_at' => now(),
            'processing_error' => $exception->getMessage(),
            'retry_count' => $this->attempts(),
        ]);
    }

    public function uniqueId(): string
    {
        return 'ai-message-' . $this->message->id;
    }

    public function tags(): array
    {
        return [
            'ai-message',
            'message:' . $this->message->id,
            'session:' . $this->message->session_id,
            'agent:' . $this->message->session->agent->slug,
        ];
    }
}
```

---

## 5. Modification du DispatcherService

### Mode Asynchrone

```php
<?php

// Dans DispatcherService.php

/**
 * Dispatch une question de manière asynchrone
 * Retourne immédiatement avec l'ID du message
 */
public function dispatchAsync(
    string $userMessage,
    Agent $agent,
    ?User $user = null,
    ?AiSession $session = null
): AiMessage {
    // Créer ou récupérer la session
    if (!$session) {
        $session = $this->createSession($agent, $user);
    }

    // Sauvegarder le message utilisateur
    $this->ragService->saveMessage($session, 'user', $userMessage);

    // Créer le message assistant en attente
    $assistantMessage = AiMessage::create([
        'uuid' => Str::uuid()->toString(),
        'session_id' => $session->id,
        'role' => 'assistant',
        'content' => '',  // Vide pour l'instant
        'processing_status' => 'pending',
        'created_at' => now(),
    ]);

    // Dispatcher le job
    $job = new ProcessAiMessageJob($assistantMessage, $userMessage);
    dispatch($job);

    // Mettre à jour avec l'ID du job
    $assistantMessage->update([
        'processing_status' => 'queued',
        'queued_at' => now(),
        'job_id' => $job->job?->getJobId() ?? null,
    ]);

    // Incrémenter le compteur
    $session->increment('message_count');

    return $assistantMessage;
}
```

---

## 6. API de Polling

### Endpoint : GET `/api/messages/{uuid}/status`

```php
<?php

// Dans un nouveau controller ou existant

/**
 * Récupère le statut d'un message en cours de traitement
 */
public function getMessageStatus(string $uuid): JsonResponse
{
    $message = AiMessage::where('uuid', $uuid)->firstOrFail();

    // Calculer la position dans la queue si pending/queued
    $queuePosition = null;
    if (in_array($message->processing_status, ['pending', 'queued'])) {
        $queuePosition = AiMessage::where('role', 'assistant')
            ->whereIn('processing_status', ['pending', 'queued'])
            ->where('queued_at', '<', $message->queued_at ?? $message->created_at)
            ->count() + 1;
    }

    return response()->json([
        'message_id' => $message->uuid,
        'status' => $message->processing_status,
        'queue_position' => $queuePosition,
        'queued_at' => $message->queued_at?->toIso8601String(),
        'processing_started_at' => $message->processing_started_at?->toIso8601String(),
        'processing_completed_at' => $message->processing_completed_at?->toIso8601String(),

        // Seulement si completed
        'content' => $message->processing_status === 'completed'
            ? $message->content
            : null,
        'generation_time_ms' => $message->generation_time_ms,

        // Seulement si failed
        'error' => $message->processing_status === 'failed'
            ? $message->processing_error
            : null,
        'retry_count' => $message->retry_count,
    ]);
}
```

### Endpoint : POST `/api/messages/{uuid}/retry`

```php
/**
 * Relance un message en échec
 */
public function retryMessage(string $uuid): JsonResponse
{
    $message = AiMessage::where('uuid', $uuid)
        ->where('processing_status', 'failed')
        ->firstOrFail();

    // Récupérer le message utilisateur original
    $userMessage = AiMessage::where('session_id', $message->session_id)
        ->where('role', 'user')
        ->where('created_at', '<', $message->created_at)
        ->orderByDesc('created_at')
        ->first();

    if (!$userMessage) {
        return response()->json(['error' => 'User message not found'], 404);
    }

    // Réinitialiser et relancer
    $message->update([
        'processing_status' => 'pending',
        'processing_error' => null,
        'retry_count' => $message->retry_count + 1,
    ]);

    dispatch(new ProcessAiMessageJob($message, $userMessage->content));

    $message->update([
        'processing_status' => 'queued',
        'queued_at' => now(),
    ]);

    return response()->json([
        'message_id' => $message->uuid,
        'status' => 'queued',
    ]);
}
```

---

## 7. Page de Monitoring (État des Services)

### Nouvelles sections à ajouter

#### 7.1 Messages IA en cours

```php
// Dans AiStatusPage.php

protected function getAiMessageStats(): array
{
    return [
        'pending' => AiMessage::where('role', 'assistant')
            ->where('processing_status', 'pending')
            ->count(),
        'queued' => AiMessage::where('role', 'assistant')
            ->where('processing_status', 'queued')
            ->count(),
        'processing' => AiMessage::where('role', 'assistant')
            ->where('processing_status', 'processing')
            ->count(),
        'completed_today' => AiMessage::where('role', 'assistant')
            ->where('processing_status', 'completed')
            ->whereDate('processing_completed_at', today())
            ->count(),
        'failed_today' => AiMessage::where('role', 'assistant')
            ->where('processing_status', 'failed')
            ->whereDate('processing_completed_at', today())
            ->count(),
        'avg_generation_time' => AiMessage::where('role', 'assistant')
            ->where('processing_status', 'completed')
            ->whereDate('processing_completed_at', today())
            ->avg('generation_time_ms'),
    ];
}
```

#### 7.2 Messages en échec avec détails

```php
protected function getFailedAiMessages(): array
{
    return AiMessage::where('role', 'assistant')
        ->where('processing_status', 'failed')
        ->with(['session.agent'])
        ->orderByDesc('processing_completed_at')
        ->limit(20)
        ->get()
        ->map(fn ($msg) => [
            'id' => $msg->id,
            'uuid' => $msg->uuid,
            'session_uuid' => $msg->session->uuid,
            'agent' => $msg->session->agent->name,
            'error' => $msg->processing_error,
            'retry_count' => $msg->retry_count,
            'failed_at' => $msg->processing_completed_at->format('d/m/Y H:i'),
            'queued_at' => $msg->queued_at?->format('d/m/Y H:i'),
        ])
        ->toArray();
}
```

#### 7.3 Queue en temps réel

```php
protected function getAiMessageQueue(): array
{
    return AiMessage::where('role', 'assistant')
        ->whereIn('processing_status', ['pending', 'queued', 'processing'])
        ->with(['session.agent'])
        ->orderBy('queued_at')
        ->limit(50)
        ->get()
        ->map(fn ($msg, $index) => [
            'position' => $index + 1,
            'uuid' => $msg->uuid,
            'agent' => $msg->session->agent->name,
            'status' => $msg->processing_status,
            'queued_at' => $msg->queued_at?->format('H:i:s'),
            'processing_started_at' => $msg->processing_started_at?->format('H:i:s'),
            'wait_time' => $msg->queued_at
                ? $msg->queued_at->diffForHumans(short: true)
                : null,
        ])
        ->toArray();
}
```

---

## 8. Configuration

### Queue dédiée

```php
// config/queue.php

'connections' => [
    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ],
],

// Optionnel : queue séparée pour les messages IA
// Permet de prioriser ou limiter le traitement
```

### Supervisor

```ini
; /etc/supervisor/conf.d/laravel-ai-worker.conf

[program:laravel-ai-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work --queue=ai-messages --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/ai-worker.log
```

---

## 9. Migration

### Fichier de migration

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->string('processing_status', 20)->default('completed')->after('content');
            $table->timestamp('queued_at')->nullable()->after('processing_status');
            $table->timestamp('processing_started_at')->nullable()->after('queued_at');
            $table->timestamp('processing_completed_at')->nullable()->after('processing_started_at');
            $table->text('processing_error')->nullable()->after('processing_completed_at');
            $table->string('job_id', 36)->nullable()->after('processing_error');
            $table->integer('retry_count')->default(0)->after('job_id');

            $table->index(['processing_status', 'role']);
            $table->index('queued_at');
        });

        // Mettre les messages existants à "completed"
        DB::table('ai_messages')
            ->where('role', 'assistant')
            ->whereNull('processing_status')
            ->update(['processing_status' => 'completed']);
    }

    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn([
                'processing_status',
                'queued_at',
                'processing_started_at',
                'processing_completed_at',
                'processing_error',
                'job_id',
                'retry_count',
            ]);
        });
    }
};
```

---

## 10. Frontend (Polling)

### Exemple JavaScript

```javascript
class AiMessagePoller {
    constructor(messageId, options = {}) {
        this.messageId = messageId;
        this.interval = options.interval || 1000;  // 1 seconde
        this.maxAttempts = options.maxAttempts || 300;  // 5 minutes
        this.onUpdate = options.onUpdate || (() => {});
        this.onComplete = options.onComplete || (() => {});
        this.onError = options.onError || (() => {});
        this.attempts = 0;
        this.timer = null;
    }

    start() {
        this.poll();
    }

    stop() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
    }

    async poll() {
        try {
            const response = await fetch(`/api/messages/${this.messageId}/status`);
            const data = await response.json();

            this.onUpdate(data);

            if (data.status === 'completed') {
                this.onComplete(data);
                return;
            }

            if (data.status === 'failed') {
                this.onError(data);
                return;
            }

            // Continue polling
            this.attempts++;
            if (this.attempts < this.maxAttempts) {
                this.timer = setTimeout(() => this.poll(), this.interval);
            } else {
                this.onError({ error: 'Timeout: message processing took too long' });
            }

        } catch (error) {
            this.onError({ error: error.message });
        }
    }
}

// Usage
const poller = new AiMessagePoller(messageUuid, {
    onUpdate: (data) => {
        console.log(`Status: ${data.status}, Position: ${data.queue_position}`);
        // Afficher indicateur de chargement avec position
    },
    onComplete: (data) => {
        console.log('Response:', data.content);
        // Afficher la réponse
    },
    onError: (data) => {
        console.error('Error:', data.error);
        // Afficher l'erreur avec option de retry
    }
});

poller.start();
```

---

## 11. Tests

### Test unitaire du job

```php
<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessAiMessageJob;
use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessAiMessageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_is_dispatched_to_correct_queue(): void
    {
        Queue::fake();

        $agent = Agent::factory()->create();
        $session = AiSession::factory()->create(['agent_id' => $agent->id]);
        $message = AiMessage::factory()->create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'processing_status' => 'pending',
        ]);

        ProcessAiMessageJob::dispatch($message, 'Test question');

        Queue::assertPushedOn('ai-messages', ProcessAiMessageJob::class);
    }

    public function test_message_status_updated_on_success(): void
    {
        // ... test avec mock de RagService
    }

    public function test_message_status_updated_on_failure(): void
    {
        // ... test avec exception
    }
}
```

---

## 12. Récapitulatif des changements

| Composant | Modification |
|-----------|--------------|
| **Table ai_messages** | +7 colonnes (processing_status, queued_at, etc.) |
| **ProcessAiMessageJob** | Nouveau job pour traitement async |
| **DispatcherService** | Nouvelle méthode `dispatchAsync()` |
| **PublicChatController** | Utiliser `dispatchAsync()` au lieu de `dispatch()` |
| **API** | +2 endpoints (status, retry) |
| **AiStatusPage** | +3 sections (stats, queue, failed messages) |
| **Frontend** | Polling JavaScript |
| **Queue** | Nouvelle queue `ai-messages` |

---

## 13. Rétrocompatibilité

- Les messages existants ont `processing_status = 'completed'`
- La méthode `dispatch()` synchrone reste disponible pour les tests
- L'API `/c/{token}/message` peut être mise à jour progressivement

---

## 14. Métriques et Alertes

### Métriques à surveiller

| Métrique | Seuil d'alerte | Action |
|----------|----------------|--------|
| Messages pending > 5 min | > 10 messages | Vérifier le worker |
| Taux d'échec | > 5% | Vérifier Ollama |
| Temps moyen de traitement | > 60s | Vérifier le modèle |
| Queue size | > 100 | Ajouter des workers |

### Commande de diagnostic

```bash
php artisan ai:queue-status

# Output:
# AI Message Queue Status
# -----------------------
# Pending:     3
# Queued:      12
# Processing:  2
#
# Oldest pending: 2 minutes ago
# Avg processing time today: 8.5s
# Failed today: 1 (0.5%)
```

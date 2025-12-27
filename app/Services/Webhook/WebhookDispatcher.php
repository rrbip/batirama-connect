<?php

declare(strict_types=1);

namespace App\Services\Webhook;

use App\Jobs\DispatchWebhookJob;
use App\Models\AiSession;
use App\Models\EditorWebhook;
use App\Models\EditorWebhookLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class WebhookDispatcher
{
    /**
     * Dispatch webhook for an event.
     *
     * @param User $editor The editor (software provider)
     * @param string $event The event type
     * @param array $data The event data
     * @param AiSession|null $session Optional related session
     */
    public function dispatch(User $editor, string $event, array $data, ?AiSession $session = null): void
    {
        // Find all active webhooks for this editor that listen to this event
        $webhooks = EditorWebhook::where('editor_id', $editor->id)
            ->where('is_active', true)
            ->get()
            ->filter(fn(EditorWebhook $webhook) => $webhook->shouldTrigger($event));

        if ($webhooks->isEmpty()) {
            Log::debug('No webhooks configured for event', [
                'editor_id' => $editor->id,
                'event' => $event,
            ]);
            return;
        }

        // Build payload
        $payload = $this->buildPayload($event, $data, $session);

        // Dispatch job for each webhook
        foreach ($webhooks as $webhook) {
            $this->dispatchWebhook($webhook, $event, $payload);
        }

        Log::info('Webhooks dispatched', [
            'editor_id' => $editor->id,
            'event' => $event,
            'webhook_count' => $webhooks->count(),
        ]);
    }

    /**
     * Dispatch a single webhook.
     */
    private function dispatchWebhook(EditorWebhook $webhook, string $event, array $payload): void
    {
        // Create log entry
        $log = EditorWebhookLog::create([
            'webhook_id' => $webhook->id,
            'event' => $event,
            'payload' => $payload,
            'status' => EditorWebhookLog::STATUS_PENDING,
            'attempt' => 1,
        ]);

        // Dispatch job
        DispatchWebhookJob::dispatch($webhook, $log, $payload);
    }

    /**
     * Build webhook payload.
     */
    private function buildPayload(string $event, array $data, ?AiSession $session): array
    {
        $payload = [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'data' => $data,
        ];

        // Add session info if available
        if ($session) {
            $payload['session'] = [
                'id' => $session->uuid,
                'deployment_id' => $session->deployment?->uuid,
                'external_id' => $session->editorLink?->external_id,
                'started_at' => $session->started_at?->toIso8601String(),
                'message_count' => $session->message_count,
            ];
        }

        return $payload;
    }

    /**
     * Dispatch session.started event.
     */
    public function dispatchSessionStarted(AiSession $session): void
    {
        $editor = $session->deployment?->editor;
        if (!$editor) {
            return;
        }

        $this->dispatch(
            $editor,
            EditorWebhook::EVENT_SESSION_STARTED,
            [
                'session_id' => $session->uuid,
                'agent_name' => $session->agent?->name,
                'external_id' => $session->editorLink?->external_id,
                'particulier' => $session->particulier ? [
                    'email' => $session->particulier->email,
                    'name' => $session->particulier->name,
                ] : null,
            ],
            $session
        );
    }

    /**
     * Dispatch session.completed event.
     */
    public function dispatchSessionCompleted(AiSession $session): void
    {
        $editor = $session->deployment?->editor;
        if (!$editor) {
            return;
        }

        $this->dispatch(
            $editor,
            EditorWebhook::EVENT_SESSION_COMPLETED,
            [
                'session_id' => $session->uuid,
                'message_count' => $session->message_count,
                'duration_seconds' => $session->started_at && $session->ended_at
                    ? $session->started_at->diffInSeconds($session->ended_at)
                    : null,
                'ended_at' => $session->ended_at?->toIso8601String(),
            ],
            $session
        );
    }

    /**
     * Dispatch message.received event.
     */
    public function dispatchMessageReceived(AiSession $session, array $message): void
    {
        $editor = $session->deployment?->editor;
        if (!$editor) {
            return;
        }

        $this->dispatch(
            $editor,
            EditorWebhook::EVENT_MESSAGE_RECEIVED,
            [
                'message_id' => $message['id'] ?? null,
                'role' => $message['role'] ?? 'assistant',
                'content' => $message['content'] ?? '',
                'sources' => $message['sources'] ?? [],
            ],
            $session
        );
    }

    /**
     * Dispatch file.uploaded event.
     */
    public function dispatchFileUploaded(AiSession $session, array $file): void
    {
        $editor = $session->deployment?->editor;
        if (!$editor) {
            return;
        }

        $this->dispatch(
            $editor,
            EditorWebhook::EVENT_FILE_UPLOADED,
            [
                'file_id' => $file['id'] ?? null,
                'name' => $file['name'] ?? null,
                'type' => $file['type'] ?? null,
                'size' => $file['size'] ?? null,
                'url' => $file['url'] ?? null,
            ],
            $session
        );
    }

    /**
     * Dispatch project.created event (structured output detected).
     */
    public function dispatchProjectCreated(AiSession $session, array $projectData): void
    {
        $editor = $session->deployment?->editor;
        if (!$editor) {
            return;
        }

        $this->dispatch(
            $editor,
            EditorWebhook::EVENT_PROJECT_CREATED,
            [
                'project' => $projectData,
            ],
            $session
        );
    }

    /**
     * Dispatch lead.generated event.
     */
    public function dispatchLeadGenerated(AiSession $session, array $leadData): void
    {
        $editor = $session->deployment?->editor;
        if (!$editor) {
            return;
        }

        $this->dispatch(
            $editor,
            EditorWebhook::EVENT_LEAD_GENERATED,
            $leadData,
            $session
        );
    }
}

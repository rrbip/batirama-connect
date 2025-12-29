<?php

declare(strict_types=1);

namespace App\Listeners\Whitelabel;

use App\Events\Whitelabel\FileUploaded;
use App\Events\Whitelabel\MessageReceived;
use App\Events\Whitelabel\SessionCompleted;
use App\Events\Whitelabel\SessionStarted;
use App\Services\Webhook\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;

class DispatchWebhookListener implements ShouldQueue
{
    public string $queue = 'webhooks';

    public function __construct(
        private WebhookDispatcher $dispatcher
    ) {}

    /**
     * Handle session started event.
     */
    public function handleSessionStarted(SessionStarted $event): void
    {
        $this->dispatcher->dispatchSessionStarted($event->session);
    }

    /**
     * Handle session completed event.
     */
    public function handleSessionCompleted(SessionCompleted $event): void
    {
        $this->dispatcher->dispatchSessionCompleted($event->session);
    }

    /**
     * Handle message received event.
     */
    public function handleMessageReceived(MessageReceived $event): void
    {
        $this->dispatcher->dispatchMessageReceived($event->session, [
            'id' => $event->message->uuid,
            'role' => $event->message->role,
            'content' => $event->message->content,
            'sources' => $event->message->rag_sources ?? [],
        ]);
    }

    /**
     * Handle file uploaded event.
     */
    public function handleFileUploaded(FileUploaded $event): void
    {
        $this->dispatcher->dispatchFileUploaded(
            $event->session,
            $event->file->toApiArray()
        );
    }

    /**
     * Subscribe to events.
     */
    public function subscribe($events): array
    {
        return [
            SessionStarted::class => 'handleSessionStarted',
            SessionCompleted::class => 'handleSessionCompleted',
            MessageReceived::class => 'handleMessageReceived',
            FileUploaded::class => 'handleFileUploaded',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs\Support;

use App\Jobs\ProcessDocumentJob;
use App\Models\AiSession;
use App\Models\Document;
use App\Services\Support\ConversationToMarkdownService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IndexConversationAsDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 300;

    public function __construct(
        protected AiSession $session
    ) {}

    public function handle(ConversationToMarkdownService $converter): void
    {
        Log::info('Indexing conversation as document', [
            'session_id' => $this->session->id,
            'agent_id' => $this->session->agent_id,
        ]);

        $agent = $this->session->agent;

        if (!$agent) {
            Log::warning('Cannot index conversation: no agent', [
                'session_id' => $this->session->id,
            ]);
            return;
        }

        try {
            // 1. Convertir la conversation en Markdown
            $markdown = $converter->convert($this->session);

            if (empty(trim($markdown))) {
                Log::warning('Empty markdown generated for conversation', [
                    'session_id' => $this->session->id,
                ]);
                return;
            }

            // 2. Stocker le fichier Markdown
            $fileName = "support_session_{$this->session->id}_" . date('Ymd_His') . '.md';
            $storagePath = "documents/support/{$fileName}";

            Storage::disk('local')->put($storagePath, $markdown);

            // 3. Créer le Document
            $document = Document::create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $agent->tenant_id,
                'agent_id' => $agent->id,
                'source_type' => 'support_conversation',
                'original_name' => $this->extractTitle() . '.md',
                'storage_path' => $storagePath,
                'mime_type' => 'text/markdown',
                'file_size' => strlen($markdown),
                'file_hash' => md5($markdown),
                'document_type' => 'support_resolution',
                'extraction_status' => 'pending',
                'extracted_text' => $markdown,
                'chunk_strategy' => 'qr_atomic', // Utiliser le chunking Q/R atomique
                'metadata' => [
                    'session_id' => $this->session->id,
                    'session_uuid' => $this->session->uuid,
                    'escalation_reason' => $this->session->escalation_reason,
                    'resolved_at' => $this->session->resolved_at?->toIso8601String(),
                    'support_agent_id' => $this->session->support_agent_id,
                    'auto_indexed' => true,
                ],
            ]);

            Log::info('Document created from conversation', [
                'document_id' => $document->id,
                'session_id' => $this->session->id,
            ]);

            // 4. Mettre à jour la session
            $this->session->update([
                'support_metadata' => array_merge(
                    $this->session->support_metadata ?? [],
                    [
                        'indexed_document_id' => $document->id,
                        'indexed_at' => now()->toIso8601String(),
                    ]
                ),
            ]);

            // 5. Lancer le pipeline d'indexation standard
            ProcessDocumentJob::dispatch($document);

            Log::info('Document processing dispatched', [
                'document_id' => $document->id,
                'session_id' => $this->session->id,
            ]);

        } catch (\Throwable $e) {
            Log::error('Failed to index conversation as document', [
                'session_id' => $this->session->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Extrait un titre depuis la conversation.
     */
    protected function extractTitle(): string
    {
        $firstMessage = $this->session->messages()
            ->where('role', 'user')
            ->orderBy('created_at')
            ->first();

        if ($firstMessage) {
            return 'Support - ' . Str::limit($firstMessage->content, 40);
        }

        return "Support Session #{$this->session->id}";
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('IndexConversationAsDocumentJob failed definitively', [
            'session_id' => $this->session->id,
            'error' => $exception->getMessage(),
        ]);

        // Marquer la session comme non indexée
        $this->session->update([
            'support_metadata' => array_merge(
                $this->session->support_metadata ?? [],
                [
                    'indexing_failed' => true,
                    'indexing_error' => $exception->getMessage(),
                ]
            ),
        ]);
    }
}

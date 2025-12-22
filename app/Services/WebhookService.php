<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiSession;
use App\Models\Partner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class WebhookService
{
    private const MAX_RETRIES = 3;
    private const RETRY_DELAYS = [60, 300, 1800]; // 1min, 5min, 30min

    /**
     * Envoie un webhook pour une session terminée
     */
    public function sendSessionCompleted(AiSession $session): void
    {
        $partner = $session->partner;

        if (!$partner || !$partner->webhook_url) {
            return;
        }

        $payload = $this->buildSessionCompletedPayload($session);

        $this->dispatchWebhook($partner, 'session.completed', $payload);
    }

    /**
     * Envoie un webhook pour une session expirée
     */
    public function sendSessionExpired(AiSession $session): void
    {
        $partner = $session->partner;

        if (!$partner || !$partner->webhook_url) {
            return;
        }

        $payload = [
            'session_id' => 'sess_' . $session->uuid,
            'external_ref' => $session->external_ref,
            'status' => 'expired',
            'expired_at' => now()->toIso8601String(),
        ];

        $this->dispatchWebhook($partner, 'session.expired', $payload);
    }

    /**
     * Dispatch le webhook dans la queue
     */
    private function dispatchWebhook(Partner $partner, string $event, array $data): void
    {
        $payload = [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'data' => $data,
        ];

        // Dispatcher dans la queue pour retry automatique
        Queue::push(function () use ($partner, $event, $payload) {
            $this->sendWebhook($partner, $event, $payload);
        });
    }

    /**
     * Envoie le webhook avec signature
     */
    public function sendWebhook(Partner $partner, string $event, array $payload, int $attempt = 0): bool
    {
        $jsonPayload = json_encode($payload);
        $signature = $this->generateSignature($jsonPayload, $partner->webhook_secret);

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Webhook-Signature' => $signature,
                    'X-Webhook-Event' => $event,
                    'User-Agent' => 'AI-Manager-Webhook/1.0',
                ])
                ->post($partner->webhook_url, $payload);

            if ($response->successful()) {
                Log::info('Webhook sent successfully', [
                    'partner' => $partner->slug,
                    'event' => $event,
                    'status' => $response->status(),
                ]);

                $this->logWebhook($partner, $event, $payload, $response->status(), true);

                return true;
            }

            Log::warning('Webhook failed', [
                'partner' => $partner->slug,
                'event' => $event,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            // Retry si possible
            return $this->handleRetry($partner, $event, $payload, $attempt);

        } catch (\Exception $e) {
            Log::error('Webhook exception', [
                'partner' => $partner->slug,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return $this->handleRetry($partner, $event, $payload, $attempt);
        }
    }

    /**
     * Gère les retries
     */
    private function handleRetry(Partner $partner, string $event, array $payload, int $attempt): bool
    {
        if ($attempt >= self::MAX_RETRIES) {
            $this->logWebhook($partner, $event, $payload, null, false, 'Max retries exceeded');
            return false;
        }

        $delay = self::RETRY_DELAYS[$attempt] ?? 1800;

        Queue::later($delay, function () use ($partner, $event, $payload, $attempt) {
            $this->sendWebhook($partner, $event, $payload, $attempt + 1);
        });

        return false;
    }

    /**
     * Génère la signature du webhook
     */
    private function generateSignature(string $payload, ?string $secret): string
    {
        $secret = $secret ?? config('app.key');
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Log le webhook en base
     */
    private function logWebhook(
        Partner $partner,
        string $event,
        array $payload,
        ?int $status,
        bool $success,
        ?string $error = null
    ): void {
        // TODO: Créer table webhook_logs si besoin
        Log::info('Webhook logged', [
            'partner_id' => $partner->id,
            'event' => $event,
            'status' => $status,
            'success' => $success,
            'error' => $error,
        ]);
    }

    /**
     * Construit le payload pour session.completed
     */
    private function buildSessionCompletedPayload(AiSession $session): array
    {
        $attachments = $session->messages()
            ->whereNotNull('attachments')
            ->get()
            ->flatMap(fn($m) => $m->attachments ?? []);

        return [
            'session_id' => 'sess_' . $session->uuid,
            'external_ref' => $session->external_ref,
            'status' => 'completed',
            'result' => [
                'project_name' => $session->metadata['project_name'] ?? 'Projet',
                'estimated_total' => $session->metadata['estimated_total'] ?? null,
                'has_attachments' => $attachments->isNotEmpty(),
                'attachments_count' => $attachments->count(),
            ],
        ];
    }

    /**
     * Vérifie une signature de webhook entrante
     */
    public static function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }
}

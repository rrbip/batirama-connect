<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EditorWebhook;
use App\Models\EditorWebhookLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300]; // 10s, 1min, 5min

    public function __construct(
        private EditorWebhook $webhook,
        private EditorWebhookLog $log,
        private array $payload
    ) {
        $this->queue = 'webhooks';
    }

    public function handle(): void
    {
        $startTime = microtime(true);

        try {
            // Generate signature
            $signature = $this->webhook->generateSignature($this->payload);

            // Make HTTP request
            $response = Http::timeout($this->webhook->timeout_ms / 1000)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Batirama-Webhook/1.0',
                    'X-Batirama-Signature' => $signature,
                    'X-Batirama-Event' => $this->payload['event'] ?? 'unknown',
                    'X-Batirama-Delivery' => $this->log->uuid,
                ])
                ->post($this->webhook->url, $this->payload);

            $responseTime = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                // Success
                $this->log->markAsSuccess(
                    $response->status(),
                    $response->body(),
                    $responseTime
                );

                $this->webhook->recordSuccess();

                Log::info('Webhook delivered successfully', [
                    'webhook_id' => $this->webhook->id,
                    'log_id' => $this->log->uuid,
                    'event' => $this->payload['event'] ?? 'unknown',
                    'status' => $response->status(),
                    'response_time_ms' => $responseTime,
                ]);
            } else {
                // HTTP error response
                throw new \RuntimeException(
                    "HTTP {$response->status()}: " . substr($response->body(), 0, 200)
                );
            }
        } catch (\Exception $e) {
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);

            Log::warning('Webhook delivery failed', [
                'webhook_id' => $this->webhook->id,
                'log_id' => $this->log->uuid,
                'event' => $this->payload['event'] ?? 'unknown',
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            // Check if we should retry
            if ($this->attempts() < $this->tries) {
                $this->log->markForRetry();
                throw $e; // Let Laravel retry
            }

            // Final failure
            $this->log->markAsFailed(
                $e->getMessage(),
                null,
                $responseTime
            );

            $this->webhook->recordFailure();
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Webhook job failed permanently', [
            'webhook_id' => $this->webhook->id,
            'log_id' => $this->log->uuid,
            'event' => $this->payload['event'] ?? 'unknown',
            'error' => $exception->getMessage(),
        ]);

        // Ensure log is marked as failed
        if ($this->log->status !== EditorWebhookLog::STATUS_FAILED) {
            $this->log->markAsFailed($exception->getMessage());
            $this->webhook->recordFailure();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Agent;
use App\Services\Support\ImapService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job pour récupérer les emails de support via IMAP.
 * Dispatché toutes les minutes par le scheduler.
 */
class FetchSupportEmailsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Nombre de tentatives max.
     */
    public int $tries = 2;

    /**
     * Timeout en secondes.
     */
    public int $timeout = 120;

    /**
     * Durée d'unicité (évite les doublons si le job est lent).
     */
    public int $uniqueFor = 60;

    public function __construct()
    {
        $this->onQueue('mail');
    }

    /**
     * Execute the job.
     */
    public function handle(ImapService $imapService): void
    {
        Log::info('FetchSupportEmailsJob: Starting IMAP fetch');

        // Récupérer les agents avec IMAP configuré
        $agents = Agent::where('human_support_enabled', true)
            ->whereNotNull('support_email')
            ->get();

        if ($agents->isEmpty()) {
            Log::debug('FetchSupportEmailsJob: No agents with email support configured');
            return;
        }

        $totalProcessed = 0;
        $errors = [];

        foreach ($agents as $agent) {
            // Vérifier que la config IMAP existe
            $imapConfig = $agent->getImapConfig();
            if (!$imapConfig) {
                Log::debug('FetchSupportEmailsJob: No IMAP config for agent', [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                ]);
                continue;
            }

            try {
                $emails = $imapService->fetchNewEmails($agent);
                $count = count($emails);
                $totalProcessed += $count;

                if ($count > 0) {
                    Log::info('FetchSupportEmailsJob: Processed emails', [
                        'agent_id' => $agent->id,
                        'agent_name' => $agent->name,
                        'count' => $count,
                    ]);
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'agent' => $agent->name,
                    'error' => $e->getMessage(),
                ];

                Log::error('FetchSupportEmailsJob: IMAP fetch failed for agent', [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('FetchSupportEmailsJob: Completed', [
            'total_processed' => $totalProcessed,
            'errors_count' => count($errors),
        ]);

        // Si toutes les tentatives ont échoué, lever une exception pour marquer le job comme failed
        if (!empty($errors) && $totalProcessed === 0) {
            throw new \RuntimeException(
                'IMAP fetch failed for all agents: ' . collect($errors)->pluck('error')->implode(', ')
            );
        }
    }

    /**
     * Clé unique pour éviter les doublons.
     */
    public function uniqueId(): string
    {
        return 'fetch-support-emails';
    }

    /**
     * Tags pour le monitoring.
     */
    public function tags(): array
    {
        return ['support', 'imap', 'email-sync'];
    }
}

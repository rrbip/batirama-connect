<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\Support\ImapService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FetchSupportEmailsCommand extends Command
{
    protected $signature = 'support:fetch-emails
        {--agent= : ID de l\'agent IA spécifique}
        {--dry-run : Exécuter sans traiter les emails}';

    protected $description = 'Récupère les emails de support via IMAP';

    public function handle(ImapService $imapService): int
    {
        $this->info('Récupération des emails de support...');

        $agentId = $this->option('agent');
        $dryRun = $this->option('dry-run');

        // Récupérer les agents avec IMAP configuré
        $query = Agent::where('human_support_enabled', true)
            ->whereNotNull('support_email');

        if ($agentId) {
            $query->where('id', $agentId);
        }

        $agents = $query->get();

        if ($agents->isEmpty()) {
            $this->warn('Aucun agent avec support email configuré.');
            return self::SUCCESS;
        }

        $totalProcessed = 0;
        $errors = [];

        foreach ($agents as $agent) {
            $this->line("Agent: {$agent->name} ({$agent->id})");

            // Vérifier que la config IMAP existe
            $imapConfig = $agent->getImapConfig();
            if (!$imapConfig) {
                $this->warn("  → Pas de configuration IMAP");
                continue;
            }

            if ($dryRun) {
                $this->info("  → Mode dry-run, connexion testée uniquement");
                $connected = $imapService->testConnection($imapConfig);
                $this->line("  → Connexion: " . ($connected ? '✓' : '✗'));
                continue;
            }

            try {
                $emails = $imapService->fetchNewEmails($agent);
                $count = count($emails);
                $totalProcessed += $count;

                if ($count > 0) {
                    $this->info("  → {$count} email(s) traité(s)");
                    foreach ($emails as $email) {
                        $this->line("    - Session #{$email['session_id']} depuis {$email['from']}");
                    }
                } else {
                    $this->line("  → Aucun nouvel email");
                }

            } catch (\Throwable $e) {
                $errors[] = [
                    'agent' => $agent->name,
                    'error' => $e->getMessage(),
                ];
                $this->error("  → Erreur: {$e->getMessage()}");

                Log::error('IMAP fetch failed for agent', [
                    'agent_id' => $agent->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->newLine();
        $this->info("Terminé. {$totalProcessed} email(s) traité(s) au total.");

        if (!empty($errors)) {
            $this->warn(count($errors) . " erreur(s) rencontrée(s).");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

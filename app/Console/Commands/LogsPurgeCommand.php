<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LogsPurgeCommand extends Command
{
    protected $signature = 'logs:purge
                            {--days=90 : Nombre de jours de rétention}
                            {--dry-run : Affiche ce qui serait supprimé sans supprimer}';

    protected $description = 'Purge les anciens logs et sessions IA';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $cutoffDate = now()->subDays($days);

        $this->info("🗑️  Purge des données antérieures au {$cutoffDate->format('Y-m-d')}");

        if ($dryRun) {
            $this->warn("   Mode dry-run activé - aucune suppression");
        }

        $this->newLine();

        // 1. Purge des messages IA
        $messagesCount = $this->purgeAiMessages($cutoffDate, $dryRun);
        $this->line("   📝 Messages IA: {$messagesCount} " . ($dryRun ? 'à supprimer' : 'supprimés'));

        // 2. Purge des sessions IA terminées
        $sessionsCount = $this->purgeAiSessions($cutoffDate, $dryRun);
        $this->line("   💬 Sessions IA: {$sessionsCount} " . ($dryRun ? 'à supprimer' : 'supprimées'));

        // 3. Purge des activity logs
        $activityCount = $this->purgeActivityLogs($cutoffDate, $dryRun);
        $this->line("   📋 Logs d'activité: {$activityCount} " . ($dryRun ? 'à supprimer' : 'supprimés'));

        // 4. Purge des webhook logs
        $webhookCount = $this->purgeWebhookLogs($cutoffDate, $dryRun);
        $this->line("   🔗 Logs webhook: {$webhookCount} " . ($dryRun ? 'à supprimer' : 'supprimés'));

        $this->newLine();

        if ($dryRun) {
            $this->info("✅ Dry-run terminé. Utilisez sans --dry-run pour effectuer la purge.");
        } else {
            $total = $messagesCount + $sessionsCount + $activityCount + $webhookCount;
            $this->info("✅ Purge terminée. {$total} enregistrements supprimés.");

            Log::info('Logs purge completed', [
                'days_retention' => $days,
                'messages_deleted' => $messagesCount,
                'sessions_deleted' => $sessionsCount,
                'activity_deleted' => $activityCount,
                'webhook_deleted' => $webhookCount,
            ]);
        }

        return Command::SUCCESS;
    }

    private function purgeAiMessages(\DateTimeInterface $cutoffDate, bool $dryRun): int
    {
        $query = DB::table('ai_messages')
            ->where('created_at', '<', $cutoffDate);

        if ($dryRun) {
            return $query->count();
        }

        return $query->delete();
    }

    private function purgeAiSessions(\DateTimeInterface $cutoffDate, bool $dryRun): int
    {
        // Seulement les sessions terminées (avec ended_at)
        $query = DB::table('ai_sessions')
            ->whereNotNull('ended_at')
            ->where('ended_at', '<', $cutoffDate);

        if ($dryRun) {
            return $query->count();
        }

        // D'abord supprimer les messages orphelins
        $sessionIds = (clone $query)->pluck('id');

        if ($sessionIds->isNotEmpty()) {
            DB::table('ai_messages')
                ->whereIn('session_id', $sessionIds)
                ->delete();
        }

        return $query->delete();
    }

    private function purgeActivityLogs(\DateTimeInterface $cutoffDate, bool $dryRun): int
    {
        if (!$this->tableExists('activity_log')) {
            return 0;
        }

        $query = DB::table('activity_log')
            ->where('created_at', '<', $cutoffDate);

        if ($dryRun) {
            return $query->count();
        }

        return $query->delete();
    }

    private function purgeWebhookLogs(\DateTimeInterface $cutoffDate, bool $dryRun): int
    {
        if (!$this->tableExists('webhook_logs')) {
            return 0;
        }

        $query = DB::table('webhook_logs')
            ->where('created_at', '<', $cutoffDate);

        if ($dryRun) {
            return $query->count();
        }

        return $query->delete();
    }

    private function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }
}

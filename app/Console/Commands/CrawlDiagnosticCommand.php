<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WebCrawl;
use App\Models\WebCrawlUrlCrawl;
use App\Services\Crawler\WebCrawlerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class CrawlDiagnosticCommand extends Command
{
    protected $signature = 'crawl:diagnostic
                            {crawl_id? : ID du crawl à diagnostiquer (dernier si non spécifié)}
                            {--test-url= : Tester l\'extraction de liens sur une URL spécifique}
                            {--fix : Tenter de corriger les problèmes détectés}';

    protected $description = 'Diagnostique les problèmes d\'un crawl';

    public function handle(WebCrawlerService $crawler): int
    {
        $crawlId = $this->argument('crawl_id');
        $testUrl = $this->option('test-url');

        // Si test URL spécifique
        if ($testUrl) {
            return $this->testUrlExtraction($testUrl, $crawler);
        }

        // Récupérer le crawl
        $crawl = $crawlId
            ? WebCrawl::find($crawlId)
            : WebCrawl::latest()->first();

        if (! $crawl) {
            $this->error('Aucun crawl trouvé');

            return Command::FAILURE;
        }

        $this->info("🔍 Diagnostic du crawl #{$crawl->id}");
        $this->newLine();

        // 1. Configuration du crawl
        $this->diagnosticConfig($crawl);

        // 2. Stats des URLs
        $this->diagnosticUrls($crawl);

        // 3. Stats de la queue
        $this->diagnosticQueue($crawl);

        // 4. Dernières activités
        $this->diagnosticActivity($crawl);

        // 5. Test extraction sur une URL du crawl
        $this->diagnosticExtraction($crawl, $crawler);

        // 6. Suggestions
        $this->showSuggestions($crawl);

        return Command::SUCCESS;
    }

    private function diagnosticConfig(WebCrawl $crawl): void
    {
        $this->info('📋 Configuration:');

        $configData = [
            ['Paramètre', 'Valeur', 'Status'],
            ['ID', $crawl->id, ''],
            ['Status', $crawl->status, $crawl->status === 'completed' ? '⚠️ Terminé' : '✅'],
            ['URL de départ', $crawl->start_url, ''],
            ['max_pages', $crawl->max_pages, $crawl->max_pages == 0 ? '✅ Illimité' : ($crawl->max_pages <= 100 ? '⚠️ Limite basse!' : '✅')],
            ['max_depth', $crawl->max_depth, $crawl->max_depth == 99 ? '✅ Illimité' : ''],
            ['pages_discovered', $crawl->pages_discovered, ''],
            ['pages_crawled', $crawl->pages_crawled, ''],
            ['Démarré', $crawl->started_at?->format('d/m H:i:s') ?? '-', ''],
            ['Terminé', $crawl->completed_at?->format('d/m H:i:s') ?? '-', ''],
        ];

        $this->table(['Paramètre', 'Valeur', 'Status'], array_slice($configData, 1));

        // Alerte si max_pages semble être le problème
        if ($crawl->max_pages > 0 && $crawl->pages_discovered >= $crawl->max_pages) {
            $this->error("⛔ PROBLÈME DÉTECTÉ: pages_discovered ({$crawl->pages_discovered}) >= max_pages ({$crawl->max_pages})");
            $this->line("   → Le crawl s'est arrêté car la limite de pages a été atteinte.");
            $this->line("   → Solution: Éditez le crawl et mettez max_pages à 0 (Illimité)");
        }

        $this->newLine();
    }

    private function diagnosticUrls(WebCrawl $crawl): void
    {
        $this->info('📊 Statistiques des URLs:');

        $stats = WebCrawlUrlCrawl::where('crawl_id', $crawl->id)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $depthStats = WebCrawlUrlCrawl::where('crawl_id', $crawl->id)
            ->selectRaw('depth, COUNT(*) as count')
            ->groupBy('depth')
            ->orderBy('depth')
            ->pluck('count', 'depth')
            ->toArray();

        $this->line('   Par statut:');
        foreach ($stats as $status => $count) {
            $icon = match ($status) {
                'fetched' => '✅',
                'pending' => '⏳',
                'fetching' => '🔄',
                'error' => '❌',
                default => '❓',
            };
            $this->line("     {$icon} {$status}: {$count}");
        }

        $this->newLine();
        $this->line('   Par profondeur:');
        foreach ($depthStats as $depth => $count) {
            $bar = str_repeat('█', min($count, 50));
            $this->line("     Depth {$depth}: {$count} {$bar}");
        }

        // Vérifier si toutes les URLs sont à max_depth
        $maxDepthInCrawl = max(array_keys($depthStats) ?: [0]);
        if ($crawl->max_depth != 99 && $maxDepthInCrawl >= $crawl->max_depth) {
            $atMaxDepth = $depthStats[$crawl->max_depth] ?? 0;
            if ($atMaxDepth > 0) {
                $this->warn("   ⚠️ {$atMaxDepth} URLs sont à la profondeur max ({$crawl->max_depth})");
                $this->line("      → Ces URLs ne généreront pas de nouveaux liens");
            }
        }

        // Vérifier les erreurs
        $errors = WebCrawlUrlCrawl::where('crawl_id', $crawl->id)
            ->where('status', 'error')
            ->limit(5)
            ->with('url')
            ->get();

        if ($errors->isNotEmpty()) {
            $this->newLine();
            $this->error('   Exemples d\'erreurs:');
            foreach ($errors as $entry) {
                $this->line("     - {$entry->url?->url}");
                $this->line("       Message: {$entry->error_message}");
            }
        }

        $this->newLine();
    }

    private function diagnosticQueue(WebCrawl $crawl): void
    {
        $this->info('📬 État de la queue:');

        // Jobs en attente
        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();

        // Jobs liés à ce crawl (approximatif)
        $crawlJobs = DB::table('jobs')
            ->where('payload', 'like', '%CrawlUrlJob%')
            ->count();

        $this->line("   Jobs en attente (total): {$pendingJobs}");
        $this->line("   Jobs CrawlUrlJob: {$crawlJobs}");
        $this->line("   Jobs échoués: {$failedJobs}");

        if ($failedJobs > 0) {
            $this->warn("   ⚠️ Il y a des jobs échoués - vérifiez avec: php artisan queue:failed");
        }

        if ($crawl->status === 'running' && $crawlJobs === 0 && ($stats['pending'] ?? 0) === 0) {
            $this->error("   ⛔ Le crawl est 'running' mais aucun job en queue!");
            $this->line("      → Possible race condition dans checkCrawlCompletion");
        }

        $this->newLine();
    }

    private function diagnosticActivity(WebCrawl $crawl): void
    {
        $this->info('📈 Activité récente:');

        // Dernières URLs crawlées
        $recentUrls = WebCrawlUrlCrawl::where('crawl_id', $crawl->id)
            ->whereNotNull('fetched_at')
            ->orderBy('fetched_at', 'desc')
            ->limit(5)
            ->with('url')
            ->get();

        if ($recentUrls->isEmpty()) {
            $this->line('   Aucune URL crawlée récemment');
        } else {
            $this->line('   Dernières URLs crawlées:');
            foreach ($recentUrls as $entry) {
                $time = $entry->fetched_at->format('H:i:s');
                $url = \Illuminate\Support\Str::limit($entry->url?->url ?? 'N/A', 60);
                $this->line("     [{$time}] {$url}");
            }
        }

        $this->newLine();
    }

    private function diagnosticExtraction(WebCrawl $crawl, WebCrawlerService $crawler): void
    {
        $this->info('🔗 Test d\'extraction de liens:');

        // Prendre une URL fetched au hasard
        $sampleEntry = WebCrawlUrlCrawl::where('crawl_id', $crawl->id)
            ->where('status', 'fetched')
            ->whereHas('url', fn ($q) => $q->whereNotNull('storage_path'))
            ->with('url')
            ->first();

        if (! $sampleEntry || ! $sampleEntry->url) {
            $this->line('   Aucune URL avec contenu stocké trouvée');

            return;
        }

        $url = $sampleEntry->url;
        $this->line("   URL testée: {$url->url}");
        $this->line("   Content-Type: {$url->content_type}");
        $this->line("   Storage path: {$url->storage_path}");

        // Vérifier si le fichier existe
        if (! Storage::disk('local')->exists($url->storage_path)) {
            $this->error("   ⛔ Le fichier n'existe pas sur le disque!");

            return;
        }

        // Charger le contenu et extraire les liens
        $content = Storage::disk('local')->get($url->storage_path);
        $this->line('   Taille contenu: ' . strlen($content) . ' bytes');

        if (str_contains($url->content_type ?? '', 'text/html')) {
            $links = $crawler->extractLinks($content, $url->url);
            $this->line('   Liens extraits: ' . count($links));

            if (count($links) > 0) {
                $this->line('   Échantillon (5 premiers):');
                foreach (array_slice($links, 0, 5) as $link) {
                    $this->line("     - {$link}");
                }
            } else {
                $this->warn('   ⚠️ Aucun lien extrait - le site utilise peut-être JavaScript');
            }

            // Vérifier combien seraient filtrés par domaine
            $allowedDomains = array_filter(explode("\n", $crawl->allowed_domains ?? ''));
            if (empty($allowedDomains)) {
                $parsed = parse_url($crawl->start_url);
                $allowedDomains = [$parsed['host'] ?? ''];
            }

            $filteredCount = 0;
            foreach ($links as $link) {
                $linkHost = parse_url($link, PHP_URL_HOST);
                $allowed = false;
                foreach ($allowedDomains as $domain) {
                    if ($linkHost === $domain || str_ends_with($linkHost, '.' . $domain)) {
                        $allowed = true;
                        break;
                    }
                }
                if (! $allowed) {
                    $filteredCount++;
                }
            }

            if ($filteredCount > 0) {
                $this->line("   Liens filtrés (domaine externe): {$filteredCount}");
            }
        }

        $this->newLine();
    }

    private function showSuggestions(WebCrawl $crawl): void
    {
        $this->info('💡 Suggestions:');

        $suggestions = [];

        // Vérifier max_pages
        if ($crawl->max_pages > 0 && $crawl->pages_discovered >= $crawl->max_pages) {
            $suggestions[] = "Le crawl est limité à {$crawl->max_pages} pages. Modifiez le crawl pour mettre 'Illimité'";
        }

        // Vérifier si terminé prématurément
        if ($crawl->status === 'completed' && $crawl->pages_discovered < 100) {
            $suggestions[] = 'Le crawl s\'est terminé avec peu de pages. Vérifiez les logs pour "Web crawl completed"';
        }

        // Vérifier la queue
        $pendingJobs = DB::table('jobs')->count();
        if ($pendingJobs === 0 && $crawl->status === 'running') {
            $suggestions[] = 'Aucun job en queue mais crawl "running". Relancez le worker ou corrigez le status';
        }

        // Vérifier les failed jobs
        $failedJobs = DB::table('failed_jobs')->count();
        if ($failedJobs > 0) {
            $suggestions[] = "Il y a {$failedJobs} jobs échoués. Exécutez: php artisan queue:failed";
        }

        if (empty($suggestions)) {
            $this->line('   ✅ Aucun problème évident détecté');
            $this->line('   → Vérifiez les logs: tail -f storage/logs/laravel.log | grep -E "(crawl|CrawlUrlJob)"');
        } else {
            foreach ($suggestions as $i => $suggestion) {
                $num = $i + 1;
                $this->line("   {$num}. {$suggestion}");
            }
        }

        $this->newLine();
    }

    private function testUrlExtraction(string $url, WebCrawlerService $crawler): int
    {
        $this->info("🔍 Test d'extraction pour: {$url}");
        $this->newLine();

        try {
            // Fetch l'URL
            $this->line('Fetching...');
            $response = Http::timeout(30)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])
                ->get($url);

            $this->line("Status: {$response->status()}");
            $this->line('Content-Type: ' . ($response->header('Content-Type') ?? 'N/A'));
            $this->line('Content-Length: ' . strlen($response->body()) . ' bytes');

            if (! $response->successful()) {
                $this->error('Échec du fetch');

                return Command::FAILURE;
            }

            // Extraire les liens
            $links = $crawler->extractLinks($response->body(), $url);

            $this->newLine();
            $this->info('📊 Résultats:');
            $this->line('Liens trouvés: ' . count($links));

            if (count($links) > 0) {
                $this->newLine();
                $this->line('Liens (20 premiers):');
                foreach (array_slice($links, 0, 20) as $link) {
                    $this->line("  - {$link}");
                }
            }

            // Analyser les liens par domaine
            $domains = [];
            foreach ($links as $link) {
                $host = parse_url($link, PHP_URL_HOST) ?? 'unknown';
                $domains[$host] = ($domains[$host] ?? 0) + 1;
            }

            if (! empty($domains)) {
                $this->newLine();
                $this->line('Par domaine:');
                arsort($domains);
                foreach (array_slice($domains, 0, 10, true) as $domain => $count) {
                    $this->line("  {$domain}: {$count}");
                }
            }

        } catch (\Exception $e) {
            $this->error('Erreur: ' . $e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

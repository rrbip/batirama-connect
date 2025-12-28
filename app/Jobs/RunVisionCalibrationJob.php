<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\VisionSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RunVisionCalibrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes max

    public int $tries = 1;

    private string $calibrationId;
    private array $tests;
    private string $imageContent;
    private ?string $imageSource;

    public function __construct(
        string $calibrationId,
        array $tests,
        string $imageContent,
        ?string $imageSource = null
    ) {
        $this->calibrationId = $calibrationId;
        $this->tests = $tests;
        $this->imageContent = $imageContent;
        $this->imageSource = $imageSource;
    }

    public function handle(): void
    {
        $results = [];
        $settings = VisionSetting::getInstance();

        // Stocker le statut initial
        $this->updateStatus('running', 0, count($this->tests), []);

        foreach ($this->tests as $index => $test) {
            $prompt = $test['prompt'] ?? '';

            if (empty($prompt)) {
                $results[$index] = [
                    'id' => $test['id'] ?? "test_$index",
                    'category' => $test['category'] ?? 'N/A',
                    'description' => $test['description'] ?? '',
                    'prompt' => $prompt,
                    'success' => false,
                    'error' => 'Prompt vide',
                    'markdown' => '',
                    'processing_time' => 0,
                ];
            } else {
                $result = $this->testPromptWithImage($prompt, $settings);
                $results[$index] = array_merge([
                    'id' => $test['id'] ?? "test_$index",
                    'category' => $test['category'] ?? 'N/A',
                    'description' => $test['description'] ?? '',
                    'prompt' => $prompt,
                ], $result);
            }

            // Mettre à jour le statut après chaque test
            $this->updateStatus('running', $index + 1, count($this->tests), $results);
        }

        // Générer le rapport
        $report = $this->generateReport($results, $settings);

        // Stocker le résultat final
        $this->updateStatus('completed', count($this->tests), count($this->tests), $results, $report);

        Log::info('Vision calibration completed', [
            'calibration_id' => $this->calibrationId,
            'tests_count' => count($this->tests),
            'success_count' => count(array_filter($results, fn($r) => $r['success'] ?? false)),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Vision calibration failed', [
            'calibration_id' => $this->calibrationId,
            'error' => $exception->getMessage(),
        ]);

        Cache::put("calibration:{$this->calibrationId}", [
            'status' => 'failed',
            'error' => $exception->getMessage(),
            'progress' => 0,
            'total' => count($this->tests),
            'results' => [],
            'report' => '',
        ], now()->addHour());
    }

    private function updateStatus(string $status, int $progress, int $total, array $results, string $report = ''): void
    {
        Cache::put("calibration:{$this->calibrationId}", [
            'status' => $status,
            'progress' => $progress,
            'total' => $total,
            'results' => $results,
            'report' => $report,
        ], now()->addHour());
    }

    private function testPromptWithImage(string $prompt, VisionSetting $settings): array
    {
        $startTime = microtime(true);

        try {
            $base64Image = base64_encode($this->imageContent);

            $response = Http::timeout($settings->timeout_seconds)
                ->post($settings->getOllamaUrl() . '/api/generate', [
                    'model' => $settings->model,
                    'prompt' => $prompt,
                    'images' => [$base64Image],
                    'stream' => false,
                    'options' => [
                        'temperature' => 0.1,
                        'num_predict' => 4096,
                    ],
                ]);

            $processingTime = round(microtime(true) - $startTime, 2);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => 'Erreur Ollama: ' . $response->body(),
                    'markdown' => '',
                    'processing_time' => $processingTime,
                ];
            }

            $result = $response->json();
            $markdown = $result['response'] ?? '';
            $markdown = $this->cleanMarkdown($markdown);

            return [
                'success' => true,
                'error' => null,
                'markdown' => $markdown,
                'processing_time' => $processingTime,
                'model' => $settings->model,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'markdown' => '',
                'processing_time' => round(microtime(true) - $startTime, 2),
            ];
        }
    }

    private function cleanMarkdown(string $markdown): string
    {
        $markdown = preg_replace('/^```(?:markdown)?\n?/m', '', $markdown);
        $markdown = preg_replace('/\n?```$/m', '', $markdown);
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);
        return trim($markdown);
    }

    private function generateReport(array $results, VisionSetting $settings): string
    {
        $successCount = count(array_filter($results, fn($r) => $r['success'] ?? false));
        $totalCount = count($results);
        $totalTime = array_sum(array_column($results, 'processing_time'));

        $report = "# Rapport de Calibration Vision\n\n";
        $report .= "## Informations Générales\n\n";
        $report .= "- **Date** : " . now()->format('d/m/Y H:i:s') . "\n";
        $report .= "- **Modèle** : `{$settings->model}`\n";
        $report .= "- **Serveur Ollama** : `{$settings->getOllamaUrl()}`\n";
        $report .= "- **Image source** : " . ($this->imageSource ?: 'Upload local') . "\n";
        $report .= "- **Tests réussis** : {$successCount}/{$totalCount}\n";
        $report .= "- **Temps total** : {$totalTime}s\n\n";

        $report .= "## Résumé par Catégorie\n\n";

        $byCategory = [];
        foreach ($results as $result) {
            $cat = $result['category'] ?? 'Autre';
            if (!isset($byCategory[$cat])) {
                $byCategory[$cat] = ['success' => 0, 'total' => 0, 'time' => 0];
            }
            $byCategory[$cat]['total']++;
            $byCategory[$cat]['time'] += $result['processing_time'] ?? 0;
            if ($result['success'] ?? false) {
                $byCategory[$cat]['success']++;
            }
        }

        $report .= "| Catégorie | Réussis | Temps moyen |\n";
        $report .= "|-----------|---------|-------------|\n";
        foreach ($byCategory as $cat => $stats) {
            $avgTime = $stats['total'] > 0 ? round($stats['time'] / $stats['total'], 2) : 0;
            $report .= "| {$cat} | {$stats['success']}/{$stats['total']} | {$avgTime}s |\n";
        }
        $report .= "\n";

        $report .= "## Détails des Tests\n\n";

        foreach ($results as $result) {
            $status = ($result['success'] ?? false) ? '✅' : '❌';
            $report .= "### {$status} {$result['id']}\n\n";
            $report .= "- **Catégorie** : {$result['category']}\n";
            $report .= "- **Description** : {$result['description']}\n";
            $report .= "- **Temps** : {$result['processing_time']}s\n\n";

            $report .= "**Prompt utilisé :**\n```\n{$result['prompt']}\n```\n\n";

            if ($result['success'] ?? false) {
                $report .= "**Résultat obtenu :**\n```markdown\n{$result['markdown']}\n```\n\n";
            } else {
                $report .= "**Erreur :** {$result['error']}\n\n";
            }

            $report .= "---\n\n";
        }

        $report .= "## Recommandations pour l'amélioration\n\n";
        $report .= "Sur la base des résultats ci-dessus, une IA peut analyser :\n";
        $report .= "1. Les tests échoués et proposer des améliorations de prompts\n";
        $report .= "2. La qualité du markdown généré vs attendu\n";
        $report .= "3. Les patterns de succès/échec par catégorie\n";
        $report .= "4. Des suggestions pour de nouveaux tests pertinents\n\n";

        return $report;
    }
}

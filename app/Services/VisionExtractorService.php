<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentDeployment;
use App\Models\VisionSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class VisionExtractorService
{
    private VisionSetting $settings;

    /**
     * Override config from Agent or Deployment
     * @var array{host?: string, port?: int, model?: string}|null
     */
    private ?array $configOverride = null;

    public function __construct(?array $configOverride = null)
    {
        $this->settings = VisionSetting::getInstance();
        $this->configOverride = $configOverride;
    }

    /**
     * Create a service instance configured for a specific Agent
     */
    public static function forAgent(Agent $agent): self
    {
        $config = $agent->getVisionConfig();

        // Only set override if different from global
        $globalSettings = VisionSetting::getInstance();
        $hasOverride = $config['host'] !== $globalSettings->ollama_host
            || $config['port'] !== $globalSettings->ollama_port
            || $config['model'] !== $globalSettings->model;

        return new self($hasOverride ? $config : null);
    }

    /**
     * Create a service instance configured for a specific Deployment
     */
    public static function forDeployment(AgentDeployment $deployment): self
    {
        $config = $deployment->getVisionConfig();

        return new self($config);
    }

    /**
     * Get the effective Ollama URL (override > global)
     */
    private function getOllamaUrl(): string
    {
        if ($this->configOverride) {
            $host = $this->configOverride['host'] ?? $this->settings->ollama_host;
            $port = $this->configOverride['port'] ?? $this->settings->ollama_port;
            return "http://{$host}:{$port}";
        }

        return $this->settings->getOllamaUrl();
    }

    /**
     * Get the effective model (override > global)
     */
    private function getModel(): string
    {
        if ($this->configOverride && !empty($this->configOverride['model'])) {
            return $this->configOverride['model'];
        }

        return $this->settings->model;
    }

    /**
     * Extrait le texte d'un PDF en utilisant un modèle vision
     *
     * @param string $pdfPath Chemin absolu vers le fichier PDF
     * @param string $documentId Identifiant unique pour le stockage
     * @return array{
     *     success: bool,
     *     markdown: string,
     *     pages: array,
     *     errors: array,
     *     metadata: array
     * }
     */
    public function extractFromPdf(string $pdfPath, string $documentId): array
    {
        $startTime = microtime(true);
        $storagePath = $this->settings->getStoragePath($documentId);

        Log::info('Vision extraction started', [
            'pdf_path' => $pdfPath,
            'document_id' => $documentId,
            'model' => $this->getModel(),
        ]);

        // Étape 1: Convertir le PDF en images
        $imagesResult = $this->convertPdfToImages($pdfPath, $storagePath);

        if (!$imagesResult['success']) {
            return [
                'success' => false,
                'markdown' => '',
                'pages' => [],
                'errors' => $imagesResult['errors'],
                'metadata' => [
                    'stage' => 'pdf_to_images',
                    'duration_seconds' => microtime(true) - $startTime,
                ],
            ];
        }

        // Étape 2: Extraire le texte de chaque image avec le modèle vision
        $pages = [];
        $errors = [];
        $allMarkdown = [];

        foreach ($imagesResult['images'] as $index => $imagePath) {
            $pageNumber = $index + 1;

            // Vérifier la limite de pages
            if ($pageNumber > $this->settings->max_pages) {
                Log::warning('Vision extraction: max pages reached', [
                    'document_id' => $documentId,
                    'max_pages' => $this->settings->max_pages,
                ]);
                break;
            }

            $pageResult = $this->extractFromImage($imagePath, $pageNumber);

            if ($pageResult['success']) {
                $markdown = $pageResult['markdown'];
                $allMarkdown[] = $markdown;

                // Stocker le markdown de la page si configuré
                $markdownPath = null;
                if ($this->settings->store_markdown) {
                    $markdownPath = "{$storagePath}/page_{$pageNumber}.md";
                    Storage::disk($this->settings->storage_disk)
                        ->put($markdownPath, $markdown);
                }

                $pages[] = [
                    'page' => $pageNumber,
                    'image_path' => $this->settings->store_images ? $imagePath : null,
                    'markdown_path' => $markdownPath,
                    'markdown_length' => strlen($markdown),
                    'processing_time' => $pageResult['processing_time'],
                ];
            } else {
                $errors[] = [
                    'page' => $pageNumber,
                    'error' => $pageResult['error'],
                ];
            }
        }

        // Combiner tout le markdown
        $fullMarkdown = implode("\n\n---\n\n", $allMarkdown);

        // Stocker le markdown complet
        if ($this->settings->store_markdown && !empty($fullMarkdown)) {
            Storage::disk($this->settings->storage_disk)
                ->put("{$storagePath}/full_document.md", $fullMarkdown);
        }

        // Nettoyer les images si pas de stockage
        if (!$this->settings->store_images) {
            foreach ($imagesResult['images'] as $imagePath) {
                Storage::disk($this->settings->storage_disk)->delete($imagePath);
            }
        }

        $duration = microtime(true) - $startTime;

        Log::info('Vision extraction completed', [
            'document_id' => $documentId,
            'pages_processed' => count($pages),
            'errors_count' => count($errors),
            'duration_seconds' => round($duration, 2),
        ]);

        return [
            'success' => count($pages) > 0,
            'markdown' => $fullMarkdown,
            'pages' => $pages,
            'errors' => $errors,
            'metadata' => [
                // Étape 1: PDF → Images
                'pdf_converter' => $imagesResult['tool'] ?? 'unknown',
                'dpi' => $imagesResult['dpi'] ?? $this->settings->image_dpi,
                // Étape 2: Images → Markdown
                'vision_model' => $this->getModel(),
                'vision_library' => 'Ollama API',
                // Stats
                'model' => $this->getModel(),
                'total_pages' => count($imagesResult['images']),
                'pages_processed' => count($pages),
                'duration_seconds' => round($duration, 2),
                'storage_path' => $storagePath,
            ],
        ];
    }

    /**
     * Extrait le texte d'une image unique avec le modèle vision
     */
    public function extractFromImage(string $imagePath, int $pageNumber = 1): array
    {
        $startTime = microtime(true);

        try {
            // Lire l'image et l'encoder en base64
            $disk = $this->settings->storage_disk;
            if (Storage::disk($disk)->exists($imagePath)) {
                $imageContent = Storage::disk($disk)->get($imagePath);
            } elseif (file_exists($imagePath)) {
                $imageContent = file_get_contents($imagePath);
            } else {
                throw new \RuntimeException("Image not found: {$imagePath}");
            }

            $base64Image = base64_encode($imageContent);

            // Appel à Ollama
            $response = Http::timeout($this->settings->timeout_seconds)
                ->post($this->getOllamaUrl() . '/api/generate', [
                    'model' => $this->getModel(),
                    'prompt' => $this->settings->system_prompt ?? VisionSetting::getDefaultPrompt(),
                    'images' => [$base64Image],
                    'stream' => false,
                    'options' => [
                        'temperature' => 0.1, // Basse température pour extraction fidèle
                        'num_predict' => 4096,
                    ],
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException('Ollama error: ' . $response->body());
            }

            $result = $response->json();
            $markdown = $result['response'] ?? '';

            // Nettoyer le markdown (enlever les balises de code si présentes)
            $markdown = $this->cleanMarkdown($markdown);

            return [
                'success' => true,
                'markdown' => $markdown,
                'processing_time' => round(microtime(true) - $startTime, 2),
            ];

        } catch (\Exception $e) {
            Log::error('Vision extraction failed for page', [
                'page' => $pageNumber,
                'image_path' => $imagePath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'processing_time' => round(microtime(true) - $startTime, 2),
            ];
        }
    }

    /**
     * Convertit un PDF en images (une par page)
     */
    private function convertPdfToImages(string $pdfPath, string $outputPath): array
    {
        $disk = $this->settings->storage_disk;
        $dpi = $this->settings->image_dpi;

        // Créer le répertoire de sortie
        Storage::disk($disk)->makeDirectory($outputPath);
        $absoluteOutputPath = Storage::disk($disk)->path($outputPath);

        // Utiliser pdftoppm (plus rapide et meilleure qualité que ImageMagick)
        $outputPrefix = "{$absoluteOutputPath}/page";

        $result = Process::timeout(300)->run([
            'pdftoppm',
            '-png',
            '-r', (string) $dpi,
            $pdfPath,
            $outputPrefix,
        ]);

        if (!$result->successful()) {
            // Fallback sur ImageMagick
            return $this->convertPdfToImagesWithImageMagick($pdfPath, $outputPath);
        }

        // Récupérer les images générées
        $images = [];
        $files = Storage::disk($disk)->files($outputPath);

        // Trier par numéro de page
        natsort($files);

        foreach ($files as $file) {
            if (preg_match('/page-?\d+\.png$/i', $file)) {
                $images[] = $file;
            }
        }

        if (empty($images)) {
            return [
                'success' => false,
                'images' => [],
                'errors' => ['No images generated from PDF'],
            ];
        }

        Log::info('PDF converted to images', [
            'pdf_path' => $pdfPath,
            'image_count' => count($images),
            'dpi' => $dpi,
            'tool' => 'pdftoppm',
        ]);

        return [
            'success' => true,
            'images' => $images,
            'errors' => [],
            'tool' => 'pdftoppm (poppler-utils)',
            'dpi' => $dpi,
        ];
    }

    /**
     * Fallback: conversion avec ImageMagick
     */
    private function convertPdfToImagesWithImageMagick(string $pdfPath, string $outputPath): array
    {
        $disk = $this->settings->storage_disk;
        $dpi = $this->settings->image_dpi;
        $absoluteOutputPath = Storage::disk($disk)->path($outputPath);

        $result = Process::timeout(300)->run([
            'convert',
            '-density', (string) $dpi,
            $pdfPath,
            '-quality', '90',
            "{$absoluteOutputPath}/page-%03d.png",
        ]);

        if (!$result->successful()) {
            return [
                'success' => false,
                'images' => [],
                'errors' => ['PDF conversion failed: ' . $result->errorOutput()],
            ];
        }

        // Récupérer les images générées
        $images = [];
        $files = Storage::disk($disk)->files($outputPath);
        natsort($files);

        foreach ($files as $file) {
            if (preg_match('/page-\d+\.png$/i', $file)) {
                $images[] = $file;
            }
        }

        return [
            'success' => count($images) > 0,
            'images' => $images,
            'errors' => count($images) === 0 ? ['No images generated'] : [],
            'tool' => 'ImageMagick (convert)',
            'dpi' => $dpi,
        ];
    }

    /**
     * Nettoie le markdown généré par le modèle
     */
    private function cleanMarkdown(string $markdown): string
    {
        // Enlever les blocs de code markdown si le modèle les a ajoutés
        $markdown = preg_replace('/^```(?:markdown)?\n?/m', '', $markdown);
        $markdown = preg_replace('/\n?```$/m', '', $markdown);

        // Normaliser les sauts de ligne
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);

        return trim($markdown);
    }

    /**
     * Vérifie la connexion Ollama (avec override si configuré)
     */
    private function checkOllamaConnection(): array
    {
        try {
            $url = $this->getOllamaUrl();
            $model = $this->getModel();

            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->get($url . '/api/tags');

            if ($response->successful()) {
                $models = collect($response->json('models', []))
                    ->pluck('name')
                    ->toArray();

                $hasConfiguredModel = in_array($model, $models) ||
                    in_array($model . ':latest', $models);

                return [
                    'connected' => true,
                    'url' => $url,
                    'models_available' => $models,
                    'configured_model_installed' => $hasConfiguredModel,
                ];
            }

            return [
                'connected' => false,
                'url' => $url,
                'error' => 'Ollama responded with status ' . $response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'url' => $this->getOllamaUrl(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Vérifie si le service est disponible
     */
    public function isAvailable(): bool
    {
        $check = $this->checkOllamaConnection();
        return $check['connected'] && ($check['configured_model_installed'] ?? false);
    }

    /**
     * Retourne les informations de diagnostic
     */
    public function getDiagnostics(): array
    {
        $ollamaCheck = $this->checkOllamaConnection();

        // Vérifier pdftoppm
        $pdftoppmResult = Process::run(['which', 'pdftoppm']);
        $hasPdftoppm = $pdftoppmResult->successful();

        // Vérifier ImageMagick
        $convertResult = Process::run(['which', 'convert']);
        $hasImageMagick = $convertResult->successful();

        $model = $this->getModel();
        $modelInfo = VisionSetting::AVAILABLE_MODELS[$model] ?? null;

        return [
            'ollama' => $ollamaCheck,
            'model' => $model,
            'model_info' => $modelInfo,
            'cpu_compatible' => $modelInfo['cpu_compatible'] ?? false,
            'has_override' => $this->configOverride !== null,
            'pdf_converter' => [
                'pdftoppm' => $hasPdftoppm,
                'imagemagick' => $hasImageMagick,
            ],
            'storage' => [
                'disk' => $this->settings->storage_disk,
                'path' => $this->settings->storage_path,
                'store_images' => $this->settings->store_images,
                'store_markdown' => $this->settings->store_markdown,
            ],
        ];
    }
}

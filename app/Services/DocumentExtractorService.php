<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use League\HTMLToMarkdown\HtmlConverter;
use Smalot\PdfParser\Parser as PdfParser;
use thiagoalessio\TesseractOCR\TesseractOCR;

class DocumentExtractorService
{
    /**
     * Seuil de mots tronqués pour déclencher l'OCR
     * Si plus de X% des mots semblent tronqués, on utilise l'OCR
     */
    private const OCR_FALLBACK_THRESHOLD = 0.05; // 5%

    private ?VisionExtractorService $visionService = null;

    /**
     * Get the vision service (lazy loaded)
     */
    private function getVisionService(): VisionExtractorService
    {
        if ($this->visionService === null) {
            $this->visionService = app(VisionExtractorService::class);
        }
        return $this->visionService;
    }

    /**
     * Extrait le texte d'un document
     */
    public function extract(Document $document): string
    {
        $storagePath = $document->storage_path;

        Log::info('DocumentExtractor: Starting extraction', [
            'document_id' => $document->id,
            'storage_path' => $storagePath,
            'document_type' => $document->document_type,
        ]);

        if (!Storage::disk('local')->exists($storagePath)) {
            throw new \RuntimeException("Fichier non trouvé: {$storagePath}");
        }

        $fullPath = Storage::disk('local')->path($storagePath);
        $extension = strtolower($document->document_type);

        Log::info('DocumentExtractor: File found', [
            'full_path' => $fullPath,
            'extension' => $extension,
            'file_size' => filesize($fullPath),
        ]);

        // Récupérer la méthode d'extraction (auto, text, ocr)
        $extractionMethod = $document->extraction_method ?? 'auto';

        $text = match ($extension) {
            'pdf' => $this->extractFromPdf($fullPath, $extractionMethod, $document),
            'txt', 'md' => $this->extractFromText($fullPath),
            'docx' => $this->extractFromDocx($fullPath),
            'doc' => $this->extractFromDoc($fullPath),
            'html', 'htm' => $this->extractFromHtml($fullPath, $document),
            'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'webp' => $this->extractFromImage($fullPath, $extractionMethod, $document),
            default => throw new \RuntimeException("Type de document non supporté: {$extension}"),
        };

        Log::info('DocumentExtractor: Extraction completed', [
            'document_id' => $document->id,
            'text_length' => strlen($text),
        ]);

        return $text;
    }

    /**
     * Extrait le texte d'un fichier PDF
     *
     * @param string $path Chemin vers le fichier PDF
     * @param string $method Méthode d'extraction: 'auto', 'text', 'ocr', ou 'vision'
     * @param Document|null $document Document pour stocker les métadonnées vision
     */
    private function extractFromPdf(string $path, string $method = 'auto', ?Document $document = null): string
    {
        Log::info('PDF extraction starting', [
            'path' => $path,
            'method' => $method,
        ]);

        // Mode Vision : utiliser un modèle de vision pour extraire le texte
        if ($method === 'vision') {
            return $this->extractFromPdfWithVision($path, $document);
        }

        // Mode OCR forcé : convertir le PDF en images et appliquer Tesseract
        if ($method === 'ocr') {
            if (!$this->isOcrAvailable()) {
                throw new \RuntimeException(
                    "Mode OCR demandé mais Tesseract n'est pas disponible. " .
                    "Installez tesseract-ocr dans le conteneur Docker."
                );
            }

            Log::info('Using forced OCR mode for PDF', ['path' => $path]);

            try {
                $ocrText = $this->extractFromPdfWithOcr($path, $document);
                $ocrClean = $this->cleanText($ocrText);

                if (!empty($ocrClean)) {
                    Log::info('Forced OCR extraction completed', [
                        'path' => $path,
                        'text_length' => strlen($ocrClean),
                    ]);
                    return $ocrClean;
                }
            } catch (\Exception $e) {
                throw new \RuntimeException("Erreur OCR: {$e->getMessage()}");
            }

            throw new \RuntimeException("L'extraction OCR n'a produit aucun texte.");
        }

        // Mode texte forcé ou auto : essayer les méthodes textuelles
        $pdftotextResult = '';
        $pdfparserResult = '';

        // Méthode 1: Essayer avec pdftotext (poppler-utils)
        $pdftotextResult = $this->extractWithPdfToText($path);

        // Méthode 2: Essayer avec smalot/pdfparser
        try {
            Log::info('Trying smalot/pdfparser', ['path' => $path]);
            $parser = new PdfParser();
            $pdf = $parser->parseFile($path);
            $pdfparserResult = $pdf->getText();
        } catch (\Exception $e) {
            Log::warning('smalot/pdfparser failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        // Comparer les résultats et choisir le meilleur
        $pdftotextClean = $this->cleanText($pdftotextResult);
        $pdfparserClean = $this->cleanText($pdfparserResult);

        // Compter les caractères problématiques et mots tronqués
        $pdftotextBadChars = $this->countProblematicChars($pdftotextClean);
        $pdfparserBadChars = $this->countProblematicChars($pdfparserClean);
        $pdftotextTruncated = $this->countTruncatedWords($pdftotextClean);
        $pdfparserTruncated = $this->countTruncatedWords($pdfparserClean);

        Log::info('PDF extraction comparison', [
            'path' => $path,
            'pdftotext_length' => strlen($pdftotextClean),
            'pdftotext_bad_chars' => $pdftotextBadChars,
            'pdftotext_truncated_words' => $pdftotextTruncated,
            'pdfparser_length' => strlen($pdfparserClean),
            'pdfparser_bad_chars' => $pdfparserBadChars,
            'pdfparser_truncated_words' => $pdfparserTruncated,
        ]);

        // Choisir le meilleur résultat textuel
        $bestText = '';
        $bestTruncatedRatio = 1.0;

        if (!empty($pdftotextClean) && !empty($pdfparserClean)) {
            // Calculer le ratio de mots tronqués
            $pdftotextRatio = $this->getTruncatedRatio($pdftotextClean);
            $pdfparserRatio = $this->getTruncatedRatio($pdfparserClean);

            if ($pdftotextBadChars + $pdftotextTruncated <= $pdfparserBadChars + $pdfparserTruncated) {
                $bestText = $pdftotextClean;
                $bestTruncatedRatio = $pdftotextRatio;
                Log::info('Selected pdftotext as best text extraction', ['path' => $path]);
            } else {
                $bestText = $pdfparserClean;
                $bestTruncatedRatio = $pdfparserRatio;
                Log::info('Selected pdfparser as best text extraction', ['path' => $path]);
            }
        } elseif (!empty($pdftotextClean)) {
            $bestText = $pdftotextClean;
            $bestTruncatedRatio = $this->getTruncatedRatio($pdftotextClean);
        } elseif (!empty($pdfparserClean)) {
            $bestText = $pdfparserClean;
            $bestTruncatedRatio = $this->getTruncatedRatio($pdfparserClean);
        }

        // En mode texte forcé, retourner le résultat même avec des problèmes
        if ($method === 'text') {
            if (!empty($bestText)) {
                Log::info('Using forced text mode result', [
                    'path' => $path,
                    'truncated_ratio' => $bestTruncatedRatio,
                ]);
                return $bestText;
            }

            throw new \RuntimeException(
                "Impossible d'extraire le texte du PDF en mode texte. " .
                "Essayez le mode OCR pour ce document."
            );
        }

        // Mode auto : si le taux de mots tronqués dépasse le seuil, essayer l'OCR
        if ($bestTruncatedRatio > self::OCR_FALLBACK_THRESHOLD && $this->isOcrAvailable()) {
            Log::info('Text extraction has too many truncated words, trying OCR fallback', [
                'path' => $path,
                'truncated_ratio' => $bestTruncatedRatio,
                'threshold' => self::OCR_FALLBACK_THRESHOLD,
            ]);

            try {
                $ocrText = $this->extractFromPdfWithOcr($path, $document);
                $ocrClean = $this->cleanText($ocrText);
                $ocrTruncatedRatio = $this->getTruncatedRatio($ocrClean);

                Log::info('OCR extraction completed', [
                    'path' => $path,
                    'ocr_length' => strlen($ocrClean),
                    'ocr_truncated_ratio' => $ocrTruncatedRatio,
                ]);

                // Utiliser l'OCR si meilleur résultat
                if (!empty($ocrClean) && $ocrTruncatedRatio < $bestTruncatedRatio) {
                    Log::info('Using OCR result (better quality)', ['path' => $path]);
                    return $ocrClean;
                }
            } catch (\Exception $e) {
                Log::warning('OCR fallback failed', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($bestText)) {
            return $bestText;
        }

        // Si tout échoue, essayer l'OCR en dernier recours
        if ($this->isOcrAvailable()) {
            Log::info('All text extraction failed, trying OCR as last resort', ['path' => $path]);
            try {
                $ocrText = $this->extractFromPdfWithOcr($path, $document);
                $ocrClean = $this->cleanText($ocrText);
                if (!empty($ocrClean)) {
                    return $ocrClean;
                }
            } catch (\Exception $e) {
                Log::error('OCR last resort failed', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new \RuntimeException(
            "Impossible d'extraire le texte du PDF. " .
            "Le fichier est peut-être protégé ou corrompu."
        );
    }

    /**
     * Extrait le texte d'un PDF en utilisant OCR (Tesseract)
     * Convertit chaque page en image puis applique l'OCR
     *
     * @param string $path Chemin vers le fichier PDF
     * @param Document|null $document Document pour stocker les métadonnées
     * @return string Texte extrait
     */
    private function extractFromPdfWithOcr(string $path, ?Document $document = null): string
    {
        $startTime = microtime(true);
        $ocrMetadata = [
            'method' => 'ocr',
            'pdf_converter' => 'pdftoppm (poppler-utils)',
            'ocr_engine' => 'Tesseract OCR',
            'ocr_languages' => 'fra+eng',
            'dpi' => 300,
            'pages' => [],
            'errors' => [],
        ];

        // Vérifier si pdftoppm est disponible pour convertir PDF en images
        $checkResult = Process::run('pdftoppm -v 2>&1');
        if (!$checkResult->successful() && !str_contains($checkResult->errorOutput(), 'pdftoppm')) {
            throw new \RuntimeException('pdftoppm (poppler-utils) is required for PDF OCR');
        }

        $tempDir = sys_get_temp_dir() . '/pdf_ocr_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            $pdfConversionStart = microtime(true);

            // Convertir le PDF en images PNG (une par page)
            $command = sprintf(
                'pdftoppm -png -r 300 %s %s/page',
                escapeshellarg($path),
                escapeshellarg($tempDir)
            );

            $result = Process::timeout(300)->run($command);
            if (!$result->successful()) {
                throw new \RuntimeException('Failed to convert PDF to images: ' . $result->errorOutput());
            }

            $ocrMetadata['pdf_conversion_time'] = round(microtime(true) - $pdfConversionStart, 2);

            // Trouver toutes les images générées
            $images = glob($tempDir . '/page-*.png');
            if (empty($images)) {
                // Essayer avec le format sans tiret
                $images = glob($tempDir . '/page*.png');
            }

            if (empty($images)) {
                throw new \RuntimeException('No images generated from PDF');
            }

            sort($images); // Assurer l'ordre des pages
            $ocrMetadata['total_pages'] = count($images);

            // Appliquer l'OCR sur chaque image
            $fullText = '';
            foreach ($images as $index => $imagePath) {
                $pageNumber = $index + 1;
                $pageStart = microtime(true);

                Log::info('OCR processing page', [
                    'page' => $pageNumber,
                    'total' => count($images),
                ]);

                try {
                    $pageText = $this->extractFromImageWithOcr($imagePath);
                    $fullText .= $pageText . "\n\n";

                    $ocrMetadata['pages'][] = [
                        'page' => $pageNumber,
                        'text_length' => strlen($pageText),
                        'processing_time' => round(microtime(true) - $pageStart, 2),
                    ];
                } catch (\Exception $e) {
                    $ocrMetadata['errors'][] = [
                        'page' => $pageNumber,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $ocrMetadata['total_processing_time'] = round(microtime(true) - $startTime, 2);
            $ocrMetadata['pages_processed'] = count($ocrMetadata['pages']);
            $ocrMetadata['extracted_at'] = now()->toIso8601String();

            // Stocker les métadonnées dans le document
            if ($document) {
                $existingMetadata = $document->extraction_metadata ?? [];
                $document->update([
                    'extraction_metadata' => array_merge($existingMetadata, [
                        'ocr_extraction' => $ocrMetadata,
                    ]),
                ]);
            }

            return trim($fullText);

        } finally {
            // Nettoyer les fichiers temporaires
            $files = glob($tempDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($tempDir);
        }
    }

    /**
     * Extrait le texte d'une image avec Tesseract OCR (méthode interne)
     */
    private function extractFromImageWithOcr(string $path): string
    {
        $tesseract = $this->findTesseractExecutable();
        $escapedPath = escapeshellarg($path);

        // Exécuter tesseract directement via shell_exec
        // -l fra+eng : français + anglais
        // --psm 3 : segmentation automatique
        // --oem 3 : LSTM + Legacy engine
        // stdout : sortie vers stdout au lieu d'un fichier
        $command = "{$tesseract} {$escapedPath} stdout -l fra+eng --psm 3 --oem 3 2>&1";

        $text = shell_exec($command);

        if ($text === null) {
            throw new \RuntimeException("La commande tesseract a échoué");
        }

        // Vérifier si c'est un message d'erreur
        if (str_starts_with(trim($text), 'Error') || str_contains($text, 'error opening')) {
            throw new \RuntimeException("Erreur Tesseract: " . trim($text));
        }

        return $this->cleanText($text);
    }

    /**
     * Extrait le texte d'une image avec Tesseract OCR ou Vision
     * Utilise shell_exec directement car la librairie TesseractOCR a des problèmes
     * avec proc_open dans certains environnements PHP-FPM
     *
     * @param string $path Chemin vers l'image
     * @param string $method Méthode: 'ocr' (default) ou 'vision'
     * @param Document|null $document Document pour stocker les métadonnées
     */
    private function extractFromImage(string $path, string $method = 'ocr', ?Document $document = null): string
    {
        // Mode Vision pour les images
        if ($method === 'vision') {
            return $this->extractFromImageWithVision($path, $document);
        }

        if (!$this->isOcrAvailable()) {
            throw new \RuntimeException(
                "Tesseract OCR n'est pas installé ou non détecté. " .
                "Vérifiez que le package tesseract-ocr est installé dans le conteneur Docker."
            );
        }

        $startTime = microtime(true);
        Log::info('Extracting text from image with OCR', ['path' => $path]);

        try {
            $text = $this->extractFromImageWithOcr($path);
            $processingTime = round(microtime(true) - $startTime, 2);

            Log::info('OCR extraction successful', [
                'path' => $path,
                'text_length' => strlen($text),
            ]);

            // Stocker les métadonnées si document disponible
            if ($document) {
                $existingMetadata = $document->extraction_metadata ?? [];
                $document->update([
                    'extraction_metadata' => array_merge($existingMetadata, [
                        'ocr_extraction' => [
                            'method' => 'ocr',
                            'ocr_engine' => 'Tesseract OCR',
                            'ocr_languages' => 'fra+eng',
                            'source_type' => 'image',
                            'text_length' => strlen($text),
                            'processing_time' => $processingTime,
                            'extracted_at' => now()->toIso8601String(),
                        ],
                    ]),
                ]);
            }

            return $text;

        } catch (\Exception $e) {
            Log::error('OCR extraction failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException("Erreur OCR: {$e->getMessage()}");
        }
    }

    /**
     * Extrait le texte d'un PDF en utilisant un modèle de vision
     */
    private function extractFromPdfWithVision(string $path, ?Document $document = null): string
    {
        $visionService = $this->getVisionService();

        if (!$visionService->isAvailable()) {
            throw new \RuntimeException(
                "Le service Vision n'est pas disponible. " .
                "Vérifiez qu'Ollama est accessible et qu'un modèle vision est installé."
            );
        }

        $documentId = $document?->uuid ?? uniqid('vision_');

        Log::info('Extracting PDF with Vision model', [
            'path' => $path,
            'document_id' => $documentId,
        ]);

        $result = $visionService->extractFromPdf($path, $documentId);

        if (!$result['success']) {
            $errorMessages = array_map(fn ($e) => $e['error'] ?? 'Unknown', $result['errors']);
            throw new \RuntimeException(
                "Extraction Vision échouée: " . implode(', ', $errorMessages)
            );
        }

        // Stocker les métadonnées complètes dans le document (incluant pages et erreurs)
        if ($document) {
            $existingMetadata = $document->extraction_metadata ?? [];
            $document->update([
                'extraction_metadata' => array_merge($existingMetadata, [
                    'vision_extraction' => array_merge($result['metadata'], [
                        'pages' => $result['pages'] ?? [],
                        'errors' => $result['errors'] ?? [],
                    ]),
                ]),
            ]);
        }

        Log::info('Vision extraction completed', [
            'path' => $path,
            'markdown_length' => strlen($result['markdown']),
            'pages_processed' => $result['metadata']['pages_processed'] ?? 0,
        ]);

        return $result['markdown'];
    }

    /**
     * Extrait le texte d'une image en utilisant un modèle de vision
     */
    private function extractFromImageWithVision(string $path, ?Document $document = null): string
    {
        $visionService = $this->getVisionService();

        if (!$visionService->isAvailable()) {
            throw new \RuntimeException(
                "Le service Vision n'est pas disponible. " .
                "Vérifiez qu'Ollama est accessible et qu'un modèle vision est installé."
            );
        }

        Log::info('Extracting image with Vision model', ['path' => $path]);

        // Pour une image seule, on l'extrait directement
        $result = $visionService->extractFromImage($path);

        if (!$result['success']) {
            throw new \RuntimeException("Extraction Vision échouée: " . ($result['error'] ?? 'Unknown'));
        }

        // Stocker les métadonnées si document disponible
        if ($document) {
            $existingMetadata = $document->extraction_metadata ?? [];
            $document->update([
                'extraction_metadata' => array_merge($existingMetadata, [
                    'vision_extraction' => [
                        'model' => app(VisionExtractorService::class)->getDiagnostics()['model'] ?? null,
                        'processing_time' => $result['processing_time'],
                    ],
                ]),
            ]);
        }

        Log::info('Vision image extraction completed', [
            'path' => $path,
            'markdown_length' => strlen($result['markdown']),
        ]);

        return $result['markdown'];
    }

    /**
     * Vérifie si Tesseract OCR est disponible
     */
    private function isOcrAvailable(): bool
    {
        static $available = null;

        if ($available === null) {
            // tesseract --version peut retourner sur stdout ou stderr selon la version
            // On capture les deux et on vérifie si "tesseract" apparaît dans la sortie
            $result = Process::run('tesseract --version 2>&1');
            $output = $result->output();

            // Vérifier si la sortie contient "tesseract" (insensible à la casse)
            $available = stripos($output, 'tesseract') !== false;

            if ($available) {
                Log::info('Tesseract OCR available', [
                    'version' => explode("\n", $output)[0] ?? 'unknown',
                ]);
            } else {
                Log::info('Tesseract OCR not available on this system', [
                    'output' => $output,
                    'exit_code' => $result->exitCode(),
                ]);
            }
        }

        return $available;
    }

    /**
     * Retourne le chemin du binaire tesseract
     * Configurable via la variable d'environnement TESSERACT_PATH
     */
    private function findTesseractExecutable(): string
    {
        // Permet de surcharger via .env si nécessaire
        return env('TESSERACT_PATH', '/usr/bin/tesseract');
    }

    /**
     * Compte les mots qui semblent tronqués (ligatures manquantes)
     * Détecte les patterns comme "rénovaon" au lieu de "rénovation"
     */
    private function countTruncatedWords(string $text): int
    {
        // Patterns de mots français courants avec ligatures ti, fi, fl
        // qui apparaissent tronqués quand la ligature est mal décodée
        $patterns = [
            // Mots avec "ti" manquant
            '/\b\w*aon\b/iu',      // rénovation → rénovaon, information → informaon
            '/\b\w*ère\b/iu',      // matière → maère (mais attention aux vrais mots)
            '/\b\w*on\b/iu',       // question → queson (trop large, on vérifie le contexte)

            // Mots avec "fi" manquant
            '/\b\w*caon\b/iu',     // modification → modication
            '/\b\w*cher\b/iu',     // fichier → cher (si isolé)

            // Mots avec "fl" manquant
            '/\b\w*exible\b/iu',   // flexible → exible

            // Pattern général: consonne suivie de voyelle sans transition normale
            // Ceci détecte les cas où une ligature a été supprimée
        ];

        $count = 0;

        // Détecter les mots tronqués spécifiques au français
        $truncatedPatterns = [
            'aon' => 'ation',      // rénovation
            'eers' => 'étiers',    // métiers
            'èes' => 'ètres',      // paramètres
            'maon' => 'mation',    // information
            'caon' => 'cation',    // modification
            'raon' => 'ration',    // configuration
            'saon' => 'sation',    // organisation
        ];

        foreach ($truncatedPatterns as $truncated => $full) {
            $count += substr_count(strtolower($text), $truncated);
        }

        return $count;
    }

    /**
     * Calcule le ratio de mots potentiellement tronqués
     */
    private function getTruncatedRatio(string $text): float
    {
        if (empty($text)) {
            return 0.0;
        }

        $wordCount = str_word_count($text);
        if ($wordCount === 0) {
            return 0.0;
        }

        $truncatedCount = $this->countTruncatedWords($text);

        return $truncatedCount / $wordCount;
    }

    /**
     * Compte les caractères problématiques dans un texte
     * (caractères de remplacement, caractères non imprimables)
     */
    private function countProblematicChars(string $text): int
    {
        // Caractère de remplacement Unicode U+FFFD
        $count = substr_count($text, "\xEF\xBF\xBD");

        // Compter aussi les ? isolés qui pourraient être des caractères non décodés
        // (mais pas dans des contextes normaux comme les questions)
        // On cherche les ? entourés de lettres (typique d'un caractère perdu)
        preg_match_all('/[a-zA-ZÀ-ÿ]\?[a-zA-ZÀ-ÿ]/', $text, $matches);
        $count += count($matches[0]);

        return $count;
    }

    /**
     * Extrait le texte avec pdftotext (poppler-utils)
     * Essaie plusieurs modes pour optimiser la qualité d'extraction
     */
    private function extractWithPdfToText(string $path): string
    {
        // Vérifier si pdftotext est disponible
        $checkResult = Process::run('pdftotext -v 2>&1');
        if (!$checkResult->successful() && !str_contains($checkResult->errorOutput(), 'pdftotext')) {
            Log::info('pdftotext not available on this system');
            return '';
        }

        // Essayer d'abord avec -raw qui gère souvent mieux les ligatures
        // car il extrait le texte dans l'ordre du flux PDF sans mise en page
        $modes = [
            ['-raw', 'raw mode'],
            ['-layout', 'layout mode'],
            ['', 'default mode'],
        ];

        $bestResult = '';
        $bestBadChars = PHP_INT_MAX;

        foreach ($modes as [$option, $modeName]) {
            try {
                $command = sprintf(
                    'pdftotext %s -enc UTF-8 %s -',
                    $option,
                    escapeshellarg($path)
                );

                $result = Process::timeout(60)->run($command);

                if ($result->successful()) {
                    $output = $result->output();
                    $badChars = substr_count($output, "\xEF\xBF\xBD");
                    $truncated = $this->countTruncatedWords($output);
                    $totalBad = $badChars + $truncated;

                    Log::info("pdftotext {$modeName}", [
                        'path' => $path,
                        'bad_chars' => $badChars,
                        'truncated_words' => $truncated,
                    ]);

                    if ($totalBad < $bestBadChars) {
                        $bestResult = $output;
                        $bestBadChars = $totalBad;
                    }

                    // Si parfait, on arrête
                    if ($totalBad === 0) {
                        return $output;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("pdftotext exception in {$modeName}", [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $bestResult;
    }

    /**
     * Extrait le texte d'un fichier texte simple
     */
    private function extractFromText(string $path): string
    {
        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException("Impossible de lire le fichier texte");
        }

        return $this->cleanText($content);
    }

    /**
     * Extrait le texte d'un fichier DOCX
     */
    private function extractFromDocx(string $path): string
    {
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw new \RuntimeException("Impossible d'ouvrir le fichier DOCX");
            }

            $content = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($content === false) {
                throw new \RuntimeException("Structure DOCX invalide");
            }

            // Extraire le texte du XML
            $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA);

            if ($xml === false) {
                throw new \RuntimeException("Erreur parsing XML du DOCX");
            }

            // Récupérer tout le texte des paragraphes
            $text = '';
            $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            foreach ($xml->xpath('//w:t') as $node) {
                $text .= (string) $node;
            }

            // Séparer les paragraphes
            foreach ($xml->xpath('//w:p') as $p) {
                $paragraphText = '';
                foreach ($p->xpath('.//w:t') as $t) {
                    $paragraphText .= (string) $t;
                }
                if (!empty(trim($paragraphText))) {
                    $text .= $paragraphText . "\n\n";
                }
            }

            return $this->cleanText($text);
        } catch (\Exception $e) {
            Log::error('DOCX extraction failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Erreur extraction DOCX: {$e->getMessage()}");
        }
    }

    /**
     * Extrait le texte d'un fichier DOC (ancien format)
     */
    private function extractFromDoc(string $path): string
    {
        // Le format DOC est complexe, on essaie une extraction basique
        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException("Impossible de lire le fichier DOC");
        }

        // Extraction basique du texte (ne marche pas toujours parfaitement)
        $text = '';

        // Supprimer les caractères binaires tout en gardant le texte
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);

        // Garder seulement les caractères imprimables
        $text = preg_replace('/[^\x20-\x7E\x80-\xFF\n\r\t]/', ' ', $content);

        $text = $this->cleanText($text);

        if (strlen($text) < 10) {
            throw new \RuntimeException("Extraction DOC a échoué - contenu trop court. Convertissez en DOCX.");
        }

        return $text;
    }

    /**
     * Extrait le texte d'un fichier HTML en le convertissant en Markdown
     * Conserve la structure sémantique (titres, listes, tableaux, liens)
     *
     * @param string $path Chemin vers le fichier HTML
     * @param Document|null $document Document pour stocker les métadonnées
     */
    private function extractFromHtml(string $path, ?Document $document = null): string
    {
        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException("Impossible de lire le fichier HTML");
        }

        $result = $this->convertHtmlToMarkdown($content);

        // Stocker les métadonnées d'extraction si document disponible
        if ($document) {
            $existingMetadata = $document->extraction_metadata ?? [];
            $document->update([
                'extraction_metadata' => array_merge($existingMetadata, [
                    'html_extraction' => $result['metadata'],
                ]),
            ]);
        }

        Log::info('HTML to Markdown extraction completed', [
            'path' => $path,
            'html_size' => $result['metadata']['html_size'],
            'markdown_size' => $result['metadata']['markdown_size'],
            'compression_ratio' => $result['metadata']['compression_ratio'],
        ]);

        return $result['markdown'];
    }

    /**
     * Convertit du HTML en Markdown avec métadonnées de traçage
     *
     * @param string $html Contenu HTML brut
     * @return array{markdown: string, metadata: array}
     */
    public function convertHtmlToMarkdown(string $html): array
    {
        $startTime = microtime(true);
        $originalSize = strlen($html);

        // Supprimer les balises script et style avant conversion
        $cleanedHtml = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $cleanedHtml = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $cleanedHtml);
        $cleanedHtml = preg_replace('/<noscript\b[^>]*>(.*?)<\/noscript>/is', '', $cleanedHtml);

        // Supprimer les commentaires HTML
        $cleanedHtml = preg_replace('/<!--.*?-->/s', '', $cleanedHtml);

        // Compter les éléments structurels avant conversion
        $elementCounts = [
            'headings' => preg_match_all('/<h[1-6]\b/i', $cleanedHtml),
            'lists' => preg_match_all('/<[uo]l\b/i', $cleanedHtml),
            'tables' => preg_match_all('/<table\b/i', $cleanedHtml),
            'links' => preg_match_all('/<a\b/i', $cleanedHtml),
            'images' => preg_match_all('/<img\b/i', $cleanedHtml),
            'paragraphs' => preg_match_all('/<p\b/i', $cleanedHtml),
        ];

        // Configurer le convertisseur HTML → Markdown
        $converter = new HtmlConverter([
            'strip_tags' => true,
            'remove_nodes' => 'head meta nav footer aside iframe script style noscript',
            'hard_break' => true,
            'header_style' => 'atx', // # Style headers
            'bold_style' => '**',
            'italic_style' => '_',
            'list_item_style' => '-',
        ]);

        // Convertir en Markdown
        $markdown = $converter->convert($cleanedHtml);

        // Nettoyer le Markdown résultant
        $markdown = $this->cleanMarkdown($markdown);

        $processingTime = round((microtime(true) - $startTime) * 1000, 2);
        $markdownSize = strlen($markdown);

        return [
            'markdown' => $markdown,
            'metadata' => [
                'converter' => 'league/html-to-markdown',
                'html_size' => $originalSize,
                'cleaned_html_size' => strlen($cleanedHtml),
                'markdown_size' => $markdownSize,
                'compression_ratio' => $originalSize > 0
                    ? round((1 - $markdownSize / $originalSize) * 100, 1)
                    : 0,
                'elements_detected' => $elementCounts,
                'processing_time_ms' => $processingTime,
                'extracted_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Nettoie le Markdown généré
     */
    private function cleanMarkdown(string $markdown): string
    {
        // Normaliser les retours à la ligne
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

        // Supprimer les lignes vides multiples (max 2 newlines)
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);

        // Supprimer les espaces en fin de ligne
        $markdown = preg_replace('/[ \t]+$/m', '', $markdown);

        // Supprimer les espaces multiples (mais pas les newlines)
        $markdown = preg_replace('/[^\S\n]+/', ' ', $markdown);

        // Supprimer les lignes ne contenant que des espaces
        $markdown = preg_replace('/^\s+$/m', '', $markdown);

        return trim($markdown);
    }

    /**
     * Nettoie le texte extrait
     */
    private function cleanText(string $text): string
    {
        // Détecter et convertir l'encodage en UTF-8
        $text = $this->ensureUtf8($text);

        // Remplacer les ligatures typographiques par leurs caractères composants
        $text = $this->replaceLigatures($text);

        // Normaliser les retours à la ligne
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Supprimer les espaces multiples (mais pas les sauts de ligne)
        $text = preg_replace('/[^\S\n]+/', ' ', $text);

        // Supprimer les espaces en début/fin de ligne
        $text = preg_replace('/^[ \t]+|[ \t]+$/m', '', $text);

        // Normaliser les sauts de paragraphe pour les PDF
        // Détecte les lignes qui se terminent par une ponctuation de fin de phrase
        // suivies d'une ligne qui commence par une majuscule = nouveau paragraphe
        $text = preg_replace('/([.!?])\n([A-ZÀÂÄÉÈÊËÏÎÔÙÛÜÇ])/', "$1\n\n$2", $text);

        // Supprimer les lignes vides multiples (max 2 newlines)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Trim global
        return trim($text);
    }

    /**
     * S'assure que le texte est en UTF-8 valide
     * Note: Ne modifie pas l'encodage si déjà valide pour éviter de corrompre les données
     */
    private function ensureUtf8(string $text): string
    {
        if (empty($text)) {
            return $text;
        }

        // Si déjà UTF-8 valide, retourner tel quel sans modification
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        // Sinon seulement, tenter conversion depuis Windows-1252
        $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        if ($converted !== false) {
            return $converted;
        }

        return $text;
    }

    /**
     * Remplace les ligatures typographiques Unicode par leurs caractères composants
     * Corrige aussi les caractères de remplacement issus de ligatures mal décodées
     */
    private function replaceLigatures(string $text): string
    {
        // Ligatures Unicode standard (Latin Extended)
        $ligatures = [
            "\xEF\xAC\x80" => 'ff',   // ﬀ U+FB00
            "\xEF\xAC\x81" => 'fi',   // ﬁ U+FB01
            "\xEF\xAC\x82" => 'fl',   // ﬂ U+FB02
            "\xEF\xAC\x83" => 'ffi',  // ﬃ U+FB03
            "\xEF\xAC\x84" => 'ffl',  // ﬄ U+FB04
            "\xEF\xAC\x85" => 'st',   // ﬅ U+FB05 (long s t)
            "\xEF\xAC\x86" => 'st',   // ﬆ U+FB06
            "\xC5\x93" => 'oe',       // œ U+0153 (pas vraiment une ligature mais utile)
            "\xC3\x86" => 'AE',       // Æ U+00C6
            "\xC3\xA6" => 'ae',       // æ U+00E6
        ];

        $text = str_replace(array_keys($ligatures), array_values($ligatures), $text);

        // Supprimer les caractères de remplacement Unicode (U+FFFD) isolés
        // Souvent générés quand pdftotext ne peut pas décoder une ligature
        // On les remplace par rien car on ne peut pas deviner le caractère original
        $text = str_replace("\xEF\xBF\xBD", '', $text);

        return $text;
    }

    /**
     * Estime le nombre de tokens dans un texte
     */
    public function estimateTokenCount(string $text): int
    {
        // Approximation: 1 token ≈ 4 caractères en français
        return (int) ceil(mb_strlen($text) / 4);
    }
}

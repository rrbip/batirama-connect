<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;

class DocumentExtractorService
{
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

        $text = match ($extension) {
            'pdf' => $this->extractFromPdf($fullPath),
            'txt', 'md' => $this->extractFromText($fullPath),
            'docx' => $this->extractFromDocx($fullPath),
            'doc' => $this->extractFromDoc($fullPath),
            'html', 'htm' => $this->extractFromHtml($fullPath),
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
     */
    private function extractFromPdf(string $path): string
    {
        $text = '';

        // Méthode 1: Essayer avec pdftotext (poppler-utils) - meilleure qualité
        $text = $this->extractWithPdfToText($path);

        if (!empty($text)) {
            Log::info('PDF extracted with pdftotext', ['path' => $path]);
            return $this->cleanText($text);
        }

        // Méthode 2: Fallback sur smalot/pdfparser
        try {
            Log::info('Trying smalot/pdfparser', ['path' => $path]);
            $parser = new PdfParser();
            $pdf = $parser->parseFile($path);
            $text = $pdf->getText();

            if (!empty(trim($text))) {
                Log::info('PDF extracted with pdfparser', ['path' => $path]);
                return $this->cleanText($text);
            }
        } catch (\Exception $e) {
            Log::warning('smalot/pdfparser failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        // Si aucune méthode n'a fonctionné
        throw new \RuntimeException(
            "Impossible d'extraire le texte du PDF. " .
            "Le fichier est peut-être un scan (image), protégé, ou corrompu. " .
            "Essayez de convertir le PDF en texte avec un outil OCR."
        );
    }

    /**
     * Extrait le texte avec pdftotext (poppler-utils)
     */
    private function extractWithPdfToText(string $path): string
    {
        // Vérifier si pdftotext est disponible
        $checkResult = Process::run('which pdftotext');
        if (!$checkResult->successful()) {
            Log::info('pdftotext not available on this system');
            return '';
        }

        try {
            // -layout préserve la mise en page, -enc UTF-8 pour l'encodage
            $result = Process::timeout(60)->run(
                sprintf('pdftotext -layout -enc UTF-8 %s -', escapeshellarg($path))
            );

            if ($result->successful()) {
                return $result->output();
            }

            Log::warning('pdftotext failed', [
                'path' => $path,
                'error' => $result->errorOutput(),
            ]);
        } catch (\Exception $e) {
            Log::warning('pdftotext exception', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return '';
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
     * Extrait le texte d'un fichier HTML
     */
    private function extractFromHtml(string $path): string
    {
        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException("Impossible de lire le fichier HTML");
        }

        // Supprimer les balises script et style
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
        $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $content);

        // Convertir les balises de bloc en sauts de ligne
        $content = preg_replace('/<(br|p|div|h[1-6]|li)[^>]*>/i', "\n", $content);

        // Supprimer toutes les balises HTML restantes
        $text = strip_tags($content);

        // Décoder les entités HTML
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->cleanText($text);
    }

    /**
     * Nettoie le texte extrait
     */
    private function cleanText(string $text): string
    {
        // Normaliser les retours à la ligne
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Supprimer les espaces multiples
        $text = preg_replace('/[^\S\n]+/', ' ', $text);

        // Supprimer les lignes vides multiples
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Supprimer les espaces en début/fin de ligne
        $text = preg_replace('/^[ \t]+|[ \t]+$/m', '', $text);

        // Trim global
        return trim($text);
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

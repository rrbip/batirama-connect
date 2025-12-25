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

        // Compter les caractères de remplacement restants (ligatures perdues)
        $pdftotextBadChars = $this->countProblematicChars($pdftotextClean);
        $pdfparserBadChars = $this->countProblematicChars($pdfparserClean);

        Log::info('PDF extraction comparison', [
            'path' => $path,
            'pdftotext_length' => strlen($pdftotextClean),
            'pdftotext_bad_chars' => $pdftotextBadChars,
            'pdfparser_length' => strlen($pdfparserClean),
            'pdfparser_bad_chars' => $pdfparserBadChars,
        ]);

        // Choisir le résultat avec le moins de caractères problématiques
        // En cas d'égalité, préférer le plus long
        if (!empty($pdftotextClean) && !empty($pdfparserClean)) {
            if ($pdftotextBadChars < $pdfparserBadChars) {
                Log::info('Using pdftotext (fewer bad chars)', ['path' => $path]);
                return $pdftotextClean;
            } elseif ($pdfparserBadChars < $pdftotextBadChars) {
                Log::info('Using pdfparser (fewer bad chars)', ['path' => $path]);
                return $pdfparserClean;
            } else {
                // Égalité: prendre le plus long
                if (strlen($pdftotextClean) >= strlen($pdfparserClean)) {
                    Log::info('Using pdftotext (equal quality, longer)', ['path' => $path]);
                    return $pdftotextClean;
                } else {
                    Log::info('Using pdfparser (equal quality, longer)', ['path' => $path]);
                    return $pdfparserClean;
                }
            }
        }

        // Sinon, prendre ce qui est disponible
        if (!empty($pdftotextClean)) {
            Log::info('Using pdftotext (only option)', ['path' => $path]);
            return $pdftotextClean;
        }

        if (!empty($pdfparserClean)) {
            Log::info('Using pdfparser (only option)', ['path' => $path]);
            return $pdfparserClean;
        }

        // Si aucune méthode n'a fonctionné
        throw new \RuntimeException(
            "Impossible d'extraire le texte du PDF. " .
            "Le fichier est peut-être un scan (image), protégé, ou corrompu. " .
            "Essayez de convertir le PDF en texte avec un outil OCR."
        );
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
        $checkResult = Process::run('which pdftotext');
        if (!$checkResult->successful()) {
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

                    // Vérifier si l'extraction contient des caractères de remplacement (ligatures perdues)
                    $replacementCount = substr_count($output, "\xEF\xBF\xBD");

                    if ($replacementCount === 0) {
                        Log::info("pdftotext succeeded with {$modeName}", ['path' => $path]);
                        return $output;
                    }

                    Log::info("pdftotext {$modeName} has {$replacementCount} replacement chars, trying next mode", [
                        'path' => $path,
                    ]);

                    // Garder le résultat si c'est le dernier mode
                    if ($option === '') {
                        Log::warning("pdftotext: using default mode despite {$replacementCount} replacement chars", [
                            'path' => $path,
                        ]);
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

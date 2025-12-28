<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

/**
 * Detects product language from various sources.
 *
 * Detection priority:
 * 1. URL path patterns (/fr/, /en/, /de-de/, etc.)
 * 2. URL domain TLD (.fr, .de, .es, etc.)
 * 3. SKU patterns (-FR, -EN, _fr, _en, etc.)
 * 4. Content analysis (common words)
 */
class LanguageDetector
{
    /**
     * Supported locales with their patterns.
     */
    private const LOCALES = [
        'fr' => [
            'path_patterns' => ['/fr/', '/fr-fr/', '/fra/', '/french/'],
            'tlds' => ['.fr'],
            'sku_patterns' => ['-FR', '_FR', '-fr', '_fr', '/FR', '/fr'],
            'common_words' => ['le', 'la', 'les', 'de', 'du', 'des', 'un', 'une', 'et', 'est', 'pour', 'avec', 'dans', 'sur', 'par'],
        ],
        'en' => [
            'path_patterns' => ['/en/', '/en-gb/', '/en-us/', '/eng/', '/english/'],
            'tlds' => ['.com', '.co.uk', '.us'],
            'sku_patterns' => ['-EN', '_EN', '-en', '_en', '/EN', '/en', '-GB', '-US'],
            'common_words' => ['the', 'and', 'for', 'with', 'this', 'that', 'from', 'are', 'was', 'were', 'been', 'have', 'has'],
        ],
        'de' => [
            'path_patterns' => ['/de/', '/de-de/', '/deu/', '/german/', '/deutsch/'],
            'tlds' => ['.de', '.at', '.ch'],
            'sku_patterns' => ['-DE', '_DE', '-de', '_de', '/DE', '/de', '-AT', '-CH'],
            'common_words' => ['der', 'die', 'das', 'und', 'ist', 'mit', 'für', 'auf', 'dem', 'den', 'ein', 'eine', 'nicht'],
        ],
        'es' => [
            'path_patterns' => ['/es/', '/es-es/', '/spa/', '/spanish/', '/espanol/'],
            'tlds' => ['.es', '.mx', '.ar'],
            'sku_patterns' => ['-ES', '_ES', '-es', '_es', '/ES', '/es'],
            'common_words' => ['el', 'la', 'los', 'las', 'de', 'del', 'con', 'para', 'por', 'una', 'uno', 'que', 'es'],
        ],
        'it' => [
            'path_patterns' => ['/it/', '/it-it/', '/ita/', '/italian/', '/italiano/'],
            'tlds' => ['.it'],
            'sku_patterns' => ['-IT', '_IT', '-it', '_it', '/IT', '/it'],
            'common_words' => ['il', 'la', 'di', 'che', 'per', 'con', 'del', 'della', 'sono', 'una', 'uno', 'non'],
        ],
        'nl' => [
            'path_patterns' => ['/nl/', '/nl-nl/', '/nld/', '/dutch/', '/nederlands/'],
            'tlds' => ['.nl', '.be'],
            'sku_patterns' => ['-NL', '_NL', '-nl', '_nl', '/NL', '/nl', '-BE'],
            'common_words' => ['de', 'het', 'een', 'van', 'en', 'in', 'is', 'op', 'met', 'voor', 'niet', 'zijn'],
        ],
        'pt' => [
            'path_patterns' => ['/pt/', '/pt-pt/', '/pt-br/', '/por/', '/portuguese/'],
            'tlds' => ['.pt', '.br'],
            'sku_patterns' => ['-PT', '_PT', '-pt', '_pt', '/PT', '/pt', '-BR'],
            'common_words' => ['de', 'da', 'do', 'para', 'com', 'uma', 'um', 'que', 'os', 'as', 'no', 'na'],
        ],
        'pl' => [
            'path_patterns' => ['/pl/', '/pl-pl/', '/pol/', '/polish/', '/polski/'],
            'tlds' => ['.pl'],
            'sku_patterns' => ['-PL', '_PL', '-pl', '_pl', '/PL', '/pl'],
            'common_words' => ['i', 'w', 'na', 'do', 'z', 'jest', 'to', 'nie', 'co', 'jak', 'dla', 'od'],
        ],
    ];

    /**
     * Detect locale from URL.
     */
    public function detectFromUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $url = strtolower($url);

        // Check path patterns first (most reliable)
        foreach (self::LOCALES as $locale => $patterns) {
            foreach ($patterns['path_patterns'] as $pattern) {
                if (str_contains($url, $pattern)) {
                    return $locale;
                }
            }
        }

        // Check TLD (less reliable, some sites use .com for all languages)
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? '';

        foreach (self::LOCALES as $locale => $patterns) {
            foreach ($patterns['tlds'] as $tld) {
                if (str_ends_with($host, $tld)) {
                    // Skip .com as it's too generic
                    if ($tld !== '.com') {
                        return $locale;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Detect locale from SKU.
     */
    public function detectFromSku(?string $sku): ?string
    {
        if (empty($sku)) {
            return null;
        }

        foreach (self::LOCALES as $locale => $patterns) {
            foreach ($patterns['sku_patterns'] as $pattern) {
                if (str_contains($sku, $pattern)) {
                    return $locale;
                }
            }
        }

        return null;
    }

    /**
     * Detect locale from text content (description, name).
     * Uses simple word frequency analysis.
     */
    public function detectFromContent(?string $text): ?string
    {
        if (empty($text) || strlen($text) < 50) {
            return null;
        }

        $text = strtolower($text);
        $words = preg_split('/\s+/', $text);
        $wordCount = count($words);

        if ($wordCount < 10) {
            return null;
        }

        $scores = [];

        foreach (self::LOCALES as $locale => $patterns) {
            $matchCount = 0;
            foreach ($patterns['common_words'] as $commonWord) {
                // Count occurrences of common word (with word boundaries)
                $matchCount += preg_match_all('/\b' . preg_quote($commonWord, '/') . '\b/', $text);
            }
            // Normalize by number of common words to check
            $scores[$locale] = $matchCount / count($patterns['common_words']);
        }

        // Find best match
        arsort($scores);
        $bestLocale = array_key_first($scores);
        $bestScore = $scores[$bestLocale];

        // Only return if score is significant (at least 2 matches per pattern on average)
        if ($bestScore >= 0.5) {
            return $bestLocale;
        }

        return null;
    }

    /**
     * Detect locale using all available sources.
     * Priority: URL > SKU > Content
     */
    public function detect(?string $url = null, ?string $sku = null, ?string $content = null): ?string
    {
        // Try URL first (most reliable)
        $locale = $this->detectFromUrl($url);
        if ($locale) {
            return $locale;
        }

        // Try SKU
        $locale = $this->detectFromSku($sku);
        if ($locale) {
            return $locale;
        }

        // Fall back to content analysis
        return $this->detectFromContent($content);
    }

    /**
     * Get all supported locales.
     */
    public function getSupportedLocales(): array
    {
        return array_keys(self::LOCALES);
    }

    /**
     * Get human-readable locale name.
     */
    public function getLocaleName(string $locale): string
    {
        return match ($locale) {
            'fr' => 'Français',
            'en' => 'English',
            'de' => 'Deutsch',
            'es' => 'Español',
            'it' => 'Italiano',
            'nl' => 'Nederlands',
            'pt' => 'Português',
            'pl' => 'Polski',
            default => strtoupper($locale),
        };
    }
}

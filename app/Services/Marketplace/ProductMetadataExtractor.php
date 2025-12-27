<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\FabricantCatalog;
use App\Models\FabricantProduct;
use App\Models\WebCrawlUrl;
use App\Services\AI\LlmService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Extracts product metadata from crawled HTML pages.
 *
 * Uses two strategies:
 * 1. CSS Selector extraction - Fast, rule-based
 * 2. LLM extraction - More accurate, understands context
 *
 * The extracted data populates FabricantProduct records.
 */
class ProductMetadataExtractor
{
    private LlmService $llmService;

    public function __construct(LlmService $llmService)
    {
        $this->llmService = $llmService;
    }

    /**
     * Extract product metadata from a crawled URL.
     *
     * @param FabricantCatalog $catalog The catalog being populated
     * @param WebCrawlUrl $crawlUrl The crawled URL with HTML content
     * @return FabricantProduct|null Created/updated product or null if not a product page
     */
    public function extractFromCrawlUrl(FabricantCatalog $catalog, WebCrawlUrl $crawlUrl): ?FabricantProduct
    {
        // Check if this URL matches product patterns
        if (!$this->isProductUrl($crawlUrl->url, $catalog->extraction_config)) {
            return null;
        }

        // Load HTML content
        $html = $this->loadContent($crawlUrl);
        if (empty($html)) {
            Log::warning('ProductMetadataExtractor: Empty content for URL', [
                'url' => $crawlUrl->url,
            ]);
            return null;
        }

        $config = $catalog->extraction_config ?? FabricantCatalog::getDefaultExtractionConfig();

        // Try selector-based extraction first
        $productData = $this->extractWithSelectors($html, $config);

        // Use LLM extraction if enabled and selector extraction incomplete
        if (($config['use_llm_extraction'] ?? false) && !$this->isDataComplete($productData)) {
            $llmData = $this->extractWithLlm($html, $crawlUrl->url);
            $productData = $this->mergeExtractionData($productData, $llmData);
        }

        // Skip if no meaningful data extracted
        if (empty($productData['name'])) {
            Log::debug('ProductMetadataExtractor: No product name found', [
                'url' => $crawlUrl->url,
            ]);
            return null;
        }

        // Create or update product
        return $this->createOrUpdateProduct($catalog, $crawlUrl, $productData);
    }

    /**
     * Check if URL matches product patterns.
     */
    private function isProductUrl(string $url, ?array $config): bool
    {
        $patterns = $config['product_url_patterns'] ?? [
            '*/produit/*',
            '*/fiche-technique/*',
            '*/product/*',
            '*/article/*',
        ];

        foreach ($patterns as $pattern) {
            if ($this->urlMatchesPattern($url, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match URL against wildcard pattern.
     */
    private function urlMatchesPattern(string $url, string $pattern): bool
    {
        $regex = str_replace(
            ['*', '/'],
            ['.*', '\/'],
            $pattern
        );

        return (bool) preg_match("/^.*{$regex}.*$/i", $url);
    }

    /**
     * Load HTML content from storage.
     */
    private function loadContent(WebCrawlUrl $crawlUrl): ?string
    {
        if (empty($crawlUrl->storage_path)) {
            return null;
        }

        if (!Storage::exists($crawlUrl->storage_path)) {
            return null;
        }

        return Storage::get($crawlUrl->storage_path);
    }

    /**
     * Extract product data using CSS selectors.
     */
    private function extractWithSelectors(string $html, array $config): array
    {
        $crawler = new Crawler($html);
        $selectors = $config['selectors'] ?? [];

        $data = [];

        // Name
        $data['name'] = $this->extractText($crawler, $selectors['name'] ?? 'h1');

        // Price
        $priceText = $this->extractText($crawler, $selectors['price'] ?? '.price');
        $data['price_ht'] = $this->parsePrice($priceText);

        // SKU/Reference
        $data['sku'] = $this->extractText($crawler, $selectors['sku'] ?? '.sku, .reference');

        // Description
        $data['description'] = $this->extractText($crawler, $selectors['description'] ?? '.description');

        // Short description
        $data['short_description'] = $this->extractText(
            $crawler,
            $selectors['short_description'] ?? '.short-description, .excerpt'
        );

        // Images
        $data['images'] = $this->extractImages($crawler, $selectors['image'] ?? '.product-image img');
        if (!empty($data['images'])) {
            $data['main_image_url'] = $data['images'][0];
        }

        // Category
        $data['category'] = $this->extractText(
            $crawler,
            $selectors['category'] ?? '.breadcrumb a:last-child, .category'
        );

        // Brand
        $data['brand'] = $this->extractText($crawler, $selectors['brand'] ?? '.brand, [itemprop="brand"]');

        // Specifications
        $data['specifications'] = $this->extractSpecifications($crawler, $selectors['specs'] ?? '.specifications');

        // EAN from structured data
        $data['ean'] = $this->extractStructuredData($crawler, 'gtin13')
            ?? $this->extractStructuredData($crawler, 'gtin');

        // Availability
        $availability = $this->extractStructuredData($crawler, 'availability');
        if ($availability) {
            $data['availability'] = $this->parseAvailability($availability);
        }

        return array_filter($data, fn($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * Extract text from selector.
     */
    private function extractText(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector)->first();
            if ($node->count() > 0) {
                $text = trim($node->text());
                return $text !== '' ? $text : null;
            }
        } catch (\Exception $e) {
            // Selector not found or invalid
        }

        return null;
    }

    /**
     * Extract images from selector.
     */
    private function extractImages(Crawler $crawler, string $selector): array
    {
        $images = [];

        try {
            $crawler->filter($selector)->each(function (Crawler $node) use (&$images) {
                $src = $node->attr('src') ?? $node->attr('data-src');
                if ($src && !in_array($src, $images)) {
                    $images[] = $src;
                }
            });
        } catch (\Exception $e) {
            // Selector not found
        }

        return array_slice($images, 0, 10); // Max 10 images
    }

    /**
     * Extract specifications table.
     */
    private function extractSpecifications(Crawler $crawler, string $selector): array
    {
        $specs = [];

        try {
            $crawler->filter($selector . ' tr, ' . $selector . ' li')->each(function (Crawler $row) use (&$specs) {
                $cells = $row->filter('td, th');
                if ($cells->count() >= 2) {
                    $key = trim($cells->eq(0)->text());
                    $value = trim($cells->eq(1)->text());
                    if ($key && $value) {
                        $specs[$key] = $value;
                    }
                }
            });
        } catch (\Exception $e) {
            // Selector not found
        }

        return $specs;
    }

    /**
     * Extract structured data (JSON-LD, microdata).
     */
    private function extractStructuredData(Crawler $crawler, string $property): ?string
    {
        // Try JSON-LD
        try {
            $scripts = $crawler->filter('script[type="application/ld+json"]');
            foreach ($scripts as $script) {
                $json = json_decode($script->textContent, true);
                if (isset($json[$property])) {
                    return (string) $json[$property];
                }
                if (isset($json['@graph'])) {
                    foreach ($json['@graph'] as $item) {
                        if (isset($item[$property])) {
                            return (string) $item[$property];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Invalid JSON
        }

        // Try microdata
        try {
            $element = $crawler->filter("[itemprop=\"{$property}\"]")->first();
            if ($element->count() > 0) {
                return $element->attr('content') ?? $element->text();
            }
        } catch (\Exception $e) {
            // Not found
        }

        return null;
    }

    /**
     * Parse price from text.
     */
    private function parsePrice(?string $text): ?float
    {
        if (empty($text)) {
            return null;
        }

        // Remove currency symbols and spaces
        $cleaned = preg_replace('/[^\d,.\s]/', '', $text);
        $cleaned = trim($cleaned);

        // Handle French format (1 234,56)
        if (preg_match('/(\d[\d\s]*),(\d{2})$/', $cleaned, $matches)) {
            $intPart = preg_replace('/\s/', '', $matches[1]);
            return (float) ($intPart . '.' . $matches[2]);
        }

        // Handle English format (1,234.56)
        if (preg_match('/(\d[\d,]*?)\.(\d{2})$/', $cleaned, $matches)) {
            $intPart = str_replace(',', '', $matches[1]);
            return (float) ($intPart . '.' . $matches[2]);
        }

        return null;
    }

    /**
     * Parse availability from schema.org value.
     */
    private function parseAvailability(string $value): string
    {
        $value = strtolower($value);

        if (str_contains($value, 'instock')) {
            return FabricantProduct::AVAILABILITY_IN_STOCK;
        }
        if (str_contains($value, 'outofstock')) {
            return FabricantProduct::AVAILABILITY_OUT_OF_STOCK;
        }
        if (str_contains($value, 'preorder') || str_contains($value, 'backorder')) {
            return FabricantProduct::AVAILABILITY_ON_ORDER;
        }
        if (str_contains($value, 'discontinued')) {
            return FabricantProduct::AVAILABILITY_DISCONTINUED;
        }

        return FabricantProduct::AVAILABILITY_IN_STOCK;
    }

    /**
     * Extract product data using LLM.
     */
    private function extractWithLlm(string $html, string $url): array
    {
        // Clean HTML to reduce tokens
        $cleanedHtml = $this->cleanHtmlForLlm($html);

        $prompt = <<<PROMPT
Analyse cette page produit HTML et extrait les informations suivantes au format JSON:

{
  "name": "Nom du produit",
  "description": "Description complète",
  "short_description": "Description courte (max 200 caractères)",
  "sku": "Code SKU/Référence",
  "ean": "Code EAN/GTIN si présent",
  "brand": "Marque",
  "category": "Catégorie du produit",
  "price_ht": null (prix HT en nombre décimal, ou null si pas de prix HT),
  "price_ttc": null (prix TTC en nombre décimal, ou null),
  "price_unit": "unité de prix (ex: m², kg, L, pièce)",
  "availability": "in_stock|out_of_stock|on_order|discontinued",
  "specifications": { "clé": "valeur" }
}

Retourne UNIQUEMENT le JSON, sans commentaires.
Si une information n'est pas trouvée, utilise null.

URL: {$url}

HTML (nettoyé):
{$cleanedHtml}
PROMPT;

        try {
            $response = $this->llmService->complete($prompt, [
                'max_tokens' => 2000,
                'temperature' => 0.1,
            ]);

            // Extract JSON from response
            if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
                $data = json_decode($matches[0], true);
                if (is_array($data)) {
                    return $data;
                }
            }
        } catch (\Exception $e) {
            Log::warning('ProductMetadataExtractor: LLM extraction failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Clean HTML for LLM processing (reduce tokens).
     */
    private function cleanHtmlForLlm(string $html): string
    {
        // Remove scripts, styles, comments
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        // Remove navigation, footer, header (common non-product areas)
        $html = preg_replace('/<nav\b[^>]*>.*?<\/nav>/is', '', $html);
        $html = preg_replace('/<footer\b[^>]*>.*?<\/footer>/is', '', $html);
        $html = preg_replace('/<header\b[^>]*>.*?<\/header>/is', '', $html);

        // Remove excessive whitespace
        $html = preg_replace('/\s+/', ' ', $html);

        // Truncate if too long (limit to ~4000 chars for reasonable token count)
        if (strlen($html) > 8000) {
            $html = substr($html, 0, 8000) . '...';
        }

        return trim($html);
    }

    /**
     * Check if extracted data is complete enough.
     */
    private function isDataComplete(array $data): bool
    {
        // Require at least name and (price OR sku)
        return !empty($data['name'])
            && (!empty($data['price_ht']) || !empty($data['sku']));
    }

    /**
     * Merge selector extraction with LLM extraction.
     * Selector data takes priority, LLM fills gaps.
     */
    private function mergeExtractionData(array $selectorData, array $llmData): array
    {
        $merged = $selectorData;

        foreach ($llmData as $key => $value) {
            if (!isset($merged[$key]) || $merged[$key] === null || $merged[$key] === '' || $merged[$key] === []) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Create or update product from extracted data.
     */
    private function createOrUpdateProduct(
        FabricantCatalog $catalog,
        WebCrawlUrl $crawlUrl,
        array $data
    ): FabricantProduct {
        // Generate source hash for change detection
        $sourceHash = hash('sha256', json_encode($data));

        // Look for existing product by source URL or SKU
        $existing = FabricantProduct::where('catalog_id', $catalog->id)
            ->where(function ($q) use ($crawlUrl, $data) {
                $q->where('crawl_url_id', $crawlUrl->id);
                if (!empty($data['sku'])) {
                    $q->orWhere('sku', $data['sku']);
                }
            })
            ->first();

        $productData = [
            'catalog_id' => $catalog->id,
            'crawl_url_id' => $crawlUrl->id,
            'source_url' => $crawlUrl->url,
            'source_hash' => $sourceHash,
            'extraction_method' => isset($data['_llm_extracted'])
                ? FabricantProduct::EXTRACTION_LLM
                : FabricantProduct::EXTRACTION_SELECTOR,
            'extraction_confidence' => $this->calculateConfidence($data),
            'status' => FabricantProduct::STATUS_PENDING_REVIEW,
        ];

        // Map extracted fields
        $fieldMappings = [
            'name', 'description', 'short_description', 'sku', 'ean',
            'brand', 'category', 'price_ht', 'price_ttc', 'price_unit',
            'availability', 'images', 'main_image_url', 'specifications',
        ];

        foreach ($fieldMappings as $field) {
            if (isset($data[$field])) {
                $productData[$field] = $data[$field];
            }
        }

        if ($existing) {
            // Check if content changed
            if ($existing->source_hash !== $sourceHash) {
                $existing->update($productData);
                Log::info('ProductMetadataExtractor: Product updated', [
                    'product_id' => $existing->id,
                    'sku' => $existing->sku,
                ]);
            }
            return $existing;
        }

        // Create new product
        $product = FabricantProduct::create($productData);

        Log::info('ProductMetadataExtractor: Product created', [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
        ]);

        return $product;
    }

    /**
     * Calculate extraction confidence score.
     */
    private function calculateConfidence(array $data): float
    {
        $score = 0.0;
        $weights = [
            'name' => 0.2,
            'price_ht' => 0.15,
            'sku' => 0.15,
            'description' => 0.1,
            'images' => 0.1,
            'category' => 0.1,
            'specifications' => 0.1,
            'brand' => 0.05,
            'availability' => 0.05,
        ];

        foreach ($weights as $field => $weight) {
            if (!empty($data[$field])) {
                $score += $weight;
            }
        }

        return round($score, 2);
    }

    /**
     * Process all URLs from a catalog's web crawl.
     *
     * @return array Stats about extraction
     */
    public function processAllCrawlUrls(FabricantCatalog $catalog): array
    {
        if (!$catalog->webCrawl) {
            return ['error' => 'No web crawl associated with catalog'];
        }

        $stats = [
            'total' => 0,
            'products_found' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $catalog->markAsExtracting();

        $urls = $catalog->webCrawl->crawledUrls()
            ->where('http_status', 200)
            ->where('content_type', 'LIKE', 'text/html%')
            ->cursor();

        foreach ($urls as $crawlUrl) {
            $stats['total']++;

            try {
                $product = $this->extractFromCrawlUrl($catalog, $crawlUrl);

                if ($product) {
                    $stats['products_found']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('ProductMetadataExtractor: Extraction error', [
                    'url' => $crawlUrl->url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $catalog->markAsCompleted(
            $stats['products_found'],
            0, // Updated count would require tracking
            $stats['errors']
        );

        return $stats;
    }
}

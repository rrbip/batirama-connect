<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\FabricantCatalog;
use App\Models\FabricantProduct;
use App\Models\WebCrawlUrl;
use App\Services\AI\OllamaService;
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
    private OllamaService $ollamaService;

    public function __construct(OllamaService $ollamaService)
    {
        $this->ollamaService = $ollamaService;
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
        // Load HTML content
        $html = $this->loadContent($crawlUrl);
        if (empty($html)) {
            Log::warning('ProductMetadataExtractor: Empty content for URL', [
                'url' => $crawlUrl->url,
            ]);
            return null;
        }

        $config = $catalog->extraction_config ?? FabricantCatalog::getDefaultExtractionConfig();
        $productData = [];

        // 1. Try JSON-LD extraction first (most reliable)
        $jsonLdData = $this->extractFromJsonLd($html);
        if (!empty($jsonLdData)) {
            $productData = $jsonLdData;
            $productData['_extraction_method'] = 'jsonld';
            Log::debug('ProductMetadataExtractor: JSON-LD data found', [
                'url' => $crawlUrl->url,
                'name' => $productData['name'] ?? null,
            ]);
        }

        // 2. If no JSON-LD product, check if URL matches product patterns
        if (empty($productData['name'])) {
            if (!$this->isProductUrl($crawlUrl->url, $config)) {
                return null;
            }
        }

        // 3. Try selector-based extraction to fill gaps
        $selectorData = $this->extractWithSelectors($html, $config);
        $productData = $this->mergeExtractionData($productData, $selectorData);

        // 4. Use LLM extraction if enabled and still incomplete
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
     * Extract full product data from JSON-LD structured data.
     */
    private function extractFromJsonLd(string $html): array
    {
        $crawler = new Crawler($html);
        $data = [];

        try {
            $scripts = $crawler->filter('script[type="application/ld+json"]');

            foreach ($scripts as $script) {
                $json = json_decode($script->textContent, true);
                if (!$json) {
                    continue;
                }

                // Handle @graph format
                $items = [];
                if (isset($json['@graph'])) {
                    $items = $json['@graph'];
                } elseif (isset($json['@type'])) {
                    $items = [$json];
                }

                foreach ($items as $item) {
                    if ($this->isProductType($item)) {
                        $data = $this->parseJsonLdProduct($item);
                        if (!empty($data['name'])) {
                            return $data;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug('ProductMetadataExtractor: JSON-LD parsing error', [
                'error' => $e->getMessage(),
            ]);
        }

        return $data;
    }

    /**
     * Check if JSON-LD item is a Product type.
     */
    private function isProductType(array $item): bool
    {
        $type = $item['@type'] ?? '';

        if (is_array($type)) {
            return in_array('Product', $type) || in_array('IndividualProduct', $type);
        }

        return in_array($type, ['Product', 'IndividualProduct', 'ProductModel']);
    }

    /**
     * Parse JSON-LD Product into our data format.
     */
    private function parseJsonLdProduct(array $item): array
    {
        $data = [];

        // Name
        $data['name'] = $item['name'] ?? null;

        // Description
        $data['description'] = $item['description'] ?? null;

        // SKU
        $data['sku'] = $item['sku'] ?? $item['productID'] ?? $item['mpn'] ?? null;

        // EAN/GTIN
        $data['ean'] = $item['gtin13'] ?? $item['gtin14'] ?? $item['gtin'] ?? $item['gtin8'] ?? null;

        // Brand
        if (isset($item['brand'])) {
            $data['brand'] = is_array($item['brand'])
                ? ($item['brand']['name'] ?? null)
                : $item['brand'];
        }

        // Category
        $data['category'] = $item['category'] ?? null;
        if (is_array($data['category'])) {
            $data['category'] = end($data['category']); // Take the most specific
        }

        // Images
        if (isset($item['image'])) {
            $images = is_array($item['image']) ? $item['image'] : [$item['image']];
            // Handle ImageObject format
            $data['images'] = array_map(function ($img) {
                return is_array($img) ? ($img['url'] ?? $img['contentUrl'] ?? null) : $img;
            }, $images);
            $data['images'] = array_filter($data['images']);
            $data['main_image_url'] = $data['images'][0] ?? null;
        }

        // Price from Offer(s)
        $offer = null;
        if (isset($item['offers'])) {
            $offers = isset($item['offers']['@type']) ? [$item['offers']] : $item['offers'];
            $offer = $offers[0] ?? null;
        }

        if ($offer) {
            $price = $offer['price'] ?? $offer['lowPrice'] ?? null;
            if ($price !== null) {
                $data['price_ht'] = (float) $price;
            }

            $data['currency'] = $offer['priceCurrency'] ?? 'EUR';

            // Unit price
            if (isset($offer['priceSpecification']['referenceQuantity'])) {
                $refQty = $offer['priceSpecification']['referenceQuantity'];
                $data['price_unit'] = $refQty['unitCode'] ?? $refQty['unitText'] ?? null;
            }

            // Availability
            if (isset($offer['availability'])) {
                $data['availability'] = $this->parseAvailability($offer['availability']);
            }
        }

        // Manufacturer
        if (isset($item['manufacturer'])) {
            $data['manufacturer'] = is_array($item['manufacturer'])
                ? ($item['manufacturer']['name'] ?? null)
                : $item['manufacturer'];
        }

        // Additional properties / specifications
        if (isset($item['additionalProperty'])) {
            $specs = [];
            foreach ($item['additionalProperty'] as $prop) {
                if (isset($prop['name']) && isset($prop['value'])) {
                    $specs[$prop['name']] = $prop['value'];
                }
            }
            if (!empty($specs)) {
                $data['specifications'] = $specs;
            }
        }

        // Weight
        if (isset($item['weight'])) {
            $weight = is_array($item['weight']) ? $item['weight']['value'] ?? null : $item['weight'];
            if ($weight) {
                $data['weight_kg'] = (float) $weight;
            }
        }

        // Dimensions
        if (isset($item['width'])) {
            $data['width_cm'] = $this->parseDimension($item['width']);
        }
        if (isset($item['height'])) {
            $data['height_cm'] = $this->parseDimension($item['height']);
        }
        if (isset($item['depth'])) {
            $data['depth_cm'] = $this->parseDimension($item['depth']);
        }

        return array_filter($data, fn($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * Parse dimension value from JSON-LD.
     */
    private function parseDimension($dimension): ?float
    {
        if (is_array($dimension)) {
            return isset($dimension['value']) ? (float) $dimension['value'] : null;
        }
        return is_numeric($dimension) ? (float) $dimension : null;
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
            $llmResponse = $this->ollamaService->generate($prompt, [
                'max_tokens' => 2000,
                'temperature' => 0.1,
            ]);
            $response = $llmResponse->content;

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

        $urls = $catalog->webCrawl->urls()
            ->where('http_status', 200)
            ->where('content_type', 'LIKE', 'text/html%')
            ->wherePivot('status', 'fetched')
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

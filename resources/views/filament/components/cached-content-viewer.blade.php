<div x-data="{ viewMode: 'preview' }" class="space-y-4">
    {{-- Header avec URL et switch --}}
    <div class="flex items-center justify-between p-3 bg-gray-100 dark:bg-gray-800 rounded-lg">
        <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
            @if($isHtml)
                <x-heroicon-o-globe-alt class="w-4 h-4" />
            @elseif($isImage)
                <x-heroicon-o-photo class="w-4 h-4" />
            @elseif($isPdf)
                <x-heroicon-o-document class="w-4 h-4" />
            @else
                <x-heroicon-o-document-text class="w-4 h-4" />
            @endif
            <a href="{{ $url }}" target="_blank" class="hover:underline truncate max-w-lg">{{ $url }}</a>
        </div>
        <div class="flex items-center gap-4">
            <span class="text-xs text-gray-500">{{ $contentType }}</span>

            @if($isHtml || $isImage)
                {{-- Switch aperçu / code --}}
                <div class="flex items-center gap-1 bg-gray-200 dark:bg-gray-700 rounded-lg p-1">
                    <button
                        type="button"
                        @click="viewMode = 'preview'"
                        :class="viewMode === 'preview' ? 'bg-white dark:bg-gray-600 shadow-sm' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                        class="px-3 py-1 text-xs font-medium rounded transition-colors"
                    >
                        Aperçu
                    </button>
                    <button
                        type="button"
                        @click="viewMode = 'code'"
                        :class="viewMode === 'code' ? 'bg-white dark:bg-gray-600 shadow-sm' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                        class="px-3 py-1 text-xs font-medium rounded transition-colors"
                    >
                        Code
                    </button>
                </div>
            @endif
        </div>
    </div>

    {{-- Contenu --}}
    @if($isHtml)
        {{-- HTML: Aperçu avec ressources en cache ou code source --}}
        @php
            // Remplacer les URLs par les versions en cache
            $htmlWithCachedResources = $content;
            $cachedCount = 0;
            $missedUrls = [];

            if (!empty($cachedResources)) {
                foreach ($cachedResources as $originalUrl => $dataUri) {
                    // Échapper les caractères spéciaux pour le regex
                    $escapedUrl = preg_quote($originalUrl, '/');
                    $htmlWithCachedResources = preg_replace(
                        '/(["\'])' . $escapedUrl . '(["\'])/i',
                        '$1' . $dataUri . '$2',
                        $htmlWithCachedResources
                    );
                    $cachedCount++;
                }
            }

            // Extraire l'URL de base pour info
            $parsedUrl = parse_url($url);
            $baseUrl = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');
        @endphp

        <div x-show="viewMode === 'preview'" class="border rounded-lg overflow-hidden dark:border-gray-700">
            @if($cachedCount > 0)
                <div class="bg-green-50 dark:bg-green-900/20 px-3 py-1 text-xs text-green-600 dark:text-green-400 border-b dark:border-gray-700">
                    <x-heroicon-o-check-circle class="w-4 h-4 inline" />
                    {{ $cachedCount }} ressource(s) chargée(s) depuis le cache
                </div>
            @else
                <div class="bg-yellow-50 dark:bg-yellow-900/20 px-3 py-1 text-xs text-yellow-600 dark:text-yellow-400 border-b dark:border-gray-700">
                    <x-heroicon-o-exclamation-triangle class="w-4 h-4 inline" />
                    Aucune ressource en cache - les CSS/images peuvent ne pas s'afficher
                </div>
            @endif
            <iframe
                srcdoc="{{ $htmlWithCachedResources }}"
                class="w-full bg-white"
                style="height: 70vh; min-height: 500px;"
                sandbox="allow-same-origin"
            ></iframe>
        </div>
        <div x-show="viewMode === 'code'" x-cloak>
            <pre class="p-4 bg-gray-900 text-gray-100 rounded-lg overflow-auto text-xs" style="max-height: 70vh;"><code>{{ $content }}</code></pre>
        </div>

    @elseif($isImage)
        {{-- Image: Aperçu ou base64 --}}
        <div x-show="viewMode === 'preview'" class="flex justify-center p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
            <img
                src="data:{{ $contentType }};base64,{{ base64_encode($content) }}"
                alt="Image"
                class="max-w-full max-h-[70vh] object-contain rounded shadow-lg"
            />
        </div>
        <div x-show="viewMode === 'code'" x-cloak>
            <div class="p-4 bg-gray-100 dark:bg-gray-800 rounded-lg">
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">Base64 ({{ number_format(strlen($content)) }} octets)</p>
                <textarea
                    readonly
                    class="w-full h-32 p-2 font-mono text-xs bg-gray-900 text-gray-100 rounded"
                >data:{{ $contentType }};base64,{{ base64_encode($content) }}</textarea>
            </div>
        </div>

    @elseif($isPdf)
        {{-- PDF: Embed viewer --}}
        <div class="border rounded-lg overflow-hidden dark:border-gray-700">
            <embed
                src="data:application/pdf;base64,{{ base64_encode($content) }}"
                type="application/pdf"
                class="w-full"
                style="height: 70vh; min-height: 500px;"
            />
        </div>

    @else
        {{-- Autre: Texte brut --}}
        <div class="p-3 bg-gray-100 dark:bg-gray-800 rounded text-xs text-gray-500 mb-2">
            {{ number_format(strlen($content)) }} caractères
        </div>
        <pre class="p-4 bg-gray-900 text-gray-100 rounded-lg overflow-auto text-sm" style="max-height: 70vh;"><code>{{ $content }}</code></pre>
    @endif
</div>

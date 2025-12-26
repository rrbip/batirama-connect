<div class="space-y-4">
    <div class="flex items-center justify-between p-3 bg-gray-100 dark:bg-gray-800 rounded-lg">
        <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
            <x-heroicon-o-globe-alt class="w-4 h-4" />
            <a href="{{ $url }}" target="_blank" class="hover:underline">{{ $url }}</a>
        </div>
        <span class="text-xs text-gray-500">HTML</span>
    </div>

    <div class="border rounded-lg overflow-hidden dark:border-gray-700">
        <iframe
            srcdoc="{{ htmlspecialchars($content) }}"
            class="w-full bg-white"
            style="height: 70vh; min-height: 500px;"
            sandbox="allow-same-origin"
        ></iframe>
    </div>

    <details class="mt-4">
        <summary class="cursor-pointer text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
            Voir le code source HTML
        </summary>
        <pre class="mt-2 p-4 bg-gray-900 text-gray-100 rounded-lg overflow-x-auto text-xs max-h-96"><code>{{ $content }}</code></pre>
    </details>
</div>

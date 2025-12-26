<div class="space-y-4">
    <div class="flex items-center justify-between p-3 bg-gray-100 dark:bg-gray-800 rounded-lg">
        <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
            <x-heroicon-o-document-text class="w-4 h-4" />
            <span>Type: {{ $contentType }}</span>
        </div>
        <span class="text-xs text-gray-500">{{ number_format(strlen($content)) }} caractères</span>
    </div>

    <pre class="p-4 bg-gray-900 text-gray-100 rounded-lg overflow-x-auto text-sm max-h-[70vh]"><code>{{ $content }}</code></pre>
</div>

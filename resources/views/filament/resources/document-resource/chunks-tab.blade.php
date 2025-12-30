@php
    // Get the record from Filament's form context
    $document = null;

    // Try different methods to get the record
    if (isset($getRecord) && is_callable($getRecord)) {
        $document = $getRecord();
    } elseif (isset($record)) {
        // If $record is a closure, try to call it
        if (is_callable($record)) {
            try {
                $document = $record();
            } catch (\Throwable $e) {
                $document = null;
            }
        } else {
            $document = $record;
        }
    }

    if (!$document || !($document instanceof \App\Models\Document)) {
        $chunks = collect();
        $allChunks = collect();
    } else {
        $chunks = $document->chunks()->with('category')->orderBy('chunk_index')->get();
        $allChunks = $chunks;
    }
@endphp

@if($chunks->isEmpty())
    <div class="text-center py-8 text-gray-500">
        <x-heroicon-o-document-text class="w-12 h-12 mx-auto mb-3 opacity-50" />
        <p>Aucun chunk disponible pour ce document.</p>
        <p class="text-sm mt-1">Le document doit d'abord être extrait et découpé.</p>
    </div>
@else
    {{-- Stats --}}
    <div class="flex flex-wrap items-center gap-6 text-sm mb-6 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
        <span class="font-semibold text-gray-700 dark:text-gray-200">
            <span class="text-lg font-bold text-primary-600">{{ $allChunks->count() }}</span> chunks
        </span>
        <span class="text-gray-400">|</span>
        <span class="font-semibold text-gray-700 dark:text-gray-200">
            <span class="text-lg font-bold text-success-600">{{ $allChunks->where('is_indexed', true)->count() }}</span> indexés
        </span>
        <span class="text-gray-400">|</span>
        <span class="font-semibold text-gray-700 dark:text-gray-200">
            <span class="text-lg font-bold text-warning-600">{{ $allChunks->where('is_indexed', false)->count() }}</span> non indexés
        </span>
    </div>

    {{-- Chunks list --}}
    <div class="space-y-4">
        @foreach($chunks as $chunk)
            <div class="p-4 bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700">
                {{-- Chunk header --}}
                <div class="flex items-center justify-between mb-3 pb-3 border-b border-gray-100 dark:border-gray-700">
                    <div class="flex items-center gap-3 flex-wrap">
                        <span class="font-bold text-lg text-gray-900 dark:text-white">Chunk #{{ $chunk->chunk_index }}</span>

                        {{-- Category badge --}}
                        @if($chunk->category)
                            <span class="text-xs px-2 py-1 rounded-full font-medium"
                                  style="background-color: {{ $chunk->category->color ?? '#6B7280' }}20; color: {{ $chunk->category->color ?? '#6B7280' }};">
                                {{ $chunk->category->name }}
                            </span>
                        @else
                            <span class="text-xs px-2 py-1 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-500">
                                Sans catégorie
                            </span>
                        @endif

                        {{-- Token count --}}
                        <span class="text-xs text-gray-400">
                            {{ $chunk->token_count ?? 0 }} tokens
                        </span>

                        {{-- Indexed status --}}
                        @if($chunk->is_indexed)
                            <span class="text-xs px-2 py-1 rounded bg-success-100 dark:bg-success-900 text-success-700 dark:text-success-300">
                                ✓ Indexé
                            </span>
                        @else
                            <span class="text-xs px-2 py-1 rounded bg-warning-100 dark:bg-warning-900 text-warning-700 dark:text-warning-300">
                                ✗ Non indexé
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Chunk content sections --}}
                <div class="space-y-3">
                    {{-- Warning for non-useful chunks --}}
                    @if($chunk->useful === false)
                        <div class="flex items-center gap-2 p-2 bg-warning-50 dark:bg-warning-900/20 rounded border border-warning-200 dark:border-warning-800">
                            <x-heroicon-o-exclamation-triangle class="w-4 h-4 text-warning-500" />
                            <span class="text-xs text-warning-700 dark:text-warning-300">
                                Ce chunk n'a pas été jugé utile par le LLM
                            </span>
                        </div>
                    @endif

                    {{-- Summary --}}
                    @if($chunk->summary)
                        <div class="space-y-1">
                            <div class="flex items-center gap-2 text-xs font-medium text-gray-600 dark:text-gray-400">
                                <span>📝</span>
                                <span>Résumé :</span>
                            </div>
                            <p class="text-sm text-gray-600 dark:text-gray-400 pl-5">
                                {{ $chunk->summary }}
                            </p>
                        </div>
                    @endif

                    {{-- Q/R pairs --}}
                    @if($chunk->knowledge_units && count($chunk->knowledge_units) > 0)
                        <div class="space-y-2">
                            <div class="flex items-center gap-2 text-xs font-medium text-gray-600 dark:text-gray-400">
                                <span>❓</span>
                                <span>Questions/Réponses ({{ count($chunk->knowledge_units) }}) :</span>
                            </div>
                            <div class="space-y-2 pl-5">
                                @foreach($chunk->knowledge_units as $index => $unit)
                                    <div class="text-sm border-l-2 border-blue-300 dark:border-blue-600 pl-3">
                                        <p class="font-medium text-gray-800 dark:text-gray-200">
                                            Q{{ $index + 1 }}: {{ $unit['question'] ?? 'N/A' }}
                                        </p>
                                        <p class="text-gray-600 dark:text-gray-400 mt-1">
                                            R{{ $index + 1 }}: {{ $unit['answer'] ?? 'N/A' }}
                                        </p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Source content --}}
                    <div class="space-y-1">
                        <div class="flex items-center gap-2 text-xs font-medium text-gray-600 dark:text-gray-400">
                            <span>📄</span>
                            <span>Contenu source :</span>
                        </div>
                        <div class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap max-h-48 overflow-y-auto bg-gray-50 dark:bg-gray-800 rounded p-3 pl-5">{{ $chunk->original_content ?? $chunk->content }}</div>
                    </div>

                    {{-- Context breadcrumb --}}
                    @if($chunk->parent_context)
                        <div class="space-y-1">
                            <div class="flex items-center gap-2 text-xs font-medium text-gray-600 dark:text-gray-400">
                                <span>🔗</span>
                                <span>Contexte :</span>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 pl-5">
                                {{ $chunk->parent_context }}
                            </p>
                        </div>
                    @endif

                    {{-- Metadata --}}
                    @if($chunk->indexed_at)
                        <div class="mt-2 pt-2 border-t border-gray-100 dark:border-gray-700 text-xs text-gray-400">
                            Indexé le {{ $chunk->indexed_at->format('d/m/Y H:i') }}
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif

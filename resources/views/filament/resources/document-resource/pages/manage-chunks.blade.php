<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Header stats (compacted into one line per spec) --}}
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-6 text-sm">
                    <span class="font-semibold text-gray-700 dark:text-gray-200">
                        <span class="text-lg font-bold text-primary-600">{{ $this->allChunks->count() }}</span> chunks
                    </span>
                    <span class="text-gray-500 dark:text-gray-400">|</span>
                    <span class="font-semibold text-gray-700 dark:text-gray-200">
                        <span class="text-lg font-bold text-success-600">{{ $this->allChunks->sum('qdrant_points_count') ?: $this->allChunks->where('is_indexed', true)->count() }}</span> points Qdrant
                    </span>
                    <span class="text-gray-500 dark:text-gray-400">|</span>
                    <span class="font-semibold text-gray-700 dark:text-gray-200">
                        <span class="text-lg font-bold text-warning-600">{{ $this->allChunks->where('is_indexed', false)->count() }}</span> non indexés
                    </span>
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ count($selectedChunks) }} sélectionné(s)
                </div>
            </div>
        </x-filament::section>

        {{-- Filters --}}
        <x-filament::section>
            <div class="flex flex-wrap items-end gap-4">
                {{-- Category filter --}}
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Catégorie</label>
                    <select
                        wire:model.live="filterCategoryId"
                        class="text-sm px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                    >
                        <option value="">Toutes</option>
                        @foreach($this->categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Useful filter --}}
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Utile</label>
                    <select
                        wire:model.live="filterUseful"
                        class="text-sm px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                    >
                        <option value="">Tous</option>
                        <option value="yes">✅ Utiles</option>
                        <option value="no">❌ Non utiles</option>
                    </select>
                </div>

                {{-- Search --}}
                <div class="flex flex-col gap-1 flex-1 min-w-[200px]">
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Recherche</label>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="filterSearch"
                        placeholder="Rechercher dans le contenu..."
                        class="text-sm px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                    >
                </div>

                {{-- Reset filters --}}
                @if($filterCategoryId || $filterUseful || $filterSearch)
                    <x-filament::button
                        color="gray"
                        size="sm"
                        wire:click="resetFilters"
                    >
                        <x-heroicon-o-x-mark class="w-4 h-4 mr-1" />
                        Réinitialiser
                    </x-filament::button>
                @endif
            </div>
        </x-filament::section>

        {{-- Selection controls --}}
        <div class="flex items-center gap-4">
            <x-filament::button
                color="gray"
                size="sm"
                wire:click="selectAllChunks"
            >
                Tout sélectionner
            </x-filament::button>

            <x-filament::button
                color="gray"
                size="sm"
                wire:click="deselectAllChunks"
            >
                Tout désélectionner
            </x-filament::button>

            @if(count($selectedChunks) >= 2)
                <x-filament::button
                    color="warning"
                    size="sm"
                    wire:click="mergeSelectedChunks"
                    wire:confirm="Fusionner les {{ count($selectedChunks) }} chunks sélectionnés ? Cette action est irréversible."
                >
                    <x-heroicon-o-link class="w-4 h-4 mr-2" />
                    Fusionner ({{ count($selectedChunks) }})
                </x-filament::button>
            @endif
        </div>

        {{-- Chunks list --}}
        <div class="space-y-4">
            @forelse($this->chunks as $chunk)
                <x-filament::section
                    :class="in_array($chunk->id, $selectedChunks) ? 'ring-2 ring-primary-500' : ''"
                >
                    {{-- Chunk header with all info --}}
                    <div class="flex items-center justify-between mb-4 pb-3 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex items-center gap-3 flex-wrap">
                            <x-filament::input.checkbox
                                :checked="in_array($chunk->id, $selectedChunks)"
                                wire:click="toggleChunkSelection({{ $chunk->id }})"
                            />

                            <span class="font-bold text-lg text-gray-900 dark:text-white">#{{ $chunk->chunk_index }}</span>

                            {{-- Category badge --}}
                            @if($editingChunkId !== $chunk->id)
                                <select
                                    wire:change="updateChunkCategory({{ $chunk->id }}, $event.target.value ? parseInt($event.target.value) : null)"
                                    class="text-xs px-2 py-1 rounded-full border-0 font-medium focus:ring-1 focus:ring-primary-500"
                                    style="{{ $chunk->category ? 'background-color: ' . $chunk->category->color . '30; color: ' . $chunk->category->color . ';' : 'background-color: #e5e7eb; color: #6b7280;' }}"
                                >
                                    <option value="">Sans catégorie</option>
                                    @foreach($this->categories as $cat)
                                        <option value="{{ $cat->id }}" {{ $chunk->category_id === $cat->id ? 'selected' : '' }}>
                                            {{ $cat->name }}
                                        </option>
                                    @endforeach
                                </select>
                            @endif

                            {{-- Useful indicator --}}
                            <span class="text-sm">
                                @if($chunk->useful === true)
                                    <span class="text-success-600">useful: ✅</span>
                                @elseif($chunk->useful === false)
                                    <span class="text-danger-600">useful: ❌</span>
                                @else
                                    <span class="text-gray-400">useful: —</span>
                                @endif
                            </span>

                            {{-- Q/R count --}}
                            @if($chunk->knowledge_units && count($chunk->knowledge_units) > 0)
                                <span class="text-xs px-2 py-1 rounded bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300">
                                    {{ count($chunk->knowledge_units) }} Q/R
                                </span>
                            @endif

                            {{-- Qdrant points count --}}
                            @if($chunk->qdrant_points_count)
                                <span class="text-xs px-2 py-1 rounded bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300">
                                    {{ $chunk->qdrant_points_count }} points
                                </span>
                            @elseif($chunk->is_indexed)
                                <span class="text-xs px-2 py-1 rounded bg-success-100 dark:bg-success-900 text-success-700 dark:text-success-300">
                                    <x-heroicon-o-check class="w-3 h-3 inline" /> Indexé
                                </span>
                            @else
                                <span class="text-xs px-2 py-1 rounded bg-warning-100 dark:bg-warning-900 text-warning-700 dark:text-warning-300">
                                    <x-heroicon-o-clock class="w-3 h-3 inline" /> Non indexé
                                </span>
                            @endif

                            {{-- Token count --}}
                            <span class="text-xs text-gray-400">
                                {{ $chunk->token_count ?? 0 }} tokens
                            </span>
                        </div>

                        {{-- Action buttons --}}
                        <div class="flex items-center gap-2">
                            @if($editingChunkId !== $chunk->id)
                                <x-filament::button
                                    color="primary"
                                    size="xs"
                                    wire:click="startEditing({{ $chunk->id }})"
                                >
                                    <x-heroicon-o-pencil class="w-4 h-4 mr-1" />
                                    Éditer
                                </x-filament::button>

                                @if(!$chunk->is_indexed)
                                    <x-filament::button
                                        color="success"
                                        size="xs"
                                        wire:click="reindexChunk({{ $chunk->id }})"
                                    >
                                        <x-heroicon-o-arrow-path class="w-4 h-4 mr-1" />
                                        Indexer
                                    </x-filament::button>
                                @endif

                                <x-filament::button
                                    color="danger"
                                    size="xs"
                                    wire:click="deleteChunk({{ $chunk->id }})"
                                    wire:confirm="Supprimer ce chunk ? Cette action est irréversible."
                                >
                                    <x-heroicon-o-trash class="w-4 h-4 mr-1" />
                                    Supprimer
                                </x-filament::button>
                            @endif
                        </div>
                    </div>

                    {{-- Chunk content --}}
                    @if($editingChunkId === $chunk->id)
                        {{-- Edit mode --}}
                        <div class="space-y-4">
                            {{-- Category selector in edit mode --}}
                            <div class="flex items-center gap-3">
                                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Catégorie :</label>
                                <select
                                    wire:model="editingCategoryId"
                                    class="text-sm px-3 py-1.5 rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                >
                                    <option value="">Sans catégorie</option>
                                    @foreach($this->categories as $cat)
                                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <x-filament::input.wrapper>
                                <textarea
                                    wire:model="editingContent"
                                    class="block w-full border-none bg-transparent px-3 py-1.5 text-base text-gray-950 placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 dark:text-white dark:placeholder:text-gray-500 sm:text-sm sm:leading-6"
                                    rows="8"
                                    placeholder="Contenu du chunk..."
                                ></textarea>
                            </x-filament::input.wrapper>

                            <div class="flex items-center gap-2">
                                <x-filament::button
                                    color="primary"
                                    size="sm"
                                    wire:click="saveChunkEdit"
                                >
                                    <x-heroicon-o-check class="w-4 h-4 mr-1" />
                                    Enregistrer
                                </x-filament::button>

                                <x-filament::button
                                    color="gray"
                                    size="sm"
                                    wire:click="cancelEditing"
                                >
                                    Annuler
                                </x-filament::button>
                            </div>
                        </div>
                    @else
                        {{-- View mode - show all sections --}}
                        <div class="space-y-4">
                            {{-- Warning for non-useful chunks --}}
                            @if($chunk->useful === false)
                                <div class="flex items-center gap-2 p-3 bg-warning-50 dark:bg-warning-900/20 rounded-lg border border-warning-200 dark:border-warning-800">
                                    <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-warning-500" />
                                    <span class="text-sm text-warning-700 dark:text-warning-300">
                                        Ce chunk n'a pas été jugé utile par le LLM
                                    </span>
                                </div>
                            @endif

                            {{-- Summary --}}
                            @if($chunk->summary)
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                        <span>📝</span>
                                        <span>Résumé :</span>
                                    </div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 pl-6">
                                        {{ $chunk->summary }}
                                    </p>
                                </div>
                            @endif

                            {{-- Q/R pairs --}}
                            @if($chunk->knowledge_units && count($chunk->knowledge_units) > 0)
                                <div class="space-y-2">
                                    <div class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                        <span>❓</span>
                                        <span>Questions/Réponses générées :</span>
                                    </div>
                                    <div class="space-y-3 pl-6">
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
                                <div class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                    <span>📄</span>
                                    <span>Contenu source :</span>
                                </div>
                                <div class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap max-h-48 overflow-y-auto bg-gray-50 dark:bg-gray-800 rounded-lg p-3 pl-6">{{ $chunk->original_content ?? $chunk->content }}</div>
                            </div>

                            {{-- Context breadcrumb --}}
                            @if($chunk->parent_context)
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                        <span>🔗</span>
                                        <span>Contexte :</span>
                                    </div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 pl-6">
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
                    @endif
                </x-filament::section>
            @empty
                <x-filament::section>
                    <div class="text-center py-8 text-gray-500">
                        <x-heroicon-o-document-text class="w-12 h-12 mx-auto mb-3 opacity-50" />
                        @if($filterCategoryId || $filterUseful || $filterSearch)
                            <p>Aucun chunk ne correspond aux filtres.</p>
                            <p class="text-sm mt-1">
                                <button wire:click="resetFilters" class="text-primary-600 hover:underline">
                                    Réinitialiser les filtres
                                </button>
                            </p>
                        @else
                            <p>Aucun chunk disponible pour ce document.</p>
                            <p class="text-sm mt-1">Le document doit d'abord être extrait et découpé.</p>
                        @endif
                    </div>
                </x-filament::section>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>

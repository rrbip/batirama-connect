<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Header stats --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <x-filament::section>
                <div class="text-center">
                    <div class="text-2xl font-bold text-primary-600">{{ $this->chunks->count() }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Chunks total</div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-center">
                    <div class="text-2xl font-bold text-success-600">{{ $this->chunks->where('is_indexed', true)->count() }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Indexés</div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-center">
                    <div class="text-2xl font-bold text-warning-600">{{ $this->chunks->where('is_indexed', false)->count() }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Non indexés</div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-center">
                    <div class="text-2xl font-bold text-info-600">{{ count($selectedChunks) }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Sélectionnés</div>
                </div>
            </x-filament::section>
        </div>

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
                    {{-- Chunk header --}}
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-3">
                            <x-filament::input.checkbox
                                :checked="in_array($chunk->id, $selectedChunks)"
                                wire:click="toggleChunkSelection({{ $chunk->id }})"
                            />

                            <span class="font-semibold text-lg">Chunk #{{ $chunk->chunk_index }}</span>

                            <span class="text-xs px-2 py-1 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                {{ $chunk->token_count ?? 0 }} tokens
                            </span>

                            @if($chunk->is_indexed)
                                <span class="text-xs px-2 py-1 rounded bg-success-100 dark:bg-success-900 text-success-700 dark:text-success-300">
                                    <x-heroicon-o-check class="w-3 h-3 inline" /> Indexé
                                </span>
                            @else
                                <span class="text-xs px-2 py-1 rounded bg-warning-100 dark:bg-warning-900 text-warning-700 dark:text-warning-300">
                                    <x-heroicon-o-clock class="w-3 h-3 inline" /> Non indexé
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            @if($editingChunkId !== $chunk->id)
                                <x-filament::icon-button
                                    icon="heroicon-o-pencil"
                                    color="primary"
                                    size="sm"
                                    wire:click="startEditing({{ $chunk->id }})"
                                    tooltip="Modifier"
                                />

                                @if(!$chunk->is_indexed)
                                    <x-filament::icon-button
                                        icon="heroicon-o-arrow-path"
                                        color="success"
                                        size="sm"
                                        wire:click="reindexChunk({{ $chunk->id }})"
                                        tooltip="Indexer"
                                    />
                                @endif

                                <x-filament::icon-button
                                    icon="heroicon-o-trash"
                                    color="danger"
                                    size="sm"
                                    wire:click="deleteChunk({{ $chunk->id }})"
                                    wire:confirm="Supprimer ce chunk ? Cette action est irréversible."
                                    tooltip="Supprimer"
                                />
                            @endif
                        </div>
                    </div>

                    {{-- Chunk content --}}
                    @if($editingChunkId === $chunk->id)
                        {{-- Edit mode --}}
                        <div class="space-y-3">
                            <x-filament::input.wrapper>
                                <textarea
                                    wire:model="editingContent"
                                    class="block w-full border-none bg-transparent px-3 py-1.5 text-base text-gray-950 placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.400)] dark:text-white dark:placeholder:text-gray-500 dark:disabled:text-gray-400 dark:disabled:[-webkit-text-fill-color:theme(colors.gray.400)] dark:disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.500)] sm:text-sm sm:leading-6"
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
                        {{-- View mode --}}
                        <div class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap max-h-48 overflow-y-auto bg-gray-50 dark:bg-gray-800 rounded-lg p-3">{{ $chunk->content }}</div>
                    @endif

                    {{-- Chunk metadata --}}
                    @if($chunk->indexed_at)
                        <div class="mt-2 text-xs text-gray-400">
                            Indexé le {{ $chunk->indexed_at->format('d/m/Y H:i') }}
                        </div>
                    @endif
                </x-filament::section>
            @empty
                <x-filament::section>
                    <div class="text-center py-8 text-gray-500">
                        <x-heroicon-o-document-text class="w-12 h-12 mx-auto mb-3 opacity-50" />
                        <p>Aucun chunk disponible pour ce document.</p>
                        <p class="text-sm mt-1">Le document doit d'abord être extrait et découpé.</p>
                    </div>
                </x-filament::section>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>

@php
    // Récupérer les agents liés depuis le record du composant parent
    $agentConfigs = $this->record->agentConfigs()->with('agent')->get();
@endphp

<div class="space-y-3">
    @forelse($agentConfigs as $config)
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <div class="flex items-center justify-between gap-4">
                {{-- Infos agent --}}
                <div class="flex items-center gap-6 flex-1">
                    <div class="min-w-[120px]">
                        <span class="text-xs text-gray-500 dark:text-gray-400">Agent</span>
                        <p class="font-bold text-gray-900 dark:text-white">{{ $config->agent->name }}</p>
                    </div>

                    <div class="min-w-[80px]">
                        <span class="text-xs text-gray-500 dark:text-gray-400">Statut</span>
                        <p>
                            @php
                                $statusColor = match($config->index_status) {
                                    'pending' => 'gray',
                                    'indexing' => 'warning',
                                    'indexed' => 'success',
                                    'error' => 'danger',
                                    default => 'gray',
                                };
                                $statusLabel = match($config->index_status) {
                                    'pending' => 'En attente',
                                    'indexing' => 'En cours',
                                    'indexed' => 'Indexé',
                                    'error' => 'Erreur',
                                    default => $config->index_status,
                                };
                            @endphp
                            <x-filament::badge :color="$statusColor" size="sm">
                                {{ $statusLabel }}
                            </x-filament::badge>
                        </p>
                    </div>

                    {{-- Stats sur une seule ligne --}}
                    <div class="flex items-center gap-4 text-sm">
                        <div class="text-center">
                            <span class="text-success-600 dark:text-success-400 font-semibold">{{ $config->pages_indexed }}</span>
                            <span class="text-gray-500 dark:text-gray-400 text-xs ml-1">indexées</span>
                        </div>
                        <div class="text-center">
                            <span class="text-warning-600 dark:text-warning-400 font-semibold">{{ $config->pages_skipped }}</span>
                            <span class="text-gray-500 dark:text-gray-400 text-xs ml-1">ignorées</span>
                        </div>
                        <div class="text-center">
                            <span class="text-danger-600 dark:text-danger-400 font-semibold">{{ $config->pages_error }}</span>
                            <span class="text-gray-500 dark:text-gray-400 text-xs ml-1">erreurs</span>
                        </div>
                        <div class="text-center">
                            <span class="text-gray-700 dark:text-gray-300 font-medium">
                                @php
                                    $chunkLabel = match($config->effective_chunk_strategy) {
                                        'simple' => 'Simple',
                                        'html_semantic' => 'HTML',
                                        'llm_assisted' => 'LLM',
                                        'sentence' => 'Phrase',
                                        default => $config->effective_chunk_strategy,
                                    };
                                @endphp
                                {{ $chunkLabel }}
                            </span>
                            <span class="text-gray-500 dark:text-gray-400 text-xs ml-1">chunking</span>
                        </div>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="flex items-center gap-2">
                    @if($config->index_status === 'pending' || $config->index_status === 'error')
                        <x-filament::button
                            size="sm"
                            color="success"
                            icon="heroicon-o-play"
                            wire:click="startAgentIndexation({{ $config->id }})"
                        >
                            Indexer
                        </x-filament::button>
                    @elseif($config->index_status === 'indexed')
                        <x-filament::button
                            size="sm"
                            color="warning"
                            icon="heroicon-o-arrow-path"
                            wire:click="reindexAgent({{ $config->id }})"
                            wire:confirm="Réindexer cet agent ? Les documents existants seront mis à jour."
                        >
                            Réindexer
                        </x-filament::button>
                    @endif
                    <x-filament::button
                        size="sm"
                        color="gray"
                        icon="heroicon-o-pencil"
                        wire:click="editAgentConfig({{ $config->id }})"
                    >
                        Modifier
                    </x-filament::button>
                    <x-filament::button
                        size="sm"
                        color="danger"
                        icon="heroicon-o-trash"
                        wire:click="deleteAgentConfig({{ $config->id }})"
                        wire:confirm="Êtes-vous sûr de vouloir supprimer cet agent du crawl ? Les documents indexés seront également supprimés."
                    >
                        Supprimer
                    </x-filament::button>
                </div>
            </div>

            @if($config->url_patterns && count($config->url_patterns) > 0)
                <div class="mt-2 pt-2 border-t border-gray-100 dark:border-gray-700">
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        Filtres ({{ $config->url_filter_mode === 'exclude' ? 'exclusion' : 'inclusion' }}):
                    </span>
                    <span class="text-xs text-gray-600 dark:text-gray-300">
                        {{ implode(', ', $config->url_patterns) }}
                    </span>
                </div>
            @endif
        </div>
    @empty
        <div class="text-center py-6 text-gray-500 dark:text-gray-400">
            <x-heroicon-o-user-group class="w-6 h-6 mx-auto mb-2 opacity-50" />
            <p>Aucun agent lié à ce crawl.</p>
            <p class="text-sm">Utilisez le bouton "Ajouter un agent" pour commencer.</p>
        </div>
    @endforelse
</div>

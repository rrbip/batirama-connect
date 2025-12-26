<div class="space-y-4">
    @forelse($agentConfigs as $config)
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <div class="flex items-center justify-between">
                <div class="grid grid-cols-6 gap-4 flex-1">
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Agent</span>
                        <p class="font-bold text-gray-900 dark:text-white">{{ $config->agent->name }}</p>
                    </div>
                    <div>
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
                            <x-filament::badge :color="$statusColor">
                                {{ $statusLabel }}
                            </x-filament::badge>
                        </p>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Indexées</span>
                        <p class="text-success-600 dark:text-success-400">{{ $config->pages_indexed }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Ignorées</span>
                        <p class="text-warning-600 dark:text-warning-400">{{ $config->pages_skipped }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Erreurs</span>
                        <p class="text-danger-600 dark:text-danger-400">{{ $config->pages_error }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Chunking</span>
                        <p class="text-gray-900 dark:text-white">
                            @php
                                $chunkLabel = match($config->effective_chunk_strategy) {
                                    'simple' => 'Simple',
                                    'html_semantic' => 'HTML',
                                    'llm_assisted' => 'LLM',
                                    default => $config->effective_chunk_strategy,
                                };
                            @endphp
                            {{ $chunkLabel }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2 ml-4">
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
            <x-heroicon-o-user-group class="w-12 h-12 mx-auto mb-2 opacity-50" />
            <p>Aucun agent lié à ce crawl.</p>
            <p class="text-sm">Utilisez le bouton "Ajouter un agent" pour commencer.</p>
        </div>
    @endforelse
</div>

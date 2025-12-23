<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Services Status --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-server class="w-5 h-5" />
                    Services IA
                </div>
            </x-slot>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach($services as $key => $service)
                    <div class="p-4 rounded-lg border {{
                        $service['status'] === 'online' ? 'bg-success-50 border-success-200 dark:bg-success-900/20 dark:border-success-700' :
                        ($service['status'] === 'warning' ? 'bg-warning-50 border-warning-200 dark:bg-warning-900/20 dark:border-warning-700' :
                        ($service['status'] === 'unknown' ? 'bg-gray-50 border-gray-200 dark:bg-gray-900/20 dark:border-gray-700' :
                        'bg-danger-50 border-danger-200 dark:bg-danger-900/20 dark:border-danger-700'))
                    }}">
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-medium text-gray-900 dark:text-gray-100">
                                {{ $service['name'] }}
                            </span>
                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{
                                $service['status'] === 'online' ? 'bg-success-100 text-success-700 dark:bg-success-800 dark:text-success-300' :
                                ($service['status'] === 'warning' ? 'bg-warning-100 text-warning-700 dark:bg-warning-800 dark:text-warning-300' :
                                ($service['status'] === 'unknown' ? 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' :
                                'bg-danger-100 text-danger-700 dark:bg-danger-800 dark:text-danger-300'))
                            }}">
                                @if($service['status'] === 'online')
                                    <x-heroicon-s-check-circle class="w-3 h-3 mr-1" />
                                @elseif($service['status'] === 'warning')
                                    <x-heroicon-s-exclamation-triangle class="w-3 h-3 mr-1" />
                                @elseif($service['status'] === 'unknown')
                                    <x-heroicon-s-question-mark-circle class="w-3 h-3 mr-1" />
                                @else
                                    <x-heroicon-s-x-circle class="w-3 h-3 mr-1" />
                                @endif
                                {{ ucfirst($service['status']) }}
                            </span>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                            {{ $service['details'] }}
                        </p>

                        {{-- Bouton de redémarrage si service offline et restartable --}}
                        @if(($service['status'] === 'offline' || $service['status'] === 'warning') && ($service['restartable'] ?? false))
                            <button
                                wire:click="restartService('{{ $key }}')"
                                wire:loading.attr="disabled"
                                wire:target="restartService('{{ $key }}')"
                                class="w-full inline-flex items-center justify-center gap-1 px-3 py-2 text-xs font-medium text-white bg-primary-600 rounded-lg hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50 transition"
                            >
                                <x-heroicon-o-arrow-path class="w-4 h-4" wire:loading.class="animate-spin" wire:target="restartService('{{ $key }}')" />
                                <span wire:loading.remove wire:target="restartService('{{ $key }}')">Redémarrer</span>
                                <span wire:loading wire:target="restartService('{{ $key }}')">Redémarrage...</span>
                            </button>
                        @elseif($service['status'] === 'offline' && isset($service['depends_on']))
                            <p class="text-xs text-gray-500 dark:text-gray-500 italic">
                                Dépend de: {{ $services[$service['depends_on']]['name'] ?? $service['depends_on'] }}
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- Queue & Documents Stats --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-queue-list class="w-5 h-5" />
                        File d'attente
                    </div>
                </x-slot>

                <div class="grid grid-cols-3 gap-4">
                    <div class="text-center p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                            {{ $queueStats['pending'] ?? 0 }}
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">En attente</div>
                    </div>
                    <div class="text-center p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div class="text-2xl font-bold text-danger-600 dark:text-danger-400">
                            {{ $queueStats['failed'] ?? 0 }}
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">Echoués</div>
                    </div>
                    <div class="text-center p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ $queueStats['connection'] ?? 'sync' }}
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">Driver</div>
                    </div>
                </div>

                @if(($queueStats['connection'] ?? 'sync') !== 'sync')
                    <div class="mt-4 p-3 bg-warning-50 dark:bg-warning-900/20 border border-warning-200 dark:border-warning-700 rounded-lg">
                        <div class="flex items-start gap-2">
                            <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-warning-500 flex-shrink-0 mt-0.5" />
                            <div class="text-sm text-warning-700 dark:text-warning-300">
                                <strong>Driver database actif.</strong> Assurez-vous qu'un worker est en cours d'exécution:
                                <code class="block mt-1 p-2 bg-gray-800 text-gray-100 rounded text-xs">
                                    php artisan queue:work --daemon
                                </code>
                            </div>
                        </div>
                    </div>
                @endif
            </x-filament::section>

            {{-- Document Stats --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-document-text class="w-5 h-5" />
                        Documents RAG
                    </div>
                </x-slot>

                @if(isset($documentStats['error']))
                    <div class="text-danger-500">{{ $documentStats['error'] }}</div>
                @else
                    <div class="grid grid-cols-3 gap-4 mb-4">
                        <div class="text-center p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                {{ $documentStats['total'] ?? 0 }}
                            </div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">Total</div>
                        </div>
                        <div class="text-center p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <div class="text-2xl font-bold text-success-600 dark:text-success-400">
                                {{ $documentStats['indexed'] ?? 0 }}
                            </div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">Indexés</div>
                        </div>
                        <div class="text-center p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <div class="text-2xl font-bold text-danger-600 dark:text-danger-400">
                                {{ $documentStats['failed'] ?? 0 }}
                            </div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">Echoués</div>
                        </div>
                    </div>

                    {{-- Progress bar --}}
                    @php
                        $total = $documentStats['total'] ?? 0;
                        $pending = $documentStats['pending'] ?? 0;
                        $processing = $documentStats['processing'] ?? 0;
                        $completed = $documentStats['completed'] ?? 0;
                        $failed = $documentStats['failed'] ?? 0;
                    @endphp

                    @if($total > 0)
                        <div class="space-y-2">
                            <div class="flex h-4 rounded-full overflow-hidden bg-gray-200 dark:bg-gray-700">
                                @if($completed > 0)
                                    <div class="bg-success-500" style="width: {{ ($completed / $total) * 100 }}%"></div>
                                @endif
                                @if($processing > 0)
                                    <div class="bg-warning-500" style="width: {{ ($processing / $total) * 100 }}%"></div>
                                @endif
                                @if($pending > 0)
                                    <div class="bg-gray-400" style="width: {{ ($pending / $total) * 100 }}%"></div>
                                @endif
                                @if($failed > 0)
                                    <div class="bg-danger-500" style="width: {{ ($failed / $total) * 100 }}%"></div>
                                @endif
                            </div>
                            <div class="flex justify-between text-xs text-gray-500 dark:text-gray-400">
                                <span class="flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-success-500"></span>
                                    Terminés ({{ $completed }})
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-warning-500"></span>
                                    En cours ({{ $processing }})
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-gray-400"></span>
                                    En attente ({{ $pending }})
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-danger-500"></span>
                                    Echoués ({{ $failed }})
                                </span>
                            </div>
                        </div>
                    @endif
                @endif
            </x-filament::section>
        </div>

        {{-- Failed Documents Section --}}
        @if(count($failedDocuments) > 0)
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2 text-danger-600 dark:text-danger-400">
                        <x-heroicon-o-document-text class="w-5 h-5" />
                        Documents en échec ({{ count($failedDocuments) }})
                    </div>
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Document</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Erreur</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Date</th>
                                <th class="px-4 py-2 text-center font-medium text-gray-700 dark:text-gray-300">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($failedDocuments as $doc)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">
                                        {{ Str::limit($doc['name'], 40) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="max-w-md">
                                            <p class="text-danger-600 dark:text-danger-400 text-xs font-mono break-words">
                                                {{ Str::limit($doc['error'], 150) }}
                                            </p>
                                            @if(strlen($doc['error']) > 150)
                                                <button
                                                    x-data="{ open: false }"
                                                    @click="open = !open"
                                                    class="text-xs text-primary-600 hover:underline mt-1"
                                                >
                                                    <span x-show="!open">Voir plus...</span>
                                                    <span x-show="open" x-cloak>Réduire</span>
                                                </button>
                                                <p x-show="open" x-cloak class="text-danger-600 dark:text-danger-400 text-xs font-mono mt-1 break-words">
                                                    {{ $doc['error'] }}
                                                </p>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400 text-xs whitespace-nowrap">
                                        {{ $doc['updated_at'] }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <button
                                            wire:click="retryDocument({{ $doc['id'] }})"
                                            wire:loading.attr="disabled"
                                            wire:target="retryDocument({{ $doc['id'] }})"
                                            class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-white bg-warning-600 rounded hover:bg-warning-700 disabled:opacity-50 transition"
                                        >
                                            <x-heroicon-o-arrow-path class="w-3 h-3" wire:loading.class="animate-spin" wire:target="retryDocument({{ $doc['id'] }})" />
                                            Relancer
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        {{-- Failed Jobs Section --}}
        @if(count($failedJobs) > 0)
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2 text-danger-600 dark:text-danger-400">
                        <x-heroicon-o-queue-list class="w-5 h-5" />
                        Jobs en échec ({{ count($failedJobs) }})
                    </div>
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Job</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Queue</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Erreur</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Date</th>
                                <th class="px-4 py-2 text-center font-medium text-gray-700 dark:text-gray-300">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($failedJobs as $job)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">
                                        {{ $job['name'] }}
                                    </td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400">
                                        <span class="px-2 py-1 text-xs bg-gray-100 dark:bg-gray-700 rounded">
                                            {{ $job['queue'] }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div x-data="{ showFull: false }" class="max-w-md">
                                            <p class="text-danger-600 dark:text-danger-400 text-xs font-mono break-words">
                                                {{ $job['error'] }}
                                            </p>
                                            @if($job['full_exception'])
                                                <button
                                                    @click="showFull = !showFull"
                                                    class="text-xs text-primary-600 hover:underline mt-1"
                                                >
                                                    <span x-show="!showFull">Voir stacktrace...</span>
                                                    <span x-show="showFull" x-cloak>Masquer stacktrace</span>
                                                </button>
                                                <pre x-show="showFull" x-cloak class="mt-2 p-2 bg-gray-800 text-green-400 text-xs rounded overflow-x-auto max-h-48 overflow-y-auto" style="color: #4ade80 !important;">{{ $job['full_exception'] }}</pre>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400 text-xs whitespace-nowrap">
                                        {{ \Carbon\Carbon::parse($job['failed_at'])->format('d/m/Y H:i') }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <div class="flex items-center justify-center gap-1">
                                            <button
                                                wire:click="retryFailedJob('{{ $job['uuid'] }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="retryFailedJob('{{ $job['uuid'] }}')"
                                                class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-white bg-warning-600 rounded hover:bg-warning-700 disabled:opacity-50 transition"
                                                title="Relancer"
                                            >
                                                <x-heroicon-o-arrow-path class="w-3 h-3" wire:loading.class="animate-spin" wire:target="retryFailedJob('{{ $job['uuid'] }}')" />
                                            </button>
                                            <button
                                                wire:click="deleteFailedJob('{{ $job['uuid'] }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="deleteFailedJob('{{ $job['uuid'] }}')"
                                                wire:confirm="Supprimer ce job échoué ?"
                                                class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-white bg-danger-600 rounded hover:bg-danger-700 disabled:opacity-50 transition"
                                                title="Supprimer"
                                            >
                                                <x-heroicon-o-trash class="w-3 h-3" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        {{-- Collections Qdrant Details --}}
        @if(isset($services['qdrant']) && $services['qdrant']['status'] === 'online' && !empty($services['qdrant']['collections']))
            <x-filament::section collapsible collapsed>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-circle-stack class="w-5 h-5" />
                        Collections Qdrant
                    </div>
                </x-slot>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    @foreach($services['qdrant']['collections'] as $collection)
                        <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg text-center">
                            <x-heroicon-o-folder class="w-6 h-6 mx-auto mb-1 text-primary-500" />
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $collection }}</span>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- Models Ollama Details --}}
        @if(isset($services['ollama']) && $services['ollama']['status'] === 'online' && !empty($services['ollama']['models']))
            <x-filament::section collapsible collapsed>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-cube class="w-5 h-5" />
                        Modèles Ollama
                    </div>
                </x-slot>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    @foreach($services['ollama']['models'] as $model)
                        <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg text-center">
                            <x-heroicon-o-cube class="w-6 h-6 mx-auto mb-1 text-primary-500" />
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $model }}</span>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>

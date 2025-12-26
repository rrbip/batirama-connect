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

                {{-- Stats par queue --}}
                @if(!empty($queueStats['by_queue']))
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach($queueStats['by_queue'] as $queue => $count)
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium rounded-full {{
                                $queue === 'llm-chunking' ? 'bg-purple-100 text-purple-700 dark:bg-purple-800 dark:text-purple-300' :
                                ($queue === 'default' ? 'bg-primary-100 text-primary-700 dark:bg-primary-800 dark:text-primary-300' :
                                'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300')
                            }}">
                                {{ $queue }}: {{ $count }}
                            </span>
                        @endforeach
                    </div>
                @endif

                @if(($queueStats['connection'] ?? 'sync') !== 'sync')
                    <div class="mt-4 p-3 bg-warning-50 dark:bg-warning-900/20 border border-warning-200 dark:border-warning-700 rounded-lg">
                        <div class="flex items-start gap-2">
                            <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-warning-500 flex-shrink-0 mt-0.5" />
                            <div class="text-sm text-warning-700 dark:text-warning-300">
                                <strong>Driver database actif.</strong> Assurez-vous que les workers sont en cours d'exécution:
                                <code class="block mt-1 p-2 bg-gray-800 text-gray-100 rounded text-xs">
php artisan queue:work --queue=default
php artisan queue:work --queue=llm-chunking
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

        {{-- Pending Jobs List --}}
        @if(count($pendingJobs) > 0)
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-clock class="w-5 h-5" />
                        Jobs en attente ({{ count($pendingJobs) }})
                    </div>
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Job</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Queue</th>
                                <th class="px-4 py-2 text-center font-medium text-gray-700 dark:text-gray-300">Statut</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Document</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Attente</th>
                                <th class="px-4 py-2 text-center font-medium text-gray-700 dark:text-gray-300">Essais</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($pendingJobs as $job)
                                <tr class="{{ $job['status'] === 'stuck' ? 'bg-danger-50 dark:bg-danger-900/10' : ($job['status'] === 'waiting' && $job['wait_time'] > 300 ? 'bg-warning-50 dark:bg-warning-900/10' : '') }}">
                                    <td class="px-4 py-3">
                                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $job['name'] }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full {{
                                            $job['queue'] === 'llm-chunking' ? 'bg-purple-100 text-purple-700 dark:bg-purple-800 dark:text-purple-300' :
                                            ($job['queue'] === 'default' ? 'bg-primary-100 text-primary-700 dark:bg-primary-800 dark:text-primary-300' :
                                            'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300')
                                        }}">
                                            {{ $job['queue'] }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-full {{
                                            $job['status'] === 'processing' ? 'bg-primary-100 text-primary-700 dark:bg-primary-800 dark:text-primary-300' :
                                            ($job['status'] === 'stuck' ? 'bg-danger-100 text-danger-700 dark:bg-danger-800 dark:text-danger-300' :
                                            'bg-warning-100 text-warning-700 dark:bg-warning-800 dark:text-warning-300')
                                        }}">
                                            @if($job['status'] === 'processing')
                                                <x-heroicon-s-arrow-path class="w-3 h-3 animate-spin" />
                                            @elseif($job['status'] === 'stuck')
                                                <x-heroicon-s-exclamation-triangle class="w-3 h-3" />
                                            @else
                                                <x-heroicon-s-clock class="w-3 h-3" />
                                            @endif
                                            {{ $job['status_label'] }}
                                        </span>
                                        @if($job['processing_time'])
                                            <div class="text-xs text-gray-500 mt-1">
                                                Depuis {{ $job['processing_time'] }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400">
                                        {{ $job['document'] ?? '-' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="{{ $job['wait_time'] > 300 ? 'text-warning-600 dark:text-warning-400 font-medium' : 'text-gray-500 dark:text-gray-400' }}">
                                            {{ $job['wait_time_human'] }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-center text-gray-500 dark:text-gray-400">
                                        {{ $job['attempts'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if(!empty($queueStats['by_queue']['llm-chunking']))
                    <div class="mt-4 p-3 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-700 rounded-lg">
                        <div class="flex items-start gap-2">
                            <x-heroicon-o-cpu-chip class="w-5 h-5 text-purple-500 flex-shrink-0 mt-0.5" />
                            <div class="text-sm text-purple-700 dark:text-purple-300">
                                <strong>Jobs LLM Chunking en attente.</strong> Démarrez le worker dédié:
                                <code class="block mt-1 p-2 bg-gray-800 text-gray-100 rounded text-xs">
                                    php artisan queue:work --queue=llm-chunking
                                </code>
                            </div>
                        </div>
                    </div>
                @endif
            </x-filament::section>
        @endif

        {{-- AI Messages Stats --}}
        @if(!isset($aiMessageStats['error']))
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-chat-bubble-left-right class="w-5 h-5" />
                        Messages IA (Async)
                    </div>
                </x-slot>

                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-4">
                    <div class="text-center p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div class="text-2xl font-bold text-warning-600 dark:text-warning-400">
                            {{ ($aiMessageStats['pending'] ?? 0) + ($aiMessageStats['queued'] ?? 0) }}
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">En file</div>
                    </div>
                    <div class="text-center p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">
                            {{ $aiMessageStats['processing'] ?? 0 }}
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">En cours</div>
                    </div>
                    <div class="text-center p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div class="text-2xl font-bold text-success-600 dark:text-success-400">
                            {{ $aiMessageStats['completed_today'] ?? 0 }}
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">Complétés (j)</div>
                    </div>
                    <div class="text-center p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div class="text-2xl font-bold text-danger-600 dark:text-danger-400">
                            {{ $aiMessageStats['failed_today'] ?? 0 }}
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">Échoués (j)</div>
                    </div>
                    <div class="text-center p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div class="text-2xl font-bold text-danger-600 dark:text-danger-400">
                            {{ $aiMessageStats['failed_total'] ?? 0 }}
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">Échoués (total)</div>
                    </div>
                    <div class="text-center p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div class="text-xl font-bold text-gray-600 dark:text-gray-400">
                            @if(($aiMessageStats['avg_generation_time_ms'] ?? 0) > 0)
                                {{ number_format(($aiMessageStats['avg_generation_time_ms'] ?? 0) / 1000, 1) }}s
                            @else
                                -
                            @endif
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">Temps moyen</div>
                    </div>
                </div>

                {{-- Queue des messages en cours --}}
                @if(count($aiMessageQueue) > 0)
                    <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            File d'attente des messages ({{ count($aiMessageQueue) }})
                        </h4>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">#</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Agent</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Status</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">En queue</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Démarré</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Attente</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($aiMessageQueue as $msg)
                                        <tr>
                                            <td class="px-3 py-2 text-gray-500">{{ $msg['position'] }}</td>
                                            <td class="px-3 py-2 font-medium text-gray-900 dark:text-gray-100">{{ $msg['agent'] }}</td>
                                            <td class="px-3 py-2">
                                                <span class="px-2 py-1 text-xs font-medium rounded-full {{
                                                    $msg['status'] === 'processing' ? 'bg-primary-100 text-primary-700 dark:bg-primary-800 dark:text-primary-300' :
                                                    ($msg['status'] === 'queued' ? 'bg-warning-100 text-warning-700 dark:bg-warning-800 dark:text-warning-300' :
                                                    'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300')
                                                }}">
                                                    @if($msg['status'] === 'processing')
                                                        <x-heroicon-s-arrow-path class="w-3 h-3 inline animate-spin mr-1" />
                                                    @endif
                                                    {{ $msg['status'] }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 text-gray-500 text-xs">{{ $msg['queued_at'] ?? '-' }}</td>
                                            <td class="px-3 py-2 text-gray-500 text-xs">{{ $msg['processing_started_at'] ?? '-' }}</td>
                                            <td class="px-3 py-2 text-gray-500 text-xs">{{ $msg['wait_time'] ?? '-' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </x-filament::section>
        @endif

        {{-- Failed AI Messages Section --}}
        @if(count($failedAiMessages) > 0)
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2 text-danger-600 dark:text-danger-400">
                        <x-heroicon-o-chat-bubble-left-right class="w-5 h-5" />
                        Messages IA en échec ({{ count($failedAiMessages) }})
                    </div>
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Agent</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Erreur</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Retries</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-700 dark:text-gray-300">Date</th>
                                <th class="px-4 py-2 text-center font-medium text-gray-700 dark:text-gray-300">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($failedAiMessages as $msg)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">
                                        {{ $msg['agent'] }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div x-data="{ showFull: false }" class="max-w-md">
                                            <p class="text-danger-600 dark:text-danger-400 text-xs font-mono break-words">
                                                {{ $msg['error'] }}
                                            </p>
                                            @if($msg['full_error'] && strlen($msg['full_error']) > 150)
                                                <button
                                                    @click="showFull = !showFull"
                                                    class="text-xs text-primary-600 hover:underline mt-1"
                                                >
                                                    <span x-show="!showFull">Voir plus...</span>
                                                    <span x-show="showFull" x-cloak>Réduire</span>
                                                </button>
                                                <pre x-show="showFull" x-cloak class="mt-2 p-2 bg-gray-800 text-green-400 text-xs rounded overflow-x-auto max-h-32 overflow-y-auto">{{ $msg['full_error'] }}</pre>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400 text-center">
                                        {{ $msg['retry_count'] }}
                                    </td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400 text-xs whitespace-nowrap">
                                        {{ $msg['failed_at'] }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <button
                                            wire:click="retryAiMessage({{ $msg['id'] }})"
                                            wire:loading.attr="disabled"
                                            wire:target="retryAiMessage({{ $msg['id'] }})"
                                            class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-white bg-warning-600 rounded hover:bg-warning-700 disabled:opacity-50 transition"
                                        >
                                            <x-heroicon-o-arrow-path class="w-3 h-3" wire:loading.class="animate-spin" wire:target="retryAiMessage({{ $msg['id'] }})" />
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
                                                <pre x-show="showFull" x-cloak class="mt-2 p-2 bg-gray-800 text-green-400 text-xs rounded overflow-x-auto max-h-48 overflow-y-auto">{{ $job['full_exception'] }}</pre>
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
            <x-filament::section collapsible>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-circle-stack class="w-5 h-5" />
                        Collections Qdrant
                        <span class="text-xs font-normal text-gray-500">
                            ({{ number_format($services['qdrant']['total_points'] ?? 0) }} points total)
                        </span>
                    </div>
                </x-slot>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($services['qdrant']['collection_details'] ?? [] as $collectionName => $details)
                        <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex items-center gap-2">
                                    <x-heroicon-o-circle-stack class="w-5 h-5 text-primary-500" />
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $collectionName }}</span>
                                </div>
                                @if(($details['status'] ?? '') === 'green')
                                    <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-success-100 text-success-700 dark:bg-success-800 dark:text-success-300">
                                        Actif
                                    </span>
                                @endif
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div class="text-center p-2 bg-white dark:bg-gray-900 rounded">
                                    <div class="text-xl font-bold text-primary-600 dark:text-primary-400">
                                        {{ number_format($details['points_count'] ?? 0) }}
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Points</div>
                                </div>
                                <div class="text-center p-2 bg-white dark:bg-gray-900 rounded">
                                    <div class="text-xl font-bold text-gray-600 dark:text-gray-400">
                                        {{ number_format($details['vectors_count'] ?? 0) }}
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Vecteurs</div>
                                </div>
                            </div>

                            @if(isset($details['error']))
                                <div class="mt-2 p-2 bg-danger-50 dark:bg-danger-900/20 rounded text-xs text-danger-600 dark:text-danger-400">
                                    {{ $details['error'] }}
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- Models Ollama Details --}}
        @if(isset($services['ollama']) && $services['ollama']['status'] === 'online')
            <x-filament::section collapsible>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-cube class="w-5 h-5" />
                        Modèles Ollama
                    </div>
                </x-slot>

                {{-- Modèles installés --}}
                <div class="mb-6">
                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                        Modèles installés ({{ count($ollamaModels) }})
                    </h4>

                    @if(count($ollamaModels) > 0)
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                            @foreach($ollamaModels as $model)
                                <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                                    <div class="flex items-start justify-between">
                                        <div class="flex items-center gap-2">
                                            <x-heroicon-o-cube class="w-5 h-5 text-primary-500 flex-shrink-0" />
                                            <div>
                                                <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $model['name'] }}</span>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $model['size_human'] }}</p>
                                            </div>
                                        </div>
                                        <button
                                            wire:click="deleteOllamaModel('{{ $model['name'] }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="deleteOllamaModel('{{ $model['name'] }}')"
                                            wire:confirm="Êtes-vous sûr de vouloir supprimer le modèle {{ $model['name'] }} ? Cette action libérera de l'espace disque."
                                            class="p-1.5 text-gray-400 hover:text-danger-500 hover:bg-danger-50 dark:hover:bg-danger-900/20 rounded transition"
                                            title="Supprimer ce modèle"
                                        >
                                            <x-heroicon-o-trash class="w-4 h-4" wire:loading.class="animate-pulse" wire:target="deleteOllamaModel('{{ $model['name'] }}')" />
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg text-center text-gray-500 dark:text-gray-400">
                            Aucun modèle installé
                        </div>
                    @endif
                </div>

                {{-- Installer un nouveau modèle --}}
                <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            Installer un nouveau modèle
                        </h4>
                        <div class="flex items-center gap-3">
                            @if($lastSyncInfo['last_sync'] ?? null)
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    Sync: {{ \Carbon\Carbon::parse($lastSyncInfo['last_sync'])->diffForHumans() }}
                                    ({{ $lastSyncInfo['source'] ?? 'config' }})
                                </span>
                            @endif
                            <button
                                wire:click="syncAvailableModels"
                                wire:loading.attr="disabled"
                                wire:target="syncAvailableModels"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 disabled:opacity-50 transition"
                                title="Actualiser la liste des modèles disponibles"
                            >
                                <x-heroicon-o-arrow-path class="w-3.5 h-3.5" wire:loading.class="animate-spin" wire:target="syncAvailableModels" />
                                <span wire:loading.remove wire:target="syncAvailableModels">Synchroniser</span>
                                <span wire:loading wire:target="syncAvailableModels">Sync...</span>
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {{-- Liste des modèles recommandés --}}
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">
                                Modèles recommandés
                            </label>

                            @if(count($availableModels) > 0)
                                <div class="space-y-2 max-h-64 overflow-y-auto pr-2">
                                    @foreach($availableModels as $modelKey => $modelInfo)
                                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-primary-300 dark:hover:border-primary-600 transition">
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2">
                                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $modelInfo['name'] }}</span>
                                                    <span class="px-1.5 py-0.5 text-xs rounded {{
                                                        $modelInfo['type'] === 'chat' ? 'bg-primary-100 text-primary-700 dark:bg-primary-800 dark:text-primary-300' :
                                                        ($modelInfo['type'] === 'embedding' ? 'bg-success-100 text-success-700 dark:bg-success-800 dark:text-success-300' :
                                                        'bg-warning-100 text-warning-700 dark:bg-warning-800 dark:text-warning-300')
                                                    }}">
                                                        {{ $modelInfo['type'] }}
                                                    </span>
                                                </div>
                                                <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                                    {{ $modelInfo['size'] }} - {{ $modelInfo['description'] }}
                                                </p>
                                            </div>
                                            <button
                                                wire:click="installOllamaModel('{{ $modelKey }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="installOllamaModel"
                                                @if($isInstallingModel) disabled @endif
                                                class="ml-3 px-3 py-1.5 text-xs font-medium text-white bg-primary-600 rounded hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed transition flex items-center gap-1"
                                            >
                                                <x-heroicon-o-arrow-down-tray class="w-3.5 h-3.5" />
                                                Installer
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="p-3 bg-success-50 dark:bg-success-900/20 border border-success-200 dark:border-success-700 rounded-lg text-sm text-success-700 dark:text-success-300">
                                    <x-heroicon-o-check-circle class="w-4 h-4 inline mr-1" />
                                    Tous les modèles recommandés sont déjà installés !
                                </div>
                            @endif
                        </div>

                        {{-- Installation personnalisée --}}
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">
                                Ou entrez un nom de modèle personnalisé
                            </label>
                            <div class="flex gap-2">
                                <input
                                    type="text"
                                    wire:model="customModelName"
                                    placeholder="ex: llama3.1:70b, codellama:13b..."
                                    class="flex-1 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                    @if($isInstallingModel) disabled @endif
                                />
                                <button
                                    wire:click="installOllamaModel"
                                    wire:loading.attr="disabled"
                                    wire:target="installOllamaModel"
                                    @if($isInstallingModel) disabled @endif
                                    class="px-4 py-2 text-sm font-medium text-white bg-primary-600 rounded-lg hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed transition flex items-center gap-2"
                                >
                                    <span wire:loading.remove wire:target="installOllamaModel">
                                        <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
                                    </span>
                                    <span wire:loading wire:target="installOllamaModel">
                                        <x-heroicon-o-arrow-path class="w-4 h-4 animate-spin" />
                                    </span>
                                    <span wire:loading.remove wire:target="installOllamaModel">Installer</span>
                                    <span wire:loading wire:target="installOllamaModel">Installation...</span>
                                </button>
                            </div>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                Consultez <a href="https://ollama.com/library" target="_blank" class="text-primary-600 hover:underline">ollama.com/library</a> pour voir tous les modèles disponibles.
                            </p>

                            @if($isInstallingModel)
                                <div class="mt-4 p-3 bg-primary-50 dark:bg-primary-900/20 border border-primary-200 dark:border-primary-700 rounded-lg">
                                    <div class="flex items-center gap-2 text-primary-700 dark:text-primary-300">
                                        <x-heroicon-o-arrow-path class="w-5 h-5 animate-spin" />
                                        <span class="text-sm font-medium">Téléchargement en cours...</span>
                                    </div>
                                    <p class="mt-1 text-xs text-primary-600 dark:text-primary-400">
                                        Le téléchargement peut prendre plusieurs minutes selon la taille du modèle et votre connexion.
                                    </p>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>

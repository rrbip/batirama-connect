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
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $service['details'] }}
                        </p>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- Queue Stats --}}
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

                @if($queueStats['connection'] !== 'sync')
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

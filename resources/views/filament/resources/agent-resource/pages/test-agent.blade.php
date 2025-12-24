<x-filament-panels::page>
    @php
        $agent = $this->getRecord();
        $testSession = $this->getTestSession();
        $ollamaStatus = $this->ollamaStatus;
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6"
         x-data="{
            pendingMessage: null,
            isProcessing: @entangle('isLoading'),
            timeoutId: null,
            elapsedTime: 0,
            timerInterval: null,
            pollingInterval: null,
            queuePosition: null,
            processingStatus: null,
            inputMessage: @entangle('userMessage'),

            scrollToBottom() {
                this.$nextTick(() => {
                    const container = document.getElementById('chat-messages');
                    if (container) {
                        container.scrollTop = container.scrollHeight;
                    }
                });
            },

            startPolling() {
                // Arreter le polling existant
                if (this.pollingInterval) {
                    clearInterval(this.pollingInterval);
                }

                // Polling toutes les 500ms
                this.pollingInterval = setInterval(async () => {
                    if (!this.isProcessing) {
                        this.stopPolling();
                        return;
                    }

                    try {
                        const result = await $wire.checkMessageStatus();

                        if (result.done) {
                            this.stopPolling();
                            this.pendingMessage = null;
                        } else {
                            this.queuePosition = result.queue_position;
                            this.processingStatus = result.status;
                        }
                    } catch (error) {
                        console.error('Polling error:', error);
                    }
                }, 500);
            },

            stopPolling() {
                if (this.pollingInterval) {
                    clearInterval(this.pollingInterval);
                    this.pollingInterval = null;
                }
                if (this.timerInterval) {
                    clearInterval(this.timerInterval);
                    this.timerInterval = null;
                }
                this.queuePosition = null;
                this.processingStatus = null;
                this.elapsedTime = 0;
            },

            resetState() {
                this.pendingMessage = null;
                this.elapsedTime = 0;
                this.stopPolling();
                this.scrollToBottom();
            },

            sendOptimistic() {
                if (!this.inputMessage || this.inputMessage.trim() === '') return;
                if (this.isProcessing) return;

                // Garder le message avant de vider
                const messageToSend = this.inputMessage;

                // Afficher immediatement le message utilisateur (optimiste)
                this.pendingMessage = {
                    content: messageToSend,
                    timestamp: new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
                };
                this.elapsedTime = 0;

                // Vider le champ
                this.inputMessage = '';

                this.scrollToBottom();

                // Timer pour afficher le temps ecoule
                this.timerInterval = setInterval(() => {
                    this.elapsedTime++;
                }, 1000);

                // Soumettre au serveur avec le message en parametre
                $wire.sendMessage(messageToSend);
            }
         }"
         x-on:message-sent.window="startPolling()"
         x-on:message-received.window="resetState()"
         x-init="
            // Si un message est en cours au chargement de la page, demarrer le polling
            if (isProcessing) {
                startPolling();
                timerInterval = setInterval(() => { elapsedTime++; }, 1000);
            }
            scrollToBottom();
         "
    >
        {{-- Info Agent --}}
        <div class="lg:col-span-1">
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-cpu-chip class="w-5 h-5" />
                        {{ $agent->name }}
                    </div>
                </x-slot>

                <div class="space-y-3 text-sm">
                    @foreach($this->agentInfo as $label => $value)
                        <div class="flex justify-between">
                            <span class="text-gray-500 dark:text-gray-400">{{ $label }}</span>
                            <span class="font-medium">{{ $value }}</span>
                        </div>
                    @endforeach
                </div>

                @if($agent->description)
                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $agent->description }}
                        </p>
                    </div>
                @endif

                @if($testSession)
                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <div class="text-xs text-gray-500">
                            Session: {{ Str::limit($testSession->uuid, 8) }}
                        </div>
                    </div>
                @endif
            </x-filament::section>

            {{-- Statut Ollama --}}
            <x-filament::section class="mt-4">
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-server class="w-5 h-5" />
                        Serveur LLM
                    </div>
                </x-slot>

                <div class="space-y-2 text-sm">
                    <div class="flex items-center gap-2">
                        @if($ollamaStatus['available'])
                            <span class="w-2 h-2 bg-success-500 rounded-full"></span>
                            <span class="text-success-600 dark:text-success-400">Connecte</span>
                        @else
                            <span class="w-2 h-2 bg-danger-500 rounded-full animate-pulse"></span>
                            <span class="text-danger-600 dark:text-danger-400">Non disponible</span>
                        @endif
                    </div>

                    <div class="text-xs text-gray-500">
                        {{ $ollamaStatus['url'] }}
                    </div>

                    @if($ollamaStatus['available'] && !empty($ollamaStatus['models']))
                        <div class="mt-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                            <span class="text-xs text-gray-500">Modeles disponibles:</span>
                            <ul class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                                @foreach(array_slice($ollamaStatus['models'], 0, 5) as $model)
                                    <li>{{ $model }}</li>
                                @endforeach
                                @if(count($ollamaStatus['models']) > 5)
                                    <li class="text-gray-400">... et {{ count($ollamaStatus['models']) - 5 }} autres</li>
                                @endif
                            </ul>
                        </div>
                    @elseif(!$ollamaStatus['available'])
                        <div class="mt-2 p-2 bg-danger-50 dark:bg-danger-950 rounded text-xs text-danger-700 dark:text-danger-300">
                            <p class="font-medium">Ollama n'est pas accessible.</p>
                            <p class="mt-1">Verifiez que le serveur est lance ou configurez un autre provider.</p>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        </div>

        {{-- Zone de chat --}}
        <div class="lg:col-span-3">
            <x-filament::section class="h-full">
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        Console de test
                        <template x-if="isProcessing">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-warning-100 text-warning-800 dark:bg-warning-900 dark:text-warning-200">
                                <svg class="animate-spin -ml-0.5 mr-1.5 h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span x-text="
                                    processingStatus === 'queued' ? 'En file #' + queuePosition :
                                    processingStatus === 'processing' ? 'Generation...' :
                                    'Traitement...'
                                "></span>
                                <span x-show="elapsedTime > 0" x-text="' (' + elapsedTime + 's)'"></span>
                            </span>
                        </template>
                    </div>
                </x-slot>

                {{-- Messages --}}
                <div class="h-96 overflow-y-auto mb-4 space-y-4 p-4 bg-gray-50 dark:bg-gray-900 rounded-lg" id="chat-messages">
                    @forelse($messages as $index => $message)
                        {{-- Message utilisateur --}}
                        @if($message['role'] === 'user')
                            <div class="flex justify-end">
                                <div class="max-w-[80%]">
                                    <div class="bg-primary-500 text-white rounded-lg p-3 shadow-sm">
                                        <div class="prose prose-sm prose-invert max-w-none">
                                            {!! \Illuminate\Support\Str::markdown($message['content']) !!}
                                        </div>
                                        <div class="flex items-center justify-between mt-2 text-xs text-primary-200">
                                            <span>{{ $message['timestamp'] }}</span>
                                        </div>
                                    </div>

                                    {{-- Contexte envoye a l'IA (structure detaillee) --}}
                                    @if(!empty($message['rag_context']))
                                        @php
                                            $context = $message['rag_context'];
                                            $learnedSources = $context['learned_sources'] ?? [];
                                            $documentSources = $context['document_sources'] ?? [];
                                            $systemPrompt = $context['system_prompt_sent'] ?? '';
                                            $totalSources = count($learnedSources) + count($documentSources);
                                        @endphp

                                        <details class="mt-2 border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800">
                                            <summary class="p-2 text-xs text-gray-600 dark:text-gray-400 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 rounded-lg flex items-center gap-2">
                                                <x-heroicon-o-document-magnifying-glass class="w-4 h-4" />
                                                <span>Voir le contexte envoye a l'IA</span>
                                                @if($totalSources > 0)
                                                    <span class="text-gray-400">({{ $totalSources }} source(s))</span>
                                                @endif
                                            </summary>

                                            <div class="p-3 space-y-4 text-xs border-t border-gray-200 dark:border-gray-700">
                                                {{-- 1. Prompt systeme --}}
                                                @if(!empty($systemPrompt))
                                                    <div>
                                                        <details>
                                                            <summary class="cursor-pointer text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 flex items-center gap-1 font-medium">
                                                                <x-heroicon-o-cog-6-tooth class="w-4 h-4 text-success-600" />
                                                                1. Prompt systeme
                                                            </summary>
                                                            <div class="mt-2 p-2 bg-success-50 dark:bg-success-950 rounded border border-success-200 dark:border-success-800 text-gray-700 dark:text-gray-300 whitespace-pre-wrap max-h-48 overflow-y-auto">{{ $systemPrompt }}</div>
                                                        </details>
                                                    </div>
                                                @endif

                                                {{-- 2. Documents indexes (RAG) --}}
                                                @if(!empty($documentSources))
                                                    <div>
                                                        <h5 class="font-semibold text-info-600 dark:text-info-400 mb-2 flex items-center gap-1">
                                                            <x-heroicon-o-document-text class="w-4 h-4" />
                                                            2. Documents indexes ({{ count($documentSources) }})
                                                        </h5>
                                                        <div class="space-y-2">
                                                            @foreach($documentSources as $doc)
                                                                <div class="p-2 bg-gray-50 dark:bg-gray-900 rounded border-l-2 border-info-500">
                                                                    <details>
                                                                        <summary class="cursor-pointer flex items-center justify-between">
                                                                            <span class="font-medium text-gray-700 dark:text-gray-300">
                                                                                Document #{{ $doc['index'] ?? $loop->iteration }}
                                                                                @if(isset($doc['metadata']['title']))
                                                                                    - {{ \Illuminate\Support\Str::limit($doc['metadata']['title'], 40) }}
                                                                                @elseif(isset($doc['metadata']['filename']))
                                                                                    - {{ $doc['metadata']['filename'] }}
                                                                                @endif
                                                                            </span>
                                                                            <x-filament::badge size="sm" color="info">
                                                                                {{ $doc['score'] ?? 0 }}% pertinent
                                                                            </x-filament::badge>
                                                                        </summary>
                                                                        <div class="mt-2 p-2 bg-white dark:bg-gray-800 rounded text-gray-700 dark:text-gray-300 whitespace-pre-wrap max-h-32 overflow-y-auto">{{ $doc['content'] ?? '' }}</div>
                                                                    </details>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif

                                                {{-- 3. Sources d'apprentissage --}}
                                                @if(!empty($learnedSources))
                                                    <div>
                                                        <h5 class="font-semibold text-primary-600 dark:text-primary-400 mb-2 flex items-center gap-1">
                                                            <x-heroicon-o-academic-cap class="w-4 h-4" />
                                                            3. Sources d'apprentissage ({{ count($learnedSources) }})
                                                        </h5>
                                                        <div class="space-y-2">
                                                            @foreach($learnedSources as $learned)
                                                                <div class="p-2 bg-primary-50 dark:bg-primary-950 rounded border-l-2 border-primary-500">
                                                                    <details>
                                                                        <summary class="cursor-pointer flex items-center justify-between">
                                                                            <span class="font-medium text-gray-700 dark:text-gray-300">
                                                                                Cas #{{ $learned['index'] ?? $loop->iteration }}
                                                                            </span>
                                                                            <x-filament::badge size="sm" color="primary">
                                                                                {{ $learned['score'] ?? 0 }}% similaire
                                                                            </x-filament::badge>
                                                                        </summary>
                                                                        <div class="mt-2 space-y-1">
                                                                            <div class="text-gray-600 dark:text-gray-400">
                                                                                <strong>Q:</strong> {{ $learned['question'] ?? '' }}
                                                                            </div>
                                                                            <div class="p-2 bg-white dark:bg-gray-900 rounded text-gray-700 dark:text-gray-300">
                                                                                <strong>R:</strong> {{ $learned['answer'] ?? '' }}
                                                                            </div>
                                                                        </div>
                                                                    </details>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif

                                                {{-- 4. Texte brut complet envoye --}}
                                                @if(!empty($systemPrompt))
                                                    <div>
                                                        <details>
                                                            <summary class="cursor-pointer text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 flex items-center gap-1 font-medium">
                                                                <x-heroicon-o-code-bracket class="w-4 h-4 text-gray-500" />
                                                                4. Texte brut complet (raw data)
                                                            </summary>
                                                            <div class="mt-2 p-2 bg-gray-100 dark:bg-gray-900 rounded border border-gray-300 dark:border-gray-600 font-mono text-gray-700 dark:text-gray-300 whitespace-pre-wrap max-h-64 overflow-y-auto text-[10px]">{{ $systemPrompt }}</div>
                                                        </details>
                                                    </div>
                                                @endif
                                            </div>
                                        </details>
                                    @endif
                                </div>
                            </div>

                        {{-- Message assistant --}}
                        @elseif($message['role'] === 'assistant')
                            <div class="flex justify-start">
                                <div class="max-w-[80%]">
                                    {{-- Statut en cours (bulle avec animation) --}}
                                    @if(isset($message['processing_status']) && in_array($message['processing_status'], ['pending', 'queued', 'processing']))
                                        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-3 shadow-sm">
                                            <div class="flex items-center gap-3">
                                                <div class="flex space-x-1">
                                                    <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms;"></span>
                                                    <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms;"></span>
                                                    <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms;"></span>
                                                </div>
                                                <span class="text-sm text-gray-500 dark:text-gray-400">
                                                    @if($message['processing_status'] === 'queued')
                                                        En file d'attente...
                                                    @elseif($message['processing_status'] === 'processing')
                                                        {{ $agent->name }} reflechit...
                                                    @else
                                                        En attente...
                                                    @endif
                                                </span>
                                            </div>
                                        </div>

                                    {{-- Erreur --}}
                                    @elseif(isset($message['processing_status']) && $message['processing_status'] === 'failed')
                                        <div class="bg-danger-50 dark:bg-danger-950 border border-danger-200 dark:border-danger-800 rounded-lg p-3 shadow-sm">
                                            <div class="flex items-start gap-2">
                                                <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-danger-500 flex-shrink-0 mt-0.5" />
                                                <div class="flex-1">
                                                    <div class="text-sm font-medium text-danger-700 dark:text-danger-300">
                                                        Erreur de traitement
                                                    </div>
                                                    @if(!empty($message['processing_error']))
                                                        <div class="text-xs text-danger-600 dark:text-danger-400 mt-1">
                                                            {{ $message['processing_error'] }}
                                                        </div>
                                                    @endif
                                                    @if(isset($message['uuid']))
                                                        <button
                                                            wire:click="retryMessage('{{ $message['uuid'] }}')"
                                                            class="mt-2 inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded bg-danger-100 dark:bg-danger-900 text-danger-700 dark:text-danger-300 hover:bg-danger-200 dark:hover:bg-danger-800 transition-colors"
                                                        >
                                                            <x-heroicon-o-arrow-path class="w-3 h-3" />
                                                            Reessayer
                                                        </button>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>

                                    {{-- Reponse complete --}}
                                    @else
                                        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-3 shadow-sm">
                                            <div class="prose prose-sm dark:prose-invert max-w-none">
                                                {!! \Illuminate\Support\Str::markdown($message['content'] ?? '') !!}
                                            </div>
                                            <div class="flex items-center justify-between mt-2 text-xs text-gray-400">
                                                <span>{{ $message['timestamp'] }}</span>
                                                <div class="flex items-center gap-2">
                                                    @if(isset($message['model_used']))
                                                        <span class="text-gray-400">{{ $message['model_used'] }}</span>
                                                    @endif
                                                    @if(isset($message['tokens']) && $message['tokens'])
                                                        <span>{{ $message['tokens'] }} tokens</span>
                                                    @endif
                                                    @if(isset($message['generation_time_ms']) && $message['generation_time_ms'])
                                                        <span>{{ number_format($message['generation_time_ms'] / 1000, 1) }}s</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>

                        {{-- Message d'erreur systeme --}}
                        @elseif($message['role'] === 'error')
                            <div class="flex justify-start">
                                <div class="max-w-[80%] bg-danger-100 text-danger-800 dark:bg-danger-900 dark:text-danger-200 rounded-lg p-3 shadow-sm">
                                    <div class="flex items-center gap-2">
                                        <x-heroicon-o-exclamation-circle class="w-5 h-5" />
                                        <span class="text-sm">{{ $message['content'] }}</span>
                                    </div>
                                    <div class="text-xs text-danger-600 dark:text-danger-400 mt-1">
                                        {{ $message['timestamp'] }}
                                    </div>
                                </div>
                            </div>
                        @endif
                    @empty
                        <template x-if="!pendingMessage">
                            <div class="flex items-center justify-center h-full text-gray-400">
                                <div class="text-center">
                                    <x-heroicon-o-chat-bubble-left-ellipsis class="w-12 h-12 mx-auto mb-2" />
                                    <p>Envoyez un message pour commencer</p>
                                </div>
                            </div>
                        </template>
                    @endforelse

                    {{-- Message optimiste utilisateur (affiche immediatement avant confirmation serveur) --}}
                    <template x-if="pendingMessage">
                        <div class="flex justify-end">
                            <div class="max-w-[80%] bg-primary-500 text-white rounded-lg p-3 shadow-sm">
                                <div class="prose prose-sm prose-invert max-w-none" x-text="pendingMessage.content"></div>
                                <div class="flex items-center justify-between mt-2 text-xs text-primary-200">
                                    <span x-text="pendingMessage.timestamp"></span>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Input --}}
                <form x-on:submit.prevent="sendOptimistic" class="flex gap-2">
                    <div class="flex-1">
                        <x-filament::input.wrapper>
                            <x-filament::input
                                type="text"
                                x-model="inputMessage"
                                placeholder="Tapez votre message..."
                                x-bind:disabled="isProcessing"
                                autofocus
                                x-on:keydown.enter.prevent="sendOptimistic"
                            />
                        </x-filament::input.wrapper>
                    </div>
                    <x-filament::button
                        type="submit"
                        x-bind:disabled="isProcessing"
                        icon="heroicon-o-paper-airplane"
                    >
                        <span x-show="!isProcessing">Envoyer</span>
                        <span x-show="isProcessing" class="flex items-center gap-1">
                            <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Envoi...
                        </span>
                    </x-filament::button>
                </form>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>

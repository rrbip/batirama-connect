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

                                    {{-- Bouton pour voir le contexte envoye a l'IA --}}
                                    @if(!empty($message['rag_context']))
                                        @php
                                            $context = $message['rag_context'];
                                            $learnedSources = $context['learned_sources'] ?? [];
                                            $documentSources = $context['document_sources'] ?? [];
                                            $conversationHistory = $context['conversation_history'] ?? [];
                                            $systemPrompt = $context['system_prompt_sent'] ?? '';
                                            $stats = $context['stats'] ?? [];
                                            $totalSources = count($learnedSources) + count($documentSources);
                                            $modalId = 'context-modal-' . ($message['uuid'] ?? $index);
                                        @endphp

                                        <div x-data="{ open: false }">
                                            {{-- Bouton d'ouverture --}}
                                            <button
                                                @click="open = true"
                                                type="button"
                                                class="mt-2 inline-flex items-center gap-2 px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                                            >
                                                <x-heroicon-o-document-magnifying-glass class="w-4 h-4" />
                                                <span>Voir le contexte envoye a l'IA</span>
                                                @if($totalSources > 0 || count($conversationHistory) > 0)
                                                    <span class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-gray-500 dark:text-gray-400">
                                                        {{ $totalSources + count($conversationHistory) }}
                                                    </span>
                                                @endif
                                            </button>

                                            {{-- Modale plein ecran --}}
                                            <template x-teleport="body">
                                                <div
                                                    x-show="open"
                                                    x-transition:enter="transition ease-out duration-200"
                                                    x-transition:enter-start="opacity-0"
                                                    x-transition:enter-end="opacity-100"
                                                    x-transition:leave="transition ease-in duration-150"
                                                    x-transition:leave-start="opacity-100"
                                                    x-transition:leave-end="opacity-0"
                                                    class="fixed inset-0 z-50 overflow-hidden"
                                                    style="display: none;"
                                                >
                                                    {{-- Backdrop --}}
                                                    <div class="absolute inset-0 bg-black/50" @click="open = false"></div>

                                                    {{-- Contenu de la modale --}}
                                                    <div class="absolute inset-4 md:inset-8 lg:inset-12 bg-white dark:bg-gray-900 rounded-xl shadow-2xl flex flex-col overflow-hidden">
                                                        {{-- Header --}}
                                                        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                                                            <div>
                                                                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Contexte envoye a l'IA</h2>
                                                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                                                                    {{ $totalSources }} source(s) documentaire(s)
                                                                    @if(count($conversationHistory) > 0)
                                                                        &bull; {{ count($conversationHistory) }} message(s) d'historique
                                                                    @endif
                                                                </p>
                                                            </div>
                                                            <button @click="open = false" class="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                                                <x-heroicon-o-x-mark class="w-6 h-6" />
                                                            </button>
                                                        </div>

                                                        {{-- Corps scrollable --}}
                                                        <div class="flex-1 overflow-y-auto p-6 space-y-6">
                                                            {{-- 1. Prompt systeme --}}
                                                            @if(!empty($systemPrompt))
                                                                <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600 overflow-hidden">
                                                                    <details open>
                                                                        <summary class="px-4 py-3 cursor-pointer bg-emerald-50 dark:bg-emerald-950 border-b border-gray-200 dark:border-gray-600 hover:bg-emerald-100 dark:hover:bg-emerald-900 transition-colors">
                                                                            <span class="font-semibold text-emerald-700 dark:text-emerald-300 flex items-center gap-2">
                                                                                <x-heroicon-o-cog-6-tooth class="w-5 h-5" />
                                                                                1. Prompt systeme
                                                                            </span>
                                                                        </summary>
                                                                        <div class="p-4 bg-gray-50 dark:bg-gray-900">
                                                                            <pre class="text-sm text-gray-800 dark:text-gray-100 whitespace-pre-wrap font-sans leading-relaxed">{{ $systemPrompt }}</pre>
                                                                        </div>
                                                                    </details>
                                                                </div>
                                                            @endif

                                                            {{-- 2. Historique de conversation --}}
                                                            @if(!empty($conversationHistory))
                                                                <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600 overflow-hidden">
                                                                    <details open>
                                                                        <summary class="px-4 py-3 cursor-pointer bg-violet-50 dark:bg-violet-950 border-b border-gray-200 dark:border-gray-600 hover:bg-violet-100 dark:hover:bg-violet-900 transition-colors">
                                                                            <span class="font-semibold text-violet-700 dark:text-violet-300 flex items-center gap-2">
                                                                                <x-heroicon-o-clock class="w-5 h-5" />
                                                                                2. Historique de conversation ({{ count($conversationHistory) }} messages)
                                                                                @if(isset($stats['context_window_size']))
                                                                                    <span class="text-xs font-normal text-violet-500 dark:text-violet-400">(fenetre: {{ $stats['context_window_size'] }} echanges max)</span>
                                                                                @endif
                                                                            </span>
                                                                        </summary>
                                                                        <div class="p-4 space-y-3 bg-gray-50 dark:bg-gray-900">
                                                                            @foreach($conversationHistory as $historyMsg)
                                                                                <div class="flex gap-3 {{ $historyMsg['role'] === 'user' ? 'flex-row-reverse' : '' }}">
                                                                                    <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center {{ $historyMsg['role'] === 'user' ? 'bg-blue-100 dark:bg-blue-800' : 'bg-gray-200 dark:bg-gray-700' }}">
                                                                                        @if($historyMsg['role'] === 'user')
                                                                                            <x-heroicon-o-user class="w-4 h-4 text-blue-600 dark:text-blue-300" />
                                                                                        @else
                                                                                            <x-heroicon-o-cpu-chip class="w-4 h-4 text-gray-600 dark:text-gray-300" />
                                                                                        @endif
                                                                                    </div>
                                                                                    <div class="flex-1 {{ $historyMsg['role'] === 'user' ? 'text-right' : '' }}">
                                                                                        <div class="inline-block max-w-[80%] p-3 rounded-lg {{ $historyMsg['role'] === 'user' ? 'bg-blue-100 dark:bg-blue-900 text-left' : 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600' }}">
                                                                                            <p class="text-sm text-gray-800 dark:text-gray-100 whitespace-pre-wrap">{{ $historyMsg['content'] }}</p>
                                                                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $historyMsg['timestamp'] ?? '' }}</p>
                                                                                        </div>
                                                                                    </div>
                                                                                </div>
                                                                            @endforeach
                                                                        </div>
                                                                    </details>
                                                                </div>
                                                            @endif

                                                            {{-- 3. Documents indexes (RAG) --}}
                                                            @if(!empty($documentSources))
                                                                <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600 overflow-hidden">
                                                                    <details open>
                                                                        <summary class="px-4 py-3 cursor-pointer bg-cyan-50 dark:bg-cyan-950 border-b border-gray-200 dark:border-gray-600 hover:bg-cyan-100 dark:hover:bg-cyan-900 transition-colors">
                                                                            <span class="font-semibold text-cyan-700 dark:text-cyan-300 flex items-center gap-2">
                                                                                <x-heroicon-o-document-text class="w-5 h-5" />
                                                                                3. Documents indexes - RAG ({{ count($documentSources) }})
                                                                            </span>
                                                                        </summary>
                                                                        <div class="p-4 space-y-3 bg-gray-50 dark:bg-gray-900">
                                                                            @foreach($documentSources as $doc)
                                                                                <div class="border border-gray-200 dark:border-gray-600 rounded-lg overflow-hidden">
                                                                                    <details>
                                                                                        <summary class="px-4 py-2 cursor-pointer bg-white dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors flex items-center justify-between">
                                                                                            <span class="font-medium text-gray-800 dark:text-gray-100">
                                                                                                Document #{{ $doc['index'] ?? $loop->iteration }}
                                                                                                @if(isset($doc['metadata']['title']))
                                                                                                    - {{ \Illuminate\Support\Str::limit($doc['metadata']['title'], 50) }}
                                                                                                @elseif(isset($doc['metadata']['filename']))
                                                                                                    - {{ $doc['metadata']['filename'] }}
                                                                                                @endif
                                                                                            </span>
                                                                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-cyan-100 text-cyan-800 dark:bg-cyan-800 dark:text-cyan-100">
                                                                                                {{ $doc['score'] ?? 0 }}% pertinent
                                                                                            </span>
                                                                                        </summary>
                                                                                        <div class="p-4 bg-gray-50 dark:bg-gray-900 border-t border-gray-200 dark:border-gray-600">
                                                                                            <pre class="text-sm text-gray-800 dark:text-gray-100 whitespace-pre-wrap font-sans leading-relaxed">{{ $doc['content'] ?? '' }}</pre>
                                                                                        </div>
                                                                                    </details>
                                                                                </div>
                                                                            @endforeach
                                                                        </div>
                                                                    </details>
                                                                </div>
                                                            @endif

                                                            {{-- 4. Sources d'apprentissage --}}
                                                            @if(!empty($learnedSources))
                                                                <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600 overflow-hidden">
                                                                    <details open>
                                                                        <summary class="px-4 py-3 cursor-pointer bg-amber-50 dark:bg-amber-950 border-b border-gray-200 dark:border-gray-600 hover:bg-amber-100 dark:hover:bg-amber-900 transition-colors">
                                                                            <span class="font-semibold text-amber-700 dark:text-amber-300 flex items-center gap-2">
                                                                                <x-heroicon-o-academic-cap class="w-5 h-5" />
                                                                                4. Sources d'apprentissage ({{ count($learnedSources) }})
                                                                            </span>
                                                                        </summary>
                                                                        <div class="p-4 space-y-3 bg-gray-50 dark:bg-gray-900">
                                                                            @foreach($learnedSources as $learned)
                                                                                <div class="border border-gray-200 dark:border-gray-600 rounded-lg overflow-hidden">
                                                                                    <details>
                                                                                        <summary class="px-4 py-2 cursor-pointer bg-white dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors flex items-center justify-between">
                                                                                            <span class="font-medium text-gray-800 dark:text-gray-100">
                                                                                                Cas #{{ $learned['index'] ?? $loop->iteration }}
                                                                                            </span>
                                                                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-800 dark:text-amber-100">
                                                                                                {{ $learned['score'] ?? 0 }}% similaire
                                                                                            </span>
                                                                                        </summary>
                                                                                        <div class="p-4 bg-gray-50 dark:bg-gray-900 border-t border-gray-200 dark:border-gray-600 space-y-3">
                                                                                            <div>
                                                                                                <p class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide mb-1">Question</p>
                                                                                                <p class="text-sm text-gray-800 dark:text-gray-100">{{ $learned['question'] ?? '' }}</p>
                                                                                            </div>
                                                                                            <div>
                                                                                                <p class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide mb-1">Reponse validee</p>
                                                                                                <pre class="text-sm text-gray-800 dark:text-gray-100 whitespace-pre-wrap font-sans leading-relaxed bg-amber-50 dark:bg-amber-900/50 p-3 rounded border border-amber-200 dark:border-amber-700">{{ $learned['answer'] ?? '' }}</pre>
                                                                                            </div>
                                                                                        </div>
                                                                                    </details>
                                                                                </div>
                                                                            @endforeach
                                                                        </div>
                                                                    </details>
                                                                </div>
                                                            @endif

                                                            {{-- 5. Donnees brutes JSON --}}
                                                            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600 overflow-hidden">
                                                                <details>
                                                                    <summary class="px-4 py-3 cursor-pointer bg-gray-100 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                                                                        <span class="font-semibold text-gray-700 dark:text-gray-200 flex items-center gap-2">
                                                                            <x-heroicon-o-code-bracket class="w-5 h-5" />
                                                                            5. Donnees brutes (JSON)
                                                                        </span>
                                                                    </summary>
                                                                    <div class="p-4 bg-gray-950">
                                                                        <pre class="text-xs text-green-400 font-mono whitespace-pre-wrap overflow-x-auto">{{ json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                                                    </div>
                                                                </details>
                                                            </div>
                                                        </div>

                                                        {{-- Footer --}}
                                                        <div class="px-6 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 flex justify-end">
                                                            <button @click="open = false" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                                                                Fermer
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
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
                                                        <span class="text-gray-400 flex items-center gap-1">
                                                            {{ $message['model_used'] }}
                                                            @if(!empty($message['used_fallback_model']))
                                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-warning-100 text-warning-700 dark:bg-warning-900 dark:text-warning-300" title="Modele de fallback utilise">
                                                                    fallback
                                                                </span>
                                                            @endif
                                                        </span>
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

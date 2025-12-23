<x-filament-panels::page>
    @php
        $agent = $this->getRecord();
        $testSession = $this->getTestSession();
        $ollamaStatus = $this->ollamaStatus;
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6"
         x-data="{
            pendingMessage: null,
            isProcessing: false,
            timeoutId: null,
            elapsedTime: 0,
            timerInterval: null,
            inputMessage: @entangle('userMessage'),

            scrollToBottom() {
                this.$nextTick(() => {
                    const container = document.getElementById('chat-messages');
                    if (container) {
                        container.scrollTop = container.scrollHeight;
                    }
                });
            },

            resetState() {
                this.pendingMessage = null;
                this.isProcessing = false;
                this.elapsedTime = 0;
                if (this.timeoutId) {
                    clearTimeout(this.timeoutId);
                    this.timeoutId = null;
                }
                if (this.timerInterval) {
                    clearInterval(this.timerInterval);
                    this.timerInterval = null;
                }
                this.scrollToBottom();
            },

            sendOptimistic() {
                if (!this.inputMessage || this.inputMessage.trim() === '') return;
                if (this.isProcessing) return;

                // Afficher immédiatement le message utilisateur
                this.pendingMessage = {
                    content: this.inputMessage,
                    timestamp: new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
                };
                this.isProcessing = true;
                this.elapsedTime = 0;
                this.scrollToBottom();

                // Timer pour afficher le temps écoulé
                this.timerInterval = setInterval(() => {
                    this.elapsedTime++;
                }, 1000);

                // Vider le champ
                this.inputMessage = '';

                // Soumettre au serveur
                $wire.sendMessage();
            }
         }"
         x-on:message-received.window="resetState()"
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
                            <span class="text-success-600 dark:text-success-400">Connecté</span>
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
                            <span class="text-xs text-gray-500">Modèles disponibles:</span>
                            <ul class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                                @foreach(array_slice($ollamaStatus['models'], 0, 5) as $model)
                                    <li>• {{ $model }}</li>
                                @endforeach
                                @if(count($ollamaStatus['models']) > 5)
                                    <li class="text-gray-400">... et {{ count($ollamaStatus['models']) - 5 }} autres</li>
                                @endif
                            </ul>
                        </div>
                    @elseif(!$ollamaStatus['available'])
                        <div class="mt-2 p-2 bg-danger-50 dark:bg-danger-950 rounded text-xs text-danger-700 dark:text-danger-300">
                            <p class="font-medium">Ollama n'est pas accessible.</p>
                            <p class="mt-1">Vérifiez que le serveur est lancé ou configurez un autre provider.</p>
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
                                <span x-text="elapsedTime + 's'"></span>
                            </span>
                        </template>
                    </div>
                </x-slot>

                {{-- Messages --}}
                <div class="h-96 overflow-y-auto mb-4 space-y-4 p-4 bg-gray-50 dark:bg-gray-900 rounded-lg" id="chat-messages">
                    @forelse($messages as $message)
                        <div class="flex {{ $message['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[80%] {{ $message['role'] === 'user'
                                ? 'bg-primary-500 text-white'
                                : ($message['role'] === 'error'
                                    ? 'bg-danger-100 text-danger-800 dark:bg-danger-900 dark:text-danger-200'
                                    : 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700')
                            }} rounded-lg p-3 shadow-sm">
                                <div class="prose prose-sm dark:prose-invert max-w-none">
                                    {!! \Illuminate\Support\Str::markdown($message['content']) !!}
                                </div>
                                <div class="flex items-center justify-between mt-2 text-xs {{ $message['role'] === 'user' ? 'text-primary-200' : 'text-gray-400' }}">
                                    <span>{{ $message['timestamp'] }}</span>
                                    @if(isset($message['tokens']))
                                        <span>{{ $message['tokens'] }} tokens</span>
                                    @endif
                                </div>
                                @if(!empty($message['sources']))
                                    <div class="mt-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                                        <span class="text-xs text-gray-500">Sources:</span>
                                        <ul class="text-xs text-gray-500 mt-1">
                                            @foreach($message['sources'] as $source)
                                                <li>• {{ $source['title'] ?? $source['id'] ?? 'Document' }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        </div>
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

                    {{-- Message optimiste (affiché immédiatement) --}}
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

                    {{-- Indicateur de réflexion de l'IA --}}
                    <template x-if="isProcessing">
                        <div class="flex justify-start">
                            <div class="max-w-[80%] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-3 shadow-sm">
                                <div class="flex items-center gap-3">
                                    <div class="flex space-x-1">
                                        <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms;"></span>
                                        <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms;"></span>
                                        <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms;"></span>
                                    </div>
                                    <span class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ $agent->name }} réfléchit...
                                        <span x-show="elapsedTime > 0" x-text="'(' + elapsedTime + 's)'"></span>
                                    </span>
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

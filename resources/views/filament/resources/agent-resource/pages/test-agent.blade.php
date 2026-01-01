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

            {{-- Handoff Humain --}}
            @php $handoffInfo = $this->handoffInfo; @endphp
            <x-filament::section class="mt-4">
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-user-group class="w-5 h-5" />
                        Handoff Humain
                    </div>
                </x-slot>

                <div class="space-y-2 text-sm">
                    <div class="flex items-center gap-2">
                        @if($handoffInfo['enabled'])
                            <span class="w-2 h-2 bg-success-500 rounded-full"></span>
                            <span class="text-success-600 dark:text-success-400">Active</span>
                        @else
                            <span class="w-2 h-2 bg-gray-400 rounded-full"></span>
                            <span class="text-gray-500 dark:text-gray-400">Desactive</span>
                        @endif
                    </div>

                    @if($handoffInfo['enabled'])
                        <div class="space-y-1 text-xs text-gray-500 dark:text-gray-400">
                            <div class="flex justify-between">
                                <span>Seuil RAG</span>
                                <span class="font-medium">{{ number_format($handoffInfo['threshold'] * 100, 0) }}%</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Agents support</span>
                                <span class="font-medium">{{ $handoffInfo['support_agents_count'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Email</span>
                                <span class="font-medium {{ $handoffInfo['has_imap'] && $handoffInfo['has_smtp'] ? 'text-success-600' : 'text-warning-600' }}">
                                    @if($handoffInfo['has_imap'] && $handoffInfo['has_smtp'])
                                        Configure
                                    @elseif($handoffInfo['has_imap'] || $handoffInfo['has_smtp'])
                                        Partiel
                                    @else
                                        Non configure
                                    @endif
                                </span>
                            </div>
                        </div>

                        {{-- Statut session si escaladee --}}
                        @if($handoffInfo['session_escalated'])
                            <div class="mt-3 p-2 rounded-lg {{ match($handoffInfo['session_status']) {
                                'escalated' => 'bg-danger-50 dark:bg-danger-950 border border-danger-200 dark:border-danger-800',
                                'assigned' => 'bg-warning-50 dark:bg-warning-950 border border-warning-200 dark:border-warning-800',
                                'resolved' => 'bg-success-50 dark:bg-success-950 border border-success-200 dark:border-success-800',
                                default => 'bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700',
                            } }}">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-medium {{ match($handoffInfo['session_status']) {
                                        'escalated' => 'text-danger-700 dark:text-danger-300',
                                        'assigned' => 'text-warning-700 dark:text-warning-300',
                                        'resolved' => 'text-success-700 dark:text-success-300',
                                        default => 'text-gray-700 dark:text-gray-300',
                                    } }}">
                                        {{ match($handoffInfo['session_status']) {
                                            'escalated' => 'En attente',
                                            'assigned' => 'En cours',
                                            'resolved' => 'Resolu',
                                            'abandoned' => 'Abandonne',
                                            default => $handoffInfo['session_status'],
                                        } }}
                                    </span>
                                    @if($handoffInfo['escalated_at'])
                                        <span class="text-xs text-gray-500">{{ $handoffInfo['escalated_at'] }}</span>
                                    @endif
                                </div>
                                @if($handoffInfo['escalation_reason'])
                                    <p class="text-xs text-gray-500 mt-1">
                                        Raison: {{ match($handoffInfo['escalation_reason']) {
                                            'low_confidence' => 'Score RAG bas',
                                            'user_request' => 'Demande utilisateur',
                                            'ai_uncertainty' => 'Incertitude IA',
                                            'negative_feedback' => 'Feedback negatif',
                                            'manual_test' => 'Test manuel',
                                            default => $handoffInfo['escalation_reason'],
                                        } }}
                                    </p>
                                @endif
                            </div>
                        @endif

                        {{-- Boutons d'action --}}
                        <div class="mt-3 flex flex-col gap-2">
                            @if(!$handoffInfo['session_escalated'])
                                <button
                                    wire:click="simulateEscalation('manual_test')"
                                    type="button"
                                    class="w-full inline-flex items-center justify-center gap-2 px-3 py-2 text-xs font-medium rounded-lg bg-orange-500 text-white hover:bg-orange-600 transition-colors"
                                >
                                    <x-heroicon-o-hand-raised class="w-4 h-4" />
                                    Simuler une escalade
                                </button>
                            @else
                                <button
                                    wire:click="viewInSupport"
                                    type="button"
                                    class="w-full inline-flex items-center justify-center gap-2 px-3 py-2 text-xs font-medium rounded-lg bg-primary-500 text-white hover:bg-primary-600 transition-colors"
                                >
                                    <x-heroicon-o-eye class="w-4 h-4" />
                                    Voir dans le support
                                </button>
                            @endif
                        </div>
                    @else
                        <p class="text-xs text-gray-400 dark:text-gray-500">
                            Activez le handoff humain dans les parametres de l'agent pour tester l'escalade.
                        </p>
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

                                                // Trouver la question utilisateur precedente
                                                $userQuestion = '';
                                                for ($i = $index - 1; $i >= 0; $i--) {
                                                    if (isset($messages[$i]) && $messages[$i]['role'] === 'user') {
                                                        $userQuestion = $messages[$i]['content'];
                                                        break;
                                                    }
                                                }
                                                $aiResponse = $message['content'] ?? '';
                                            @endphp

                                            <div x-data="{
                                                open: false,
                                                copied: false,
                                                copyReport() {
                                                    const report = this.generateReport();
                                                    navigator.clipboard.writeText(report).then(() => {
                                                        this.copied = true;
                                                        setTimeout(() => this.copied = false, 2000);
                                                    });
                                                },
                                                generateReport() {
                                                    return `# Rapport d'analyse IA

## Question utilisateur
{{ addslashes($userQuestion) }}

## Reponse de l'IA
{{ addslashes($aiResponse) }}

## Contexte fourni a l'IA

### Prompt systeme
{{ addslashes($systemPrompt) }}

### Historique de conversation ({{ count($conversationHistory) }} messages)
@foreach($conversationHistory as $historyMsg)
[{{ $historyMsg['role'] }}] {{ addslashes($historyMsg['content']) }}
@endforeach

### Filtrage par categorie
@php
    $catDetect = $context['category_detection'] ?? null;
    $useCatFilter = $stats['use_category_filtering'] ?? false;
@endphp
@if(!$useCatFilter)
- Statut: Desactive pour cet agent
@elseif($catDetect)
- Methode de detection: {{ $catDetect['method'] ?? 'N/A' }}
- Confiance: {{ round(($catDetect['confidence'] ?? 0) * 100) }}%
- Categories detectees: {{ !empty($catDetect['categories']) ? implode(', ', array_column($catDetect['categories'], 'name')) : 'Aucune' }}
@if(!empty($catDetect['match_details']))
- Detail du match:
@foreach($catDetect['match_details'] as $detail)
  - [{{ $detail['type'] ?? '?' }}] "{{ $detail['in_question'] ?? '' }}" → "{{ $detail['matched'] ?? '' }}" ({{ $detail['category'] ?? '' }}){{ !empty($detail['rule']) ? ' [' . $detail['rule'] . ']' : '' }}
@endforeach
@endif
- Resultats filtres: {{ $catDetect['filtered_results_count'] ?? 0 }}
- Resultats totaux: {{ $catDetect['total_results_count'] ?? 0 }}
- Fallback utilise: {{ ($catDetect['used_fallback'] ?? false) ? 'Oui (pas assez de resultats avec le filtre)' : 'Non' }}
@else
- Statut: Active mais aucune categorie detectee
@endif

### Documents RAG ({{ count($documentSources) }} sources)
@if(($stats['response_type'] ?? '') === 'direct_qr_match')
Mode: REPONSE DIRECTE Q/R (score > 95%, pas d'appel LLM)
@endif
@foreach($documentSources as $doc)
--- Document #{{ $doc['index'] ?? $loop->iteration }} ({{ $doc['score'] ?? 0 }}% pertinent) ---
Type: {{ $doc['type'] ?? 'unknown' }}
Categorie: {{ $doc['category'] ?? $doc['metadata']['chunk_category'] ?? 'Non categorise' }}
Source: {{ $doc['source_doc'] ?? $doc['metadata']['title'] ?? 'N/A' }}
@if(!empty($doc['question']))
Question matchee: {{ addslashes($doc['question']) }}
@endif
Contenu: {{ addslashes($doc['content'] ?? '') }}
@endforeach

### Sources d'apprentissage ({{ count($learnedSources) }} cas)
@foreach($learnedSources as $learned)
--- Cas #{{ $learned['index'] ?? $loop->iteration }} ({{ $learned['score'] ?? 0 }}% similaire) ---
Q: {{ addslashes($learned['question'] ?? '') }}
R: {{ addslashes($learned['answer'] ?? '') }}
@endforeach

## Handoff Humain
@php
    $hAgentHandoff = $agent->human_support_enabled ?? false;
    $hAgentThreshold = $agent->escalation_threshold ?? 0.3;
    $hMaxRagScore = $stats['max_rag_score'] ?? null;
    $hWouldEscalate = $hAgentHandoff && $hMaxRagScore !== null && $hMaxRagScore < $hAgentThreshold;
@endphp
- Handoff active: {{ $hAgentHandoff ? 'Oui' : 'Non' }}
@if($hAgentHandoff)
- Seuil d'escalade: {{ number_format($hAgentThreshold * 100, 0) }}%
- Score RAG max: {{ $hMaxRagScore !== null ? number_format($hMaxRagScore * 100, 1) . '%' : 'N/A' }}
- Escalade declenchee: {{ $hWouldEscalate ? 'OUI - Score RAG insuffisant' : 'Non' }}
- Agents de support: {{ $agent->supportUsers()->count() }}
- Email IMAP: {{ $agent->hasImapConfig() ? 'Configure' : 'Non configure' }}
- Email SMTP: {{ $agent->hasSmtpConfig() ? 'Configure' : 'Non configure' }}
@if($agent->support_email)
- Email de support: {{ $agent->support_email }}
@endif
@endif

## Informations techniques
- Modele: {{ $message['model_used'] ?? 'Non specifie' }}
- Type de reponse: {{ ($stats['response_type'] ?? '') === 'direct_qr_match' ? 'DIRECT Q/R (sans appel LLM)' : 'Generation LLM' }}
- Tokens: {{ $message['tokens'] ?? 'N/A' }}
- Temps de generation: {{ isset($message['generation_time_ms']) ? number_format($message['generation_time_ms'] / 1000, 1) . 's' : 'N/A' }}
- Fallback: {{ !empty($message['used_fallback_model']) ? 'Oui' : 'Non' }}
- Filtrage par categorie: {{ $useCatFilter ? 'Active' : 'Desactive' }}
@if(isset($stats['direct_qr_threshold']))
- Seuil reponse directe: {{ $stats['direct_qr_threshold'] * 100 }}%
@endif`;
                                                }
                                            }">
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
                                                                {{-- 0. Question et Reponse --}}
                                                                <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600 overflow-hidden">
                                                                    <details open>
                                                                        <summary class="px-4 py-3 cursor-pointer bg-rose-50 dark:bg-rose-950 border-b border-gray-200 dark:border-gray-600 hover:bg-rose-100 dark:hover:bg-rose-900 transition-colors">
                                                                            <span class="font-semibold text-rose-700 dark:text-rose-300 flex items-center gap-2">
                                                                                <x-heroicon-o-chat-bubble-left-right class="w-5 h-5" />
                                                                                0. Question et Reponse
                                                                            </span>
                                                                        </summary>
                                                                        <div class="p-4 space-y-4 bg-gray-50 dark:bg-gray-900">
                                                                            <div>
                                                                                <p class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide mb-2">Question utilisateur</p>
                                                                                <div class="p-3 bg-blue-50 dark:bg-blue-900/30 rounded-lg border border-blue-200 dark:border-blue-700">
                                                                                    <p class="text-sm text-gray-800 dark:text-gray-100">{{ $userQuestion }}</p>
                                                                                </div>
                                                                            </div>
                                                                            <div>
                                                                                <p class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide mb-2">Reponse de l'IA</p>
                                                                                <div class="p-3 bg-gray-100 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600">
                                                                                    <div class="prose prose-sm dark:prose-invert max-w-none">
                                                                                        {!! \Illuminate\Support\Str::markdown($aiResponse) !!}
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </details>
                                                                </div>

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
                                                                                                <p class="text-sm text-gray-800 dark:text-gray-100 whitespace-pre-wrap">{!! nl2br(e(\Illuminate\Support\Str::limit($historyMsg['content'], 300))) !!}</p>
                                                                                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $historyMsg['timestamp'] ?? '' }}</p>
                                                                                            </div>
                                                                                        </div>
                                                                                    </div>
                                                                                @endforeach
                                                                            </div>
                                                                        </details>
                                                                    </div>
                                                                @endif

                                                                {{-- 2.5 Détection de catégorie --}}
                                                                @php
                                                                    $categoryDetection = $context['category_detection'] ?? null;
                                                                    $useCategoryFiltering = $stats['use_category_filtering'] ?? false;
                                                                @endphp
                                                                <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600 overflow-hidden">
                                                                    <details {{ $categoryDetection ? 'open' : '' }}>
                                                                        <summary class="px-4 py-3 cursor-pointer bg-pink-50 dark:bg-pink-950 border-b border-gray-200 dark:border-gray-600 hover:bg-pink-100 dark:hover:bg-pink-900 transition-colors">
                                                                            <span class="font-semibold text-pink-700 dark:text-pink-300 flex items-center gap-2">
                                                                                <x-heroicon-o-funnel class="w-5 h-5" />
                                                                                2.5 Filtrage par catégorie
                                                                                @if(!$useCategoryFiltering)
                                                                                    <span class="text-xs font-normal text-gray-500 dark:text-gray-400">(désactivé)</span>
                                                                                @elseif($categoryDetection && !empty($categoryDetection['categories']))
                                                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-pink-100 text-pink-800 dark:bg-pink-800 dark:text-pink-100">
                                                                                        {{ count($categoryDetection['categories']) }} catégorie(s) détectée(s)
                                                                                    </span>
                                                                                @else
                                                                                    <span class="text-xs font-normal text-gray-500 dark:text-gray-400">(aucune catégorie détectée)</span>
                                                                                @endif
                                                                            </span>
                                                                        </summary>
                                                                        <div class="p-4 bg-gray-50 dark:bg-gray-900 space-y-4">
                                                                            @if(!$useCategoryFiltering)
                                                                                <div class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                                                                                    <x-heroicon-o-information-circle class="w-5 h-5" />
                                                                                    <span class="text-sm">Le filtrage par catégorie n'est pas activé pour cet agent.</span>
                                                                                </div>
                                                                                <p class="text-xs text-gray-400 dark:text-gray-500">
                                                                                    Activez cette option dans les paramètres de l'agent pour améliorer la précision des résultats RAG en détectant automatiquement la catégorie de la question.
                                                                                </p>
                                                                            @elseif($categoryDetection)
                                                                                <div class="grid grid-cols-2 gap-4 text-sm">
                                                                                    <div>
                                                                                        <p class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide mb-1">Méthode de détection</p>
                                                                                        <span class="inline-flex items-center px-2 py-1 rounded text-sm font-medium {{ $categoryDetection['method'] === 'keyword' ? 'bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100' : ($categoryDetection['method'] === 'embedding' ? 'bg-blue-100 text-blue-800 dark:bg-blue-800 dark:text-blue-100' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200') }}">
                                                                                            @if($categoryDetection['method'] === 'keyword')
                                                                                                <x-heroicon-o-key class="w-4 h-4 mr-1" /> Mot-clé
                                                                                            @elseif($categoryDetection['method'] === 'embedding')
                                                                                                <x-heroicon-o-sparkles class="w-4 h-4 mr-1" /> Embedding
                                                                                            @else
                                                                                                Aucune
                                                                                            @endif
                                                                                        </span>
                                                                                    </div>
                                                                                    <div>
                                                                                        <p class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide mb-1">Confiance</p>
                                                                                        <span class="text-lg font-bold {{ ($categoryDetection['confidence'] ?? 0) >= 0.7 ? 'text-green-600 dark:text-green-400' : (($categoryDetection['confidence'] ?? 0) >= 0.4 ? 'text-yellow-600 dark:text-yellow-400' : 'text-red-600 dark:text-red-400') }}">
                                                                                            {{ round(($categoryDetection['confidence'] ?? 0) * 100) }}%
                                                                                        </span>
                                                                                    </div>
                                                                                </div>

                                                                                @if(!empty($categoryDetection['categories']))
                                                                                    <div>
                                                                                        <p class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide mb-2">Catégories détectées</p>
                                                                                        <div class="flex flex-wrap gap-2">
                                                                                            @foreach($categoryDetection['categories'] as $cat)
                                                                                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-pink-100 text-pink-800 dark:bg-pink-800 dark:text-pink-100">
                                                                                                    {{ $cat['name'] ?? 'Inconnu' }}
                                                                                                </span>
                                                                                            @endforeach
                                                                                        </div>
                                                                                    </div>
                                                                                @endif

                                                                                {{-- Détails du match --}}
                                                                                @if(!empty($categoryDetection['match_details']))
                                                                                    <div>
                                                                                        <p class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide mb-2">Détail du matching</p>
                                                                                        <div class="space-y-2">
                                                                                            @foreach($categoryDetection['match_details'] as $detail)
                                                                                                <div class="flex items-center gap-2 text-sm bg-gray-100 dark:bg-gray-800 px-3 py-2 rounded-lg">
                                                                                                    @php
                                                                                                        $typeLabel = match($detail['type'] ?? '') {
                                                                                                            'exact' => ['label' => 'Exact', 'color' => 'green'],
                                                                                                            'exact_word' => ['label' => 'Mot exact', 'color' => 'green'],
                                                                                                            'stemming' => ['label' => 'Stemming', 'color' => 'blue'],
                                                                                                            'root_match' => ['label' => 'Racine', 'color' => 'purple'],
                                                                                                            'embedding' => ['label' => 'Embedding', 'color' => 'orange'],
                                                                                                            default => ['label' => $detail['type'] ?? '?', 'color' => 'gray'],
                                                                                                        };
                                                                                                    @endphp
                                                                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-{{ $typeLabel['color'] }}-100 text-{{ $typeLabel['color'] }}-800 dark:bg-{{ $typeLabel['color'] }}-800 dark:text-{{ $typeLabel['color'] }}-100">
                                                                                                        {{ $typeLabel['label'] }}
                                                                                                    </span>
                                                                                                    <span class="text-gray-600 dark:text-gray-300">
                                                                                                        "<strong class="text-pink-600 dark:text-pink-400">{{ $detail['in_question'] ?? '' }}</strong>"
                                                                                                        →
                                                                                                        "<strong class="text-green-600 dark:text-green-400">{{ $detail['matched'] ?? '' }}</strong>"
                                                                                                        ({{ $detail['category'] ?? '' }})
                                                                                                    </span>
                                                                                                    @if(!empty($detail['rule']))
                                                                                                        <span class="text-xs text-gray-400">[{{ $detail['rule'] }}]</span>
                                                                                                    @endif
                                                                                                </div>
                                                                                            @endforeach
                                                                                        </div>
                                                                                    </div>
                                                                                @endif

                                                                                <div class="grid grid-cols-3 gap-4 pt-3 border-t border-gray-200 dark:border-gray-700">
                                                                                    <div class="text-center">
                                                                                        <p class="text-xs text-gray-500 dark:text-gray-400">Résultats filtrés</p>
                                                                                        <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $categoryDetection['filtered_results_count'] ?? 0 }}</p>
                                                                                    </div>
                                                                                    <div class="text-center">
                                                                                        <p class="text-xs text-gray-500 dark:text-gray-400">Résultats totaux</p>
                                                                                        <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $categoryDetection['total_results_count'] ?? 0 }}</p>
                                                                                    </div>
                                                                                    <div class="text-center">
                                                                                        <p class="text-xs text-gray-500 dark:text-gray-400">Fallback utilisé</p>
                                                                                        <p class="text-lg font-bold {{ ($categoryDetection['used_fallback'] ?? false) ? 'text-orange-600 dark:text-orange-400' : 'text-green-600 dark:text-green-400' }}">
                                                                                            {{ ($categoryDetection['used_fallback'] ?? false) ? 'Oui' : 'Non' }}
                                                                                        </p>
                                                                                    </div>
                                                                                </div>

                                                                                @if($categoryDetection['used_fallback'] ?? false)
                                                                                    <div class="p-3 bg-orange-50 dark:bg-orange-900/30 rounded-lg border border-orange-200 dark:border-orange-700">
                                                                                        <p class="text-sm text-orange-700 dark:text-orange-300">
                                                                                            <x-heroicon-o-exclamation-triangle class="w-4 h-4 inline mr-1" />
                                                                                            Pas assez de résultats avec le filtre catégorie. Une recherche sans filtre a été ajoutée pour compléter.
                                                                                        </p>
                                                                                    </div>
                                                                                @endif
                                                                            @else
                                                                                <div class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                                                                                    <x-heroicon-o-magnifying-glass class="w-5 h-5" />
                                                                                    <span class="text-sm">Aucune catégorie n'a pu être détectée dans la question.</span>
                                                                                </div>
                                                                            @endif
                                                                        </div>
                                                                    </details>
                                                                </div>

                                                                {{-- 3. Documents indexes (RAG) --}}
                                                                @if(!empty($documentSources))
                                                                    @php
                                                                        $problematicCount = collect($documentSources)->filter(fn($d) => empty(trim($d['content'] ?? '')) && ($d['score'] ?? 0) > 50)->count();
                                                                    @endphp
                                                                    <div class="bg-white dark:bg-gray-800 rounded-lg border {{ $problematicCount > 0 ? 'border-red-300 dark:border-red-700' : 'border-gray-200 dark:border-gray-600' }} overflow-hidden">
                                                                        <details open>
                                                                            <summary class="px-4 py-3 cursor-pointer {{ $problematicCount > 0 ? 'bg-red-50 dark:bg-red-950' : 'bg-cyan-50 dark:bg-cyan-950' }} border-b border-gray-200 dark:border-gray-600 hover:bg-cyan-100 dark:hover:bg-cyan-900 transition-colors">
                                                                                <span class="font-semibold {{ $problematicCount > 0 ? 'text-red-700 dark:text-red-300' : 'text-cyan-700 dark:text-cyan-300' }} flex items-center gap-2">
                                                                                    <x-heroicon-o-document-text class="w-5 h-5" />
                                                                                    3. Documents indexes - RAG ({{ count($documentSources) }})
                                                                                    @if(($stats['response_type'] ?? '') === 'direct_qr_match')
                                                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100">
                                                                                            Réponse directe Q/R
                                                                                        </span>
                                                                                    @endif
                                                                                    @if($problematicCount > 0)
                                                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-500 text-white">
                                                                                            {{ $problematicCount }} source(s) vide(s)
                                                                                        </span>
                                                                                    @endif
                                                                                </span>
                                                                            </summary>
                                                                            <div class="p-4 space-y-3 bg-gray-50 dark:bg-gray-900">
                                                                                @if($problematicCount > 0)
                                                                                    <div class="p-3 mb-3 bg-red-50 dark:bg-red-900/30 rounded-lg border border-red-200 dark:border-red-700">
                                                                                        <div class="flex items-start gap-2">
                                                                                            <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" />
                                                                                            <div>
                                                                                                <p class="font-medium text-red-700 dark:text-red-300">
                                                                                                    Problème de données détecté
                                                                                                </p>
                                                                                                <p class="text-sm text-red-600 dark:text-red-400 mt-1">
                                                                                                    {{ $problematicCount }} document(s) ont été retournés par Qdrant avec un score élevé mais sans contenu.
                                                                                                    Cela peut expliquer des réponses de mauvaise qualité.
                                                                                                </p>
                                                                                                <p class="text-xs text-red-500 dark:text-red-400 mt-2">
                                                                                                    <strong>Solution:</strong> Réindexez les documents sources ou nettoyez la collection Qdrant de cet agent.
                                                                                                </p>
                                                                                            </div>
                                                                                        </div>
                                                                                    </div>
                                                                                @endif
                                                                                @foreach($documentSources as $doc)
                                                                                    @php
                                                                                        $docCategory = $doc['category'] ?? $doc['metadata']['chunk_category'] ?? $doc['metadata']['category'] ?? null;
                                                                                        $docTitle = $doc['source_doc'] ?? $doc['metadata']['title'] ?? $doc['metadata']['filename'] ?? null;
                                                                                        $docType = $doc['type'] ?? 'unknown';
                                                                                        $docQuestion = $doc['question'] ?? null;
                                                                                    @endphp
                                                                                    <div class="border border-gray-200 dark:border-gray-600 rounded-lg overflow-hidden">
                                                                                        <details {{ $loop->first ? 'open' : '' }}>
                                                                                            @php
                                                                                                        $hasEmptyContent = empty(trim($doc['content'] ?? ''));
                                                                                                        $hasEmptyQuestion = empty(trim($docQuestion ?? ''));
                                                                                                        $isProblematic = $hasEmptyContent && ($doc['score'] ?? 0) > 50;
                                                                                                    @endphp
                                                                                            <summary class="px-4 py-2 cursor-pointer {{ $isProblematic ? 'bg-red-50 dark:bg-red-950' : 'bg-white dark:bg-gray-800' }} hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors flex items-center justify-between">
                                                                                                <span class="font-medium text-gray-800 dark:text-gray-100 flex items-center gap-2 flex-wrap">
                                                                                                    Document #{{ $doc['index'] ?? $loop->iteration }}
                                                                                                    @if($docTitle)
                                                                                                        - {{ \Illuminate\Support\Str::limit($docTitle, 50) }}
                                                                                                    @endif
                                                                                                    @if($docType === 'qa_pair')
                                                                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-800 dark:text-blue-100">
                                                                                                            Q/R
                                                                                                        </span>
                                                                                                    @elseif($docType === 'faq')
                                                                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-800 dark:text-amber-100">
                                                                                                            FAQ
                                                                                                        </span>
                                                                                                    @elseif($docType === 'source_material')
                                                                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200">
                                                                                                            Source
                                                                                                        </span>
                                                                                                    @endif
                                                                                                    @if($docCategory)
                                                                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-pink-100 text-pink-800 dark:bg-pink-800 dark:text-pink-100">
                                                                                                            {{ $docCategory }}
                                                                                                        </span>
                                                                                                    @endif
                                                                                                    @if($isProblematic)
                                                                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-500 text-white animate-pulse" title="Ce document a un contenu vide mais un score élevé - problème de données">
                                                                                                            <x-heroicon-o-exclamation-triangle class="w-3 h-3 mr-1" />
                                                                                                            Contenu vide!
                                                                                                        </span>
                                                                                                    @endif
                                                                                                </span>
                                                                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $isProblematic ? 'bg-red-100 text-red-800 dark:bg-red-800 dark:text-red-100' : 'bg-cyan-100 text-cyan-800 dark:bg-cyan-800 dark:text-cyan-100' }}">
                                                                                                    {{ $doc['score'] ?? 0 }}% pertinent
                                                                                                </span>
                                                                                            </summary>
                                                                                            <div class="p-4 bg-gray-50 dark:bg-gray-900 border-t border-gray-200 dark:border-gray-600 space-y-3">
                                                                                                @if($docQuestion)
                                                                                                    <div class="p-2 bg-blue-50 dark:bg-blue-900/30 rounded border border-blue-200 dark:border-blue-700">
                                                                                                        <p class="text-xs font-semibold text-blue-600 dark:text-blue-300 uppercase tracking-wide mb-1">Question matchée</p>
                                                                                                        <p class="text-sm text-blue-800 dark:text-blue-100">{{ $docQuestion }}</p>
                                                                                                    </div>
                                                                                                @endif
                                                                                                @if(isset($doc['metadata']['summary']))
                                                                                                    <div class="p-2 bg-purple-50 dark:bg-purple-900/30 rounded border border-purple-200 dark:border-purple-700">
                                                                                                        <p class="text-xs font-semibold text-purple-600 dark:text-purple-300 uppercase tracking-wide mb-1">Résumé</p>
                                                                                                        <p class="text-sm text-purple-800 dark:text-purple-100">{{ $doc['metadata']['summary'] }}</p>
                                                                                                    </div>
                                                                                                @endif
                                                                                                <div>
                                                                                                    <p class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide mb-1">Contenu / Réponse</p>
                                                                                                    @if($hasEmptyContent)
                                                                                                        <div class="p-4 bg-red-50 dark:bg-red-900/30 rounded border-2 border-dashed border-red-300 dark:border-red-700">
                                                                                                            <div class="flex items-center gap-2 text-red-700 dark:text-red-300">
                                                                                                                <x-heroicon-o-exclamation-triangle class="w-5 h-5" />
                                                                                                                <span class="font-medium">Contenu vide ou null</span>
                                                                                                            </div>
                                                                                                            <p class="mt-2 text-sm text-red-600 dark:text-red-400">
                                                                                                                Ce document est retourné avec un score de {{ $doc['score'] ?? 0 }}% mais ne contient pas de données.
                                                                                                                Cela indique un problème lors de l'indexation des documents.
                                                                                                            </p>
                                                                                                            <div class="mt-3 text-xs text-red-500 dark:text-red-400 space-y-1">
                                                                                                                <p><strong>ID Qdrant:</strong> {{ $doc['id'] ?? 'N/A' }}</p>
                                                                                                                <p><strong>Type:</strong> {{ $docType }}</p>
                                                                                                                <p><strong>Actions suggérées:</strong> Réindexer le document source ou supprimer ce point dans Qdrant.</p>
                                                                                                            </div>
                                                                                                        </div>
                                                                                                    @else
                                                                                                        <pre class="text-sm text-gray-800 dark:text-gray-100 whitespace-pre-wrap font-sans leading-relaxed bg-white dark:bg-gray-800 p-3 rounded border border-gray-200 dark:border-gray-600">{{ $doc['content'] }}</pre>
                                                                                                    @endif
                                                                                                </div>
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

                                                                {{-- 5. Handoff Humain --}}
                                                                @php
                                                                    $handoffConfig = $context['handoff_config'] ?? null;
                                                                    $escalationInfo = $context['escalation_info'] ?? null;
                                                                    $agentHandoff = $agent->human_support_enabled ?? false;
                                                                    $agentThreshold = $agent->escalation_threshold ?? 0.3;
                                                                    $maxRagScore = $stats['max_rag_score'] ?? ($escalationInfo['max_rag_score'] ?? null);
                                                                    $wouldEscalate = $agentHandoff && $maxRagScore !== null && $maxRagScore < $agentThreshold;
                                                                @endphp
                                                                <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600 overflow-hidden">
                                                                    <details {{ $wouldEscalate ? 'open' : '' }}>
                                                                        <summary class="px-4 py-3 cursor-pointer bg-orange-50 dark:bg-orange-950 border-b border-gray-200 dark:border-gray-600 hover:bg-orange-100 dark:hover:bg-orange-900 transition-colors">
                                                                            <span class="font-semibold text-orange-700 dark:text-orange-300 flex items-center gap-2">
                                                                                <x-heroicon-o-user-group class="w-5 h-5" />
                                                                                5. Handoff Humain
                                                                                @if(!$agentHandoff)
                                                                                    <span class="text-xs font-normal text-gray-500 dark:text-gray-400">(desactive)</span>
                                                                                @elseif($wouldEscalate)
                                                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-danger-100 text-danger-800 dark:bg-danger-800 dark:text-danger-100 animate-pulse">
                                                                                        Escalade declenchee
                                                                                    </span>
                                                                                @else
                                                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-success-100 text-success-800 dark:bg-success-800 dark:text-success-100">
                                                                                        Pas d'escalade
                                                                                    </span>
                                                                                @endif
                                                                            </span>
                                                                        </summary>
                                                                        <div class="p-4 bg-gray-50 dark:bg-gray-900 space-y-4">
                                                                            @if(!$agentHandoff)
                                                                                <div class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                                                                                    <x-heroicon-o-information-circle class="w-5 h-5" />
                                                                                    <span class="text-sm">Le handoff humain n'est pas active pour cet agent.</span>
                                                                                </div>
                                                                            @else
                                                                                {{-- Configuration --}}
                                                                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                                                                    <div class="text-center p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600">
                                                                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Seuil d'escalade</p>
                                                                                        <p class="text-lg font-bold text-orange-600 dark:text-orange-400">{{ number_format($agentThreshold * 100, 0) }}%</p>
                                                                                    </div>
                                                                                    <div class="text-center p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600">
                                                                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Score RAG max</p>
                                                                                        <p class="text-lg font-bold {{ $maxRagScore !== null && $maxRagScore < $agentThreshold ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">
                                                                                            {{ $maxRagScore !== null ? number_format($maxRagScore * 100, 0) . '%' : 'N/A' }}
                                                                                        </p>
                                                                                    </div>
                                                                                    <div class="text-center p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600">
                                                                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Agents support</p>
                                                                                        <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $agent->supportUsers()->count() }}</p>
                                                                                    </div>
                                                                                    <div class="text-center p-3 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600">
                                                                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Email</p>
                                                                                        <p class="text-lg font-bold {{ $agent->hasImapConfig() && $agent->hasSmtpConfig() ? 'text-success-600' : 'text-warning-600' }}">
                                                                                            {{ $agent->hasImapConfig() && $agent->hasSmtpConfig() ? 'OK' : 'Partiel' }}
                                                                                        </p>
                                                                                    </div>
                                                                                </div>

                                                                                {{-- Decision d'escalade --}}
                                                                                @if($wouldEscalate)
                                                                                    <div class="p-4 bg-danger-50 dark:bg-danger-900/30 rounded-lg border border-danger-200 dark:border-danger-700">
                                                                                        <div class="flex items-start gap-3">
                                                                                            <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-danger-500 flex-shrink-0" />
                                                                                            <div>
                                                                                                <p class="font-semibold text-danger-700 dark:text-danger-300">Escalade declenchee</p>
                                                                                                <p class="text-sm text-danger-600 dark:text-danger-400 mt-1">
                                                                                                    Le score RAG maximum ({{ number_format($maxRagScore * 100, 0) }}%) est inferieur au seuil d'escalade ({{ number_format($agentThreshold * 100, 0) }}%).
                                                                                                    En production, cette conversation serait transferee au support humain.
                                                                                                </p>
                                                                                                <p class="text-xs text-danger-500 dark:text-danger-500 mt-2">
                                                                                                    Raison: Score RAG insuffisant ({{ number_format($maxRagScore * 100, 1) }}% &lt; {{ number_format($agentThreshold * 100, 0) }}%)
                                                                                                </p>
                                                                                            </div>
                                                                                        </div>
                                                                                    </div>
                                                                                @else
                                                                                    <div class="p-4 bg-success-50 dark:bg-success-900/30 rounded-lg border border-success-200 dark:border-success-700">
                                                                                        <div class="flex items-start gap-3">
                                                                                            <x-heroicon-o-check-circle class="w-6 h-6 text-success-500 flex-shrink-0" />
                                                                                            <div>
                                                                                                <p class="font-semibold text-success-700 dark:text-success-300">Pas d'escalade necessaire</p>
                                                                                                <p class="text-sm text-success-600 dark:text-success-400 mt-1">
                                                                                                    @if($maxRagScore !== null)
                                                                                                        Le score RAG ({{ number_format($maxRagScore * 100, 0) }}%) est superieur au seuil ({{ number_format($agentThreshold * 100, 0) }}%).
                                                                                                    @else
                                                                                                        L'IA a repondu avec suffisamment de confiance.
                                                                                                    @endif
                                                                                                </p>
                                                                                            </div>
                                                                                        </div>
                                                                                    </div>
                                                                                @endif

                                                                                {{-- Email support --}}
                                                                                @if($agent->support_email)
                                                                                    <div class="text-sm">
                                                                                        <span class="text-gray-500 dark:text-gray-400">Email de support:</span>
                                                                                        <span class="font-medium text-gray-900 dark:text-white ml-2">{{ $agent->support_email }}</span>
                                                                                    </div>
                                                                                @endif
                                                                            @endif
                                                                        </div>
                                                                    </details>
                                                                </div>

                                                                {{-- 6. Donnees brutes JSON --}}
                                                                <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600 overflow-hidden">
                                                                    <details>
                                                                        <summary class="px-4 py-3 cursor-pointer bg-gray-100 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                                                                            <span class="font-semibold text-gray-700 dark:text-gray-200 flex items-center gap-2">
                                                                                <x-heroicon-o-code-bracket class="w-5 h-5" />
                                                                                6. Donnees brutes (JSON)
                                                                            </span>
                                                                        </summary>
                                                                        <div class="p-4 bg-gray-950">
                                                                            <pre class="text-xs text-green-400 font-mono whitespace-pre-wrap overflow-x-auto">{{ json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                                                        </div>
                                                                    </details>
                                                                </div>

                                                                {{-- 7. Rapport pour analyse --}}
                                                                <div class="bg-white dark:bg-gray-800 rounded-lg border-2 border-indigo-300 dark:border-indigo-600 overflow-hidden">
                                                                    <details>
                                                                        <summary class="px-4 py-3 cursor-pointer bg-indigo-50 dark:bg-indigo-950 border-b border-gray-200 dark:border-gray-600 hover:bg-indigo-100 dark:hover:bg-indigo-900 transition-colors">
                                                                            <span class="font-semibold text-indigo-700 dark:text-indigo-300 flex items-center gap-2">
                                                                                <x-heroicon-o-clipboard-document class="w-5 h-5" />
                                                                                7. Rapport pour analyse (copier pour Claude)
                                                                            </span>
                                                                        </summary>
                                                                        <div class="p-4 bg-gray-50 dark:bg-gray-900">
                                                                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                                                                                Cliquez sur le bouton ci-dessous pour copier un rapport complet que vous pouvez envoyer a Claude ou un autre LLM pour analyser pourquoi l'IA n'a pas bien repondu.
                                                                            </p>
                                                                            <button
                                                                                @click="copyReport()"
                                                                                type="button"
                                                                                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition-colors"
                                                                            >
                                                                                <template x-if="!copied">
                                                                                    <span class="flex items-center gap-2">
                                                                                        <x-heroicon-o-clipboard-document class="w-4 h-4" />
                                                                                        Copier le rapport complet
                                                                                    </span>
                                                                                </template>
                                                                                <template x-if="copied">
                                                                                    <span class="flex items-center gap-2">
                                                                                        <x-heroicon-o-check class="w-4 h-4" />
                                                                                        Copie !
                                                                                    </span>
                                                                                </template>
                                                                            </button>
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

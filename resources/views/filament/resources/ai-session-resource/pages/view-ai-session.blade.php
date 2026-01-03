<x-filament-panels::page>
    @php
        $session = $this->record;
        $unifiedMessages = $this->getUnifiedMessages();
        $stats = $this->getSessionStats();
        $canHandleSupport = $this->canHandleSupport();
        $isAssignedAgent = $this->isAssignedAgent();
        $isEscalated = $session->isEscalated();
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        {{-- Sidebar: Infos Session --}}
        <div class="lg:col-span-1 space-y-4">

            {{-- Section Support Humain (si escaladé) --}}
            @if($isEscalated)
                <x-filament::section class="border-2 {{ match($session->support_status) {
                    'escalated' => 'border-danger-500',
                    'assigned' => 'border-warning-500',
                    'resolved' => 'border-success-500',
                    default => 'border-gray-300'
                } }}">
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-user-group class="w-5 h-5" />
                            Support Humain
                        </div>
                    </x-slot>

                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-500 dark:text-gray-400">Statut</span>
                            <x-filament::badge color="{{ match($session->support_status) {
                                'escalated' => 'danger',
                                'assigned' => 'warning',
                                'resolved' => 'success',
                                'abandoned' => 'gray',
                                default => 'gray'
                            } }}">
                                {{ match($session->support_status) {
                                    'escalated' => 'En attente',
                                    'assigned' => 'En cours',
                                    'resolved' => 'Résolu',
                                    'abandoned' => 'Abandonné',
                                    default => $session->support_status
                                } }}
                            </x-filament::badge>
                        </div>

                        <div class="flex justify-between">
                            <span class="text-gray-500 dark:text-gray-400">Raison</span>
                            <span class="font-medium text-right text-xs">
                                {{ match($session->escalation_reason) {
                                    'low_confidence' => 'Score RAG bas',
                                    'user_request' => 'Demande utilisateur',
                                    'user_explicit_request' => 'Demande explicite',
                                    'ai_handoff_request' => 'Demande IA',
                                    'ai_uncertainty' => 'Incertitude IA',
                                    'negative_feedback' => 'Feedback négatif',
                                    default => $session->escalation_reason ?? '-'
                                } }}
                            </span>
                        </div>

                        @if($session->escalated_at)
                            <div class="flex justify-between">
                                <span class="text-gray-500 dark:text-gray-400">Escaladé le</span>
                                <span class="font-medium">{{ $session->escalated_at->format('d/m H:i') }}</span>
                            </div>
                        @endif

                        @if($session->supportAgent)
                            <div class="flex justify-between">
                                <span class="text-gray-500 dark:text-gray-400">Agent</span>
                                <span class="font-medium">{{ $session->supportAgent->name }}</span>
                            </div>
                        @endif

                        @if($session->user_email)
                            <div class="flex justify-between">
                                <span class="text-gray-500 dark:text-gray-400">Email</span>
                                <span class="font-medium text-xs">{{ $session->user_email }}</span>
                            </div>
                        @endif
                    </div>
                </x-filament::section>
            @endif

            {{-- Infos Session --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-information-circle class="w-5 h-5" />
                        Informations
                    </div>
                </x-slot>

                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Agent</span>
                        <span class="font-medium">{{ $session->agent?->name ?? '-' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Utilisateur</span>
                        <span class="font-medium">{{ $session->user?->name ?? 'Visiteur' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Source</span>
                        <x-filament::badge color="{{ match($session->external_context['source'] ?? '') {
                            'admin_test' => 'warning',
                            'api' => 'info',
                            'public_link' => 'success',
                            default => 'gray'
                        } }}">
                            {{ $session->external_context['source'] ?? 'unknown' }}
                        </x-filament::badge>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Créé le</span>
                        <span class="font-medium">{{ $session->created_at->format('d/m/Y H:i') }}</span>
                    </div>
                </div>
            </x-filament::section>

            {{-- Stats --}}
            <x-filament::section collapsible collapsed>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-chart-bar class="w-5 h-5" />
                        Statistiques
                    </div>
                </x-slot>

                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Messages</span>
                        <span class="font-medium">{{ $stats['total_messages'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Tokens</span>
                        <span class="font-medium">{{ number_format($stats['total_tokens']) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Temps moyen</span>
                        <span class="font-medium">{{ $stats['avg_generation_time'] }}ms</span>
                    </div>
                </div>
            </x-filament::section>

            {{-- Légende des couleurs --}}
            <x-filament::section collapsible collapsed>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-swatch class="w-5 h-5" />
                        Légende
                    </div>
                </x-slot>

                <div class="space-y-2 text-xs">
                    <div class="flex items-center gap-2">
                        <div class="w-3 h-3 rounded-full bg-primary-500"></div>
                        <span>Client</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-3 h-3 rounded-full bg-gray-400"></div>
                        <span>Assistant IA</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-3 h-3 rounded-full bg-success-500"></div>
                        <span>Agent Support</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-3 h-3 rounded-full bg-gray-300"></div>
                        <span>Système</span>
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- Main: Conversation Unifiée --}}
        <div class="lg:col-span-3">
            <x-filament::section class="h-full">
                <x-slot name="heading">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-chat-bubble-left-right class="w-5 h-5" />
                            Conversation
                            @if($isEscalated)
                                <x-filament::badge color="warning" size="sm">Support actif</x-filament::badge>
                            @endif
                        </div>
                        <span class="text-sm text-gray-500">{{ count($unifiedMessages) }} messages</span>
                    </div>
                </x-slot>

                {{-- Zone de messages unifiée --}}
                <div class="h-[600px] overflow-y-auto space-y-4 p-4 bg-gray-50 dark:bg-gray-900 rounded-lg" id="chat-messages">
                    @forelse($unifiedMessages as $index => $message)
                        @php
                            $isClient = $message['type'] === 'client';
                            $isAi = $message['type'] === 'ai';
                            $isSupport = $message['type'] === 'support';
                            $isSystem = $message['type'] === 'system';
                        @endphp

                        {{-- Message Système (centré) --}}
                        @if($isSystem)
                            <div class="flex justify-center">
                                <div class="px-4 py-2 bg-gray-200 dark:bg-gray-700 rounded-full text-xs text-gray-600 dark:text-gray-400">
                                    {{ $message['content'] }}
                                    <span class="ml-2 opacity-75">{{ $message['created_at']->format('H:i') }}</span>
                                </div>
                            </div>

                        {{-- Message Client (droite, bleu) --}}
                        @elseif($isClient)
                            <div class="flex justify-end">
                                <div style="max-width: 75%;">
                                    <div class="bg-primary-500 text-white rounded-lg p-3 shadow-sm">
                                        <div class="prose prose-sm prose-invert max-w-none">
                                            {!! \Illuminate\Support\Str::markdown($message['content']) !!}
                                        </div>
                                        <div class="flex items-center justify-between mt-2 text-xs text-primary-200">
                                            <span>{{ $message['sender_name'] }}</span>
                                            <span>{{ $message['created_at']->format('H:i') }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        {{-- Message IA (gauche, gris) - NOUVELLE UX UNIFIÉE --}}
                        @elseif($isAi)
                            @php
                                // ═══════════════════════════════════════════════════════════════
                                // PRÉPARATION DES DONNÉES - TEMPLATE UNIQUE MONO/MULTI
                                // ═══════════════════════════════════════════════════════════════

                                // Récupérer la question utilisateur précédente
                                $previousQuestion = '';
                                if (isset($unifiedMessages[$index - 1]) && $unifiedMessages[$index - 1]['type'] === 'client') {
                                    $previousQuestion = $unifiedMessages[$index - 1]['content'] ?? '';
                                }

                                // Détecter si multi-questions
                                $isMultiQuestion = $message['rag_context']['multi_question']['is_multi'] ?? false;

                                // Construire les blocs Q/R de manière uniforme
                                if ($isMultiQuestion && !empty($message['rag_context']['multi_question']['blocks'])) {
                                    // Multi-questions : utiliser les blocs parsés
                                    $qrBlocks = $message['rag_context']['multi_question']['blocks'];
                                } else {
                                    // Mono-question : créer un bloc unique
                                    // Note: response_type est dans stats pour direct_qr_match
                                    $qrBlocks = [[
                                        'id' => 1,
                                        'question' => $previousQuestion,
                                        'answer' => $message['content'] ?? '',
                                        'type' => $message['rag_context']['stats']['response_type']
                                            ?? $message['rag_context']['response_type']
                                            ?? 'unknown',
                                        'is_suggestion' => $message['rag_context']['is_suggestion'] ?? false,
                                        'learned' => in_array($message['validation_status'], ['learned', 'validated']),
                                        'rejected' => $message['validation_status'] === 'rejected',
                                    ]];
                                }

                                $blockCount = count($qrBlocks);
                                // requires_handoff peut venir de plusieurs sources:
                                // 1. stats.requires_handoff (réponse LLM normale)
                                // 2. Le raw de la réponse direct_qr_match
                                // 3. Au niveau du bloc pour multi-questions
                                $globalRequiresHandoff = $message['rag_context']['stats']['requires_handoff']
                                    ?? $message['rag_context']['requires_handoff']
                                    ?? false;
                                $isPending = $message['validation_status'] === 'pending';
                                $isSuggestionGlobal = $message['rag_context']['is_suggestion'] ?? false;
                            @endphp
                            <div class="flex justify-start" x-data="{
                                blocks: @js(collect($qrBlocks)->map(fn($b, $i) => [
                                    'id' => $b['id'] ?? $i + 1,
                                    'question' => $b['question'] ?? '',
                                    'answer' => $b['answer'] ?? '',
                                    'requiresHandoff' => $b['requires_handoff'] ?? $globalRequiresHandoff,
                                    'validated' => $b['learned'] ?? false,
                                    'rejected' => $b['rejected'] ?? false,
                                    'is_suggestion' => $b['is_suggestion'] ?? false,
                                    'type' => $b['type'] ?? 'unknown',
                                    'improving' => false,
                                ])->values()->toArray()),
                                sent: @js($message['validation_status'] === 'learned' || $message['validation_status'] === 'validated'),
                                openContext: false,
                                copied: false,

                                // Récupérer les blocs validés (non rejetés)
                                getValidatedBlocks() {
                                    return this.blocks.filter(b => b.validated && !b.rejected);
                                },

                                // Récupérer les blocs en attente
                                getPendingBlocks() {
                                    return this.blocks.filter(b => !b.validated && !b.rejected);
                                },

                                // Vérifier si tous les blocs sont rejetés
                                allRejected() {
                                    return this.blocks.length > 0 && this.blocks.every(b => b.rejected);
                                },

                                // Valider tous les blocs en attente
                                validateAllPending() {
                                    this.blocks.forEach(b => {
                                        if (!b.rejected) {
                                            b.validated = true;
                                        }
                                    });
                                },

                                // Envoyer tous les blocs validés
                                sendAll() {
                                    const validBlocks = this.blocks
                                        .filter(b => b.validated && !b.rejected)
                                        .map(b => ({
                                            id: b.id,
                                            question: b.question,
                                            answer: b.answer,
                                            requiresHandoff: b.requiresHandoff
                                        }));

                                    if (validBlocks.length === 0) {
                                        alert('Veuillez valider au moins un bloc avant d\'envoyer.');
                                        return;
                                    }

                                    $wire.sendValidatedBlocks({{ $message['original_id'] }}, validBlocks);
                                    this.sent = true;
                                },

                                // Passer (tout rejeté)
                                skipAll() {
                                    $wire.rejectAllBlocks({{ $message['original_id'] }});
                                    this.sent = true;
                                },

                                // Copier le rapport
                                copyReport() {
                                    const report = document.getElementById('report-{{ $message['original_id'] }}');
                                    if (report) {
                                        navigator.clipboard.writeText(report.innerText);
                                        this.copied = true;
                                        setTimeout(() => this.copied = false, 2000);
                                    }
                                }
                            }">
                                <div style="width: 75%;">
                                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4 shadow-sm">
                                        {{-- Header IA --}}
                                        <div class="flex items-center gap-2 mb-2 pb-2 border-b border-gray-100 dark:border-gray-700">
                                            <x-heroicon-o-cpu-chip class="w-4 h-4 text-gray-400" />
                                            <span class="text-xs text-gray-500">{{ $message['sender_name'] }}</span>

                                            @if($message['validation_status'] === 'pending')
                                                <x-filament::badge color="warning" size="sm">En attente</x-filament::badge>
                                            @elseif($message['validation_status'] === 'validated')
                                                <x-filament::badge color="success" size="sm">Validée</x-filament::badge>
                                            @elseif($message['validation_status'] === 'learned')
                                                <x-filament::badge color="primary" size="sm">Apprise</x-filament::badge>
                                            @elseif($message['validation_status'] === 'rejected')
                                                <x-filament::badge color="danger" size="sm">Rejetée</x-filament::badge>
                                            @endif

                                            {{-- Badge de type de réponse (Documenté / Suggestion) --}}
                                            @php
                                                $responseType = $message['rag_context']['response_type'] ?? 'unknown';
                                                $isSuggestion = $message['rag_context']['is_suggestion'] ?? false;
                                                $isMultiQuestion = $message['rag_context']['multi_question']['is_multi'] ?? false;
                                                $blockCount = $message['rag_context']['multi_question']['block_count'] ?? 0;
                                            @endphp

                                            @if($isSuggestion)
                                                <x-filament::badge color="warning" size="sm" icon="heroicon-o-light-bulb">
                                                    Suggestion IA
                                                </x-filament::badge>
                                            @elseif($responseType === 'documented')
                                                <x-filament::badge color="info" size="sm" icon="heroicon-o-document-check">
                                                    Documenté
                                                </x-filament::badge>
                                            @endif

                                            @if($isMultiQuestion)
                                                <x-filament::badge color="gray" size="sm" icon="heroicon-o-queue-list">
                                                    {{ $blockCount }} questions
                                                </x-filament::badge>
                                            @endif
                                        </div>

                                        {{-- Bannière d'avertissement pour les suggestions --}}
                                        @if($isSuggestion && $message['validation_status'] === 'pending')
                                            <div class="mb-3 p-2 bg-warning-50 dark:bg-warning-950 border border-warning-200 dark:border-warning-800 rounded-lg">
                                                <div class="flex items-start gap-2">
                                                    <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-warning-500 flex-shrink-0 mt-0.5" />
                                                    <div class="text-xs text-warning-700 dark:text-warning-300">
                                                        <strong>Attention :</strong> Cette réponse est une suggestion basée sur les connaissances générales de l'IA.
                                                        Aucune source documentaire n'a été trouvée.
                                                        <strong>Vérifiez et corrigez si nécessaire avant validation.</strong>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif

                                        {{-- ═══════════════════════════════════════════════════════════════ --}}
                                        {{-- BLOCS Q/R - TEMPLATE UNIQUE (MONO/MULTI) --}}
                                        {{-- ═══════════════════════════════════════════════════════════════ --}}

                                        {{-- Message en cours de génération --}}
                                        @if(empty(trim($message['content'] ?? '')))
                                            <div class="flex items-center gap-2 text-gray-400 italic mb-4" wire:poll.3s>
                                                <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                                <span>Génération en cours...</span>
                                            </div>
                                        @else
                                            <div class="space-y-4">
                                                <template x-for="(block, blockIndex) in blocks" :key="block.id">
                                                    <div class="rounded-lg p-4 transition-all border-2"
                                                        :class="{
                                                            'border-gray-300 dark:border-gray-600': !block.validated && !block.rejected,
                                                            'opacity-70': block.rejected
                                                        }"
                                                        :style="{
                                                            borderColor: block.rejected ? '#ef4444' : (block.validated ? '#22c55e' : null)
                                                        }">
                                                        {{-- Header du bloc (badges uniquement) --}}
                                                        <div class="flex items-center gap-2 flex-wrap mb-4">
                                                            {{-- Numéro de question (multi-questions) --}}
                                                            <x-filament::badge color="primary" x-show="blocks.length > 1">
                                                                Question <span x-text="block.id"></span>/<span x-text="blocks.length"></span>
                                                            </x-filament::badge>

                                                            {{-- Badge type: Suggestion --}}
                                                            <x-filament::badge color="warning" icon="heroicon-s-light-bulb" x-show="block.is_suggestion || block.type === 'suggestion'">
                                                                SUGGESTION IA
                                                            </x-filament::badge>

                                                            {{-- Badge type: Documenté --}}
                                                            <x-filament::badge color="info" icon="heroicon-s-document-check" x-show="!block.is_suggestion && block.type === 'documented'">
                                                                DOCUMENTÉ
                                                            </x-filament::badge>

                                                            {{-- Badge type: Direct QR Match (Qdrant sans LLM) --}}
                                                            <x-filament::badge color="success" icon="heroicon-s-bolt" x-show="block.type === 'direct_qr_match'">
                                                                RÉPONSE DIRECTE
                                                            </x-filament::badge>

                                                            {{-- Badge état: Validé --}}
                                                            <x-filament::badge color="success" icon="heroicon-s-check" x-show="block.validated && !block.rejected">
                                                                VALIDÉ
                                                            </x-filament::badge>

                                                            {{-- Badge état: Rejeté --}}
                                                            <x-filament::badge color="danger" icon="heroicon-s-x-mark" x-show="block.rejected">
                                                                REJETÉ
                                                            </x-filament::badge>
                                                        </div>

                                                        {{-- Avertissement suggestion --}}
                                                        <div class="mb-3 transition-opacity"
                                                            x-show="block.is_suggestion || block.type === 'suggestion'"
                                                            :class="{'opacity-40': block.validated || block.rejected}">
                                                            <div class="p-2 bg-warning-500 dark:bg-warning-600 border border-warning-600 dark:border-warning-500 rounded-lg">
                                                                <div class="flex items-center gap-2 text-sm text-white font-medium">
                                                                    <x-heroicon-s-exclamation-triangle class="w-5 h-5" />
                                                                    <span>Suggestion IA - Aucune documentation. Vérifiez avant validation.</span>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {{-- Question --}}
                                                        <div class="mb-3">
                                                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-1">Question :</label>
                                                            <textarea
                                                                x-model="block.question"
                                                                :disabled="block.validated || block.rejected || sent"
                                                                :class="{
                                                                    'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100': !block.validated && !block.rejected && !sent,
                                                                    'bg-gray-50 dark:bg-gray-900 border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 cursor-not-allowed': block.validated || block.rejected || sent,
                                                                    'line-through opacity-50': block.rejected
                                                                }"
                                                                class="w-full p-3 rounded-lg text-sm border resize-none overflow-hidden"
                                                                rows="1"
                                                                x-effect="block.question; $nextTick(() => { $el.style.height = 'auto'; $el.style.height = ($el.scrollHeight + 2) + 'px'; })"
                                                            ></textarea>
                                                        </div>

                                                        {{-- Réponse --}}
                                                        <div class="mb-3">
                                                            <div class="flex items-center justify-between mb-1">
                                                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200">Réponse :</label>
                                                                {{-- Bouton Améliorer (visible seulement si éditable) --}}
                                                                <button
                                                                    type="button"
                                                                    x-show="!block.validated && !block.rejected && !sent && block.answer.length > 0"
                                                                    x-on:click="
                                                                        block.improving = true;
                                                                        $wire.improveBlockResponse({{ $message['original_id'] }}, block.id, block.question, block.answer)
                                                                            .then(result => {
                                                                                if (result) block.answer = result;
                                                                                block.improving = false;
                                                                            })
                                                                            .catch(() => block.improving = false);
                                                                    "
                                                                    :disabled="block.improving"
                                                                    class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300 hover:bg-primary-50 dark:hover:bg-primary-900/50 rounded transition-colors disabled:opacity-50"
                                                                    title="Corriger les fautes et améliorer la formulation"
                                                                >
                                                                    <template x-if="!block.improving">
                                                                        <span class="flex items-center gap-1">
                                                                            <x-heroicon-o-sparkles class="w-4 h-4" />
                                                                            Améliorer
                                                                        </span>
                                                                    </template>
                                                                    <template x-if="block.improving">
                                                                        <span class="flex items-center gap-1">
                                                                            <svg class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                                            </svg>
                                                                            Amélioration...
                                                                        </span>
                                                                    </template>
                                                                </button>
                                                            </div>
                                                            <textarea
                                                                x-model="block.answer"
                                                                :disabled="block.validated || block.rejected || sent"
                                                                :class="{
                                                                    'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100': !block.validated && !block.rejected && !sent,
                                                                    'bg-gray-50 dark:bg-gray-900 border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 cursor-not-allowed': block.validated || block.rejected || sent,
                                                                    'line-through opacity-50': block.rejected
                                                                }"
                                                                class="w-full p-3 rounded-lg text-sm border resize-none overflow-hidden"
                                                                rows="1"
                                                                x-effect="block.answer; $nextTick(() => { $el.style.height = 'auto'; $el.style.height = ($el.scrollHeight + 2) + 'px'; })"
                                                            ></textarea>
                                                        </div>

                                                        {{-- Checkbox handoff --}}
                                                        <div class="mb-4 transition-opacity"
                                                            x-show="!sent"
                                                            :class="{'opacity-40 pointer-events-none': block.validated || block.rejected}">
                                                            <div class="flex items-center gap-3 p-2 rounded-lg bg-warning-50 dark:bg-warning-900 border border-warning-300 dark:border-warning-700">
                                                                <input
                                                                    type="checkbox"
                                                                    x-model="block.requiresHandoff"
                                                                    :id="'handoff-' + block.id + '-{{ $message['original_id'] }}'"
                                                                    :disabled="block.validated || block.rejected"
                                                                    class="w-5 h-5 rounded border-warning-400 text-warning-600 focus:ring-warning-500"
                                                                />
                                                                <label :for="'handoff-' + block.id + '-{{ $message['original_id'] }}'" class="text-sm font-semibold text-warning-700 dark:text-warning-300 cursor-pointer flex items-center gap-2">
                                                                    <x-heroicon-s-user-group class="w-5 h-5" />
                                                                    Nécessite toujours un suivi humain
                                                                </label>
                                                            </div>
                                                        </div>

                                                        {{-- Boutons Valider/Rejeter/Annuler (en bas du bloc) --}}
                                                        <div class="flex items-center gap-2 pt-3 border-t border-gray-200 dark:border-gray-700" x-show="!sent">
                                                            {{-- Valider --}}
                                                            <x-filament::button
                                                                color="success"
                                                                size="sm"
                                                                icon="heroicon-s-check"
                                                                x-show="!block.validated && !block.rejected"
                                                                x-on:click="block.validated = true"
                                                            >
                                                                Valider
                                                            </x-filament::button>

                                                            {{-- Rejeter --}}
                                                            <x-filament::button
                                                                color="danger"
                                                                size="sm"
                                                                icon="heroicon-s-x-mark"
                                                                x-show="!block.validated && !block.rejected"
                                                                x-on:click="block.rejected = true"
                                                            >
                                                                Rejeter
                                                            </x-filament::button>

                                                            {{-- Annuler --}}
                                                            <x-filament::button
                                                                color="gray"
                                                                size="sm"
                                                                icon="heroicon-o-arrow-uturn-left"
                                                                x-show="block.validated || block.rejected"
                                                                x-on:click="block.validated = false; block.rejected = false"
                                                            >
                                                                Annuler
                                                            </x-filament::button>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>

                                            {{-- ═══════════════════════════════════════════════════════════════ --}}
                                            {{-- FOOTER GLOBAL - COLORÉ --}}
                                            {{-- ═══════════════════════════════════════════════════════════════ --}}
                                            <div class="mt-5 pt-4 border-t-2 border-gray-200 dark:border-gray-700" x-show="!sent">
                                                <div class="flex items-center justify-between gap-4 flex-wrap">
                                                    <div class="flex items-center gap-4">
                                                        {{-- Bouton Envoyer (si au moins un validé) --}}
                                                        <x-filament::button
                                                            color="primary"
                                                            size="lg"
                                                            icon="heroicon-s-paper-airplane"
                                                            x-show="!allRejected()"
                                                            x-on:click="sendAll()"
                                                            ::disabled="getValidatedBlocks().length === 0"
                                                        >
                                                            Envoyer
                                                        </x-filament::button>

                                                        {{-- Bouton Passer (si tout rejeté) --}}
                                                        <x-filament::button
                                                            color="gray"
                                                            size="lg"
                                                            icon="heroicon-s-forward"
                                                            x-show="allRejected()"
                                                            x-on:click="skipAll()"
                                                        >
                                                            Passer
                                                        </x-filament::button>

                                                        {{-- Compteur blocs validés --}}
                                                        <x-filament::badge color="gray" x-show="blocks.length > 1 && !allRejected()">
                                                            <span x-text="getValidatedBlocks().length"></span>/<span x-text="blocks.length"></span> validés
                                                        </x-filament::badge>

                                                        {{-- Indication tout rejeté --}}
                                                        <x-filament::badge color="danger" x-show="allRejected()">
                                                            Tout rejeté
                                                        </x-filament::badge>
                                                    </div>

                                                    {{-- Bouton Valider tout --}}
                                                    <x-filament::button
                                                        color="success"
                                                        size="sm"
                                                        icon="heroicon-s-check-circle"
                                                        outlined
                                                        x-show="getPendingBlocks().length > 0"
                                                        x-on:click="validateAllPending()"
                                                    >
                                                        Tout valider
                                                    </x-filament::button>
                                                </div>
                                            </div>

                                            {{-- Message envoyé --}}
                                            <div class="mt-4 pt-4 border-t border-success-200 dark:border-success-700" x-show="sent" x-cloak>
                                                <div class="flex items-center gap-2 text-success-600 dark:text-success-400">
                                                    <x-heroicon-o-check-circle class="w-5 h-5" />
                                                    <span class="text-sm font-medium">Réponse validée et envoyée</span>
                                                </div>
                                            </div>
                                        @endif

                                        {{-- Métadonnées --}}
                                        <div class="flex items-center justify-between mt-3 text-xs text-gray-400">
                                            <span>{{ $message['created_at']->format('H:i') }}</span>
                                            <div class="flex items-center gap-2">
                                                @if($message['model_used'] && $message['model_used'] !== 'direct_qr_match')
                                                    <span>{{ $message['model_used'] }}</span>
                                                @endif
                                                @if($message['tokens'])
                                                    <span>{{ $message['tokens'] }} tokens</span>
                                                @endif
                                                {{-- Bouton "Utiliser comme modèle" (visible uniquement si session escaladée) --}}
                                                @if($session->isEscalated())
                                                    @php
                                                        $cleanedContent = $message['content'] ?? '';
                                                        $cleanedContent = preg_replace('/\n*\[HANDOFF\\\\?_NEEDED\]\n*/i', '', $cleanedContent);
                                                        $cleanedContent = preg_replace('/[^.!?\n]*(?:contacter|contactez|parler à|joindre).*(?:humain|conseiller|support)[^.!?\n]*[.!?]?\s*/i', '', $cleanedContent);
                                                        $cleanedContent = trim($cleanedContent);
                                                    @endphp
                                                    <button
                                                        type="button"
                                                        class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300 hover:bg-primary-50 dark:hover:bg-primary-950 rounded transition-colors"
                                                        x-on:click="$wire.set('supportMessage', @js($cleanedContent))"
                                                        title="Copier cette suggestion dans le champ de réponse"
                                                    >
                                                        <x-heroicon-o-clipboard-document class="w-3.5 h-3.5" />
                                                        <span>Utiliser</span>
                                                    </button>
                                                @endif
                                            </div>
                                        </div>

                                        {{-- Bouton contexte RAG --}}
                                        @if(!empty($message['rag_context']))
                                            @php
                                                $context = $message['rag_context'];
                                                $documentSources = $context['document_sources'] ?? [];
                                                $learnedSources = $context['learned_sources'] ?? [];
                                                $totalSources = count($documentSources) + count($learnedSources);
                                            @endphp
                                            @php
                                                // Préparer les données pour le rapport
                                                $userQuestion = '';
                                                $aiResponse = $message['content'] ?? '';
                                                $systemPrompt = $context['system_prompt_sent'] ?? '';
                                                $conversationHistory = $context['conversation_history'] ?? [];
                                                $stats = $context['stats'] ?? [];
                                                $catDetect = $context['category_detection'] ?? null;

                                                // Trouver la question utilisateur (message précédent)
                                                if (isset($unifiedMessages[$index - 1]) && $unifiedMessages[$index - 1]['type'] === 'client') {
                                                    $userQuestion = $unifiedMessages[$index - 1]['content'] ?? '';
                                                }

                                                // Calculer le score RAG max pour l'évaluation handoff
                                                $maxRagScore = 0;
                                                foreach ($documentSources as $doc) {
                                                    $score = ($doc['score'] ?? 0) / 100; // Convertir de % à décimal
                                                    if ($score > $maxRagScore) {
                                                        $maxRagScore = $score;
                                                    }
                                                }

                                                // Récupérer les infos de l'agent pour le handoff
                                                $agentForHandoff = $session->agent;
                                                $handoffEnabled = $agentForHandoff?->human_support_enabled ?? false;
                                                $escalationThreshold = $agentForHandoff?->escalation_threshold ?? 0.3;
                                                $wouldEscalate = $handoffEnabled && $maxRagScore < $escalationThreshold && $maxRagScore > 0;
                                            @endphp
                                            @php
                                                // Préparer le rapport complet en PHP pour éviter les problèmes d'échappement JS
                                                $reportParts = [];
                                                $reportParts[] = "# Rapport d'analyse IA\n\n## Question utilisateur\n" . $userQuestion;
                                                $reportParts[] = "\n\n## Reponse de l'IA\n" . $aiResponse;
                                                $reportParts[] = "\n\n## Contexte fourni a l'IA\n\n### Prompt systeme\n" . $systemPrompt;

                                                // Historique
                                                $historyText = "\n\n### Historique de conversation (" . count($conversationHistory) . " messages)";
                                                foreach ($conversationHistory as $historyMsg) {
                                                    $historyText .= "\n[" . ($historyMsg['role'] ?? 'unknown') . "] " . ($historyMsg['content'] ?? '');
                                                }
                                                $reportParts[] = $historyText;

                                                // Filtrage catégorie
                                                $catText = "\n\n### Filtrage par categorie";
                                                if (!($stats['use_category_filtering'] ?? false)) {
                                                    $catText .= "\n- Statut: Desactive pour cet agent";
                                                } elseif ($catDetect) {
                                                    $catText .= "\n- Methode de detection: " . ($catDetect['method'] ?? 'N/A');
                                                    $catText .= "\n- Confiance: " . round(($catDetect['confidence'] ?? 0) * 100) . "%";
                                                    $catText .= "\n- Categories detectees: " . (!empty($catDetect['categories']) ? implode(', ', array_map(fn($c) => $c['name'] ?? $c, $catDetect['categories'])) : 'Aucune');
                                                    $catText .= "\n- Resultats filtres: " . ($catDetect['filtered_results_count'] ?? 0);
                                                    $catText .= "\n- Resultats totaux: " . ($catDetect['total_results_count'] ?? 0);
                                                    $catText .= "\n- Fallback utilise: " . (($catDetect['used_fallback'] ?? false) ? 'Oui' : 'Non');
                                                } else {
                                                    $catText .= "\n- Statut: Active mais aucune categorie detectee";
                                                }
                                                $reportParts[] = $catText;

                                                // Documents RAG
                                                $docsText = "\n\n### Documents RAG (" . count($documentSources) . " sources)";
                                                foreach ($documentSources as $idx => $doc) {
                                                    $docsText .= "\n--- Document #" . ($doc['index'] ?? ($idx + 1)) . " (" . ($doc['score'] ?? 0) . "% pertinent) ---";
                                                    $docsText .= "\nType: " . ($doc['type'] ?? 'unknown');
                                                    $docsText .= "\nCategorie: " . ($doc['category'] ?? 'Non categorise');
                                                    $docsText .= "\nSource: " . ($doc['source_doc'] ?? 'N/A');
                                                    if (!empty($doc['question'])) {
                                                        $docsText .= "\nQuestion matchee: " . ($doc['question'] ?? '');
                                                    }
                                                    $docsText .= "\nContenu: " . ($doc['content'] ?? '[VIDE]');
                                                }
                                                $reportParts[] = $docsText;

                                                // Sources apprises
                                                $learnedText = "\n\n### Sources d'apprentissage (" . count($learnedSources) . " cas)";
                                                foreach ($learnedSources as $idx => $learned) {
                                                    $learnedText .= "\n--- Cas #" . ($learned['index'] ?? ($idx + 1)) . " (" . ($learned['score'] ?? 0) . "% similaire) ---";
                                                    $learnedText .= "\nQ: " . ($learned['question'] ?? '');
                                                    $learnedText .= "\nR: " . ($learned['answer'] ?? '');
                                                }
                                                $reportParts[] = $learnedText;

                                                // Évaluation Handoff
                                                $handoffText = "\n\n## Evaluation Handoff Humain";
                                                $handoffText .= "\n- Handoff active: " . ($handoffEnabled ? 'Oui' : 'Non');
                                                if ($handoffEnabled) {
                                                    $handoffText .= "\n- Seuil d'escalade configure: " . number_format($escalationThreshold * 100, 0) . "%";
                                                    $handoffText .= "\n- Score RAG maximum obtenu: " . ($maxRagScore > 0 ? number_format($maxRagScore * 100, 1) . '%' : 'Aucun document trouve');
                                                    $handoffText .= "\n- Decision d'escalade (score RAG): " . ($wouldEscalate ? 'OUI - Score RAG insuffisant (< seuil)' : ($maxRagScore == 0 ? 'OUI - Aucune source trouvee' : 'NON - Score suffisant'));
                                                    $handoffText .= "\n- Agents de support configures: " . ($agentForHandoff?->supportUsers()->count() ?? 0);
                                                    if ($agentForHandoff?->support_email) {
                                                        $handoffText .= "\n- Email de support: " . $agentForHandoff->support_email;
                                                    }
                                                }

                                                // Statut réel d'escalade de la session
                                                $handoffText .= "\n\n### Statut reel de la session";
                                                $handoffText .= "\n- Session escaladee: " . ($session->isEscalated() ? 'OUI' : 'NON');
                                                if ($session->isEscalated()) {
                                                    $reasonLabel = match($session->escalation_reason) {
                                                        'low_confidence' => 'Score RAG bas (automatique)',
                                                        'user_request' => 'Demande utilisateur (bouton)',
                                                        'user_explicit_request' => 'Detection automatique de demande humain dans le message',
                                                        'ai_handoff_request' => 'IA a ajoute le marqueur [HANDOFF_NEEDED]',
                                                        'ai_uncertainty' => 'Incertitude IA',
                                                        'negative_feedback' => 'Feedback negatif utilisateur',
                                                        default => $session->escalation_reason ?? 'Inconnu'
                                                    };
                                                    $handoffText .= "\n- Raison de l'escalade: " . $reasonLabel;
                                                    $handoffText .= "\n- Escalade declenchee le: " . ($session->escalated_at ? $session->escalated_at->format('d/m/Y H:i:s') : 'N/A');
                                                }
                                                $reportParts[] = $handoffText;

                                                // Infos techniques
                                                $techText = "\n\n## Informations techniques";
                                                $techText .= "\n- Agent: " . ($stats['agent_slug'] ?? 'N/A');
                                                $techText .= "\n- Modele: " . ($stats['agent_model'] ?? 'N/A');
                                                $techText .= "\n- Temperature: " . ($stats['temperature'] ?? 'N/A');
                                                $techText .= "\n- Fenetre contexte: " . ($stats['context_window_size'] ?? 0) . " messages";
                                                $techText .= "\n- Filtrage categorie: " . (($stats['use_category_filtering'] ?? false) ? 'Active' : 'Desactive');
                                                $techText .= "\n- Type de reponse: " . (($stats['response_type'] ?? '') === 'direct_qr_match' ? 'DIRECT Q/R (sans appel LLM)' : 'Generation LLM');
                                                $reportParts[] = $techText;

                                                $fullReport = implode('', $reportParts);
                                            @endphp
                                            <div x-data="{
                                                openContext: false,
                                                copied: false,
                                                reportContent: @js($fullReport),
                                                copyReport() {
                                                    navigator.clipboard.writeText(this.reportContent).then(() => {
                                                        this.copied = true;
                                                        setTimeout(() => this.copied = false, 2000);
                                                    }).catch(err => {
                                                        console.error('Failed to copy report:', err);
                                                        alert('Erreur lors de la copie. Veuillez réessayer.');
                                                    });
                                                }
                                            }">
                                                <button
                                                    type="button"
                                                    @click="openContext = true"
                                                    class="mt-3 inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-purple-100 text-purple-700 hover:bg-purple-200 dark:bg-purple-900 dark:text-purple-200 dark:hover:bg-purple-800 border border-purple-300 dark:border-purple-700 transition-colors"
                                                >
                                                    <x-heroicon-o-document-magnifying-glass class="w-4 h-4" />
                                                    Voir le contexte RAG ({{ $totalSources }} sources)
                                                </button>

                                                {{-- Modal contexte RAG --}}
                                                <template x-teleport="body">
                                                    <div
                                                        x-show="openContext"
                                                        x-transition:enter="transition ease-out duration-200"
                                                        x-transition:enter-start="opacity-0"
                                                        x-transition:enter-end="opacity-100"
                                                        x-transition:leave="transition ease-in duration-150"
                                                        x-transition:leave-start="opacity-100"
                                                        x-transition:leave-end="opacity-0"
                                                        class="fixed inset-0 z-50 overflow-hidden"
                                                        style="display: none;"
                                                    >
                                                        <div class="absolute inset-0 bg-black/50" @click="openContext = false"></div>
                                                        <div class="absolute inset-4 md:inset-8 lg:inset-12 bg-white dark:bg-gray-900 rounded-xl shadow-2xl flex flex-col overflow-hidden">
                                                            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                                                                <div>
                                                                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Contexte RAG</h2>
                                                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $totalSources }} source(s)</p>
                                                                </div>
                                                                <button @click="openContext = false" class="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                                                                    <x-heroicon-o-x-mark class="w-6 h-6" />
                                                                </button>
                                                            </div>
                                                            <div class="flex-1 overflow-y-auto p-6 space-y-6">
                                                                {{-- Stats Section --}}
                                                                @if(!empty($context['stats']))
                                                                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                                                                        <h3 class="font-semibold text-gray-700 dark:text-gray-300 mb-3 flex items-center gap-2">
                                                                            <x-heroicon-o-chart-bar class="w-4 h-4" />
                                                                            Statistiques de génération
                                                                        </h3>
                                                                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                                                            <div>
                                                                                <span class="text-gray-500">Agent</span>
                                                                                <p class="font-medium">{{ $context['stats']['agent_slug'] ?? '-' }}</p>
                                                                            </div>
                                                                            <div>
                                                                                <span class="text-gray-500">Modèle</span>
                                                                                <p class="font-medium">{{ $context['stats']['agent_model'] ?? '-' }}</p>
                                                                            </div>
                                                                            <div>
                                                                                <span class="text-gray-500">Température</span>
                                                                                <p class="font-medium">{{ $context['stats']['temperature'] ?? '-' }}</p>
                                                                            </div>
                                                                            <div>
                                                                                <span class="text-gray-500">Fenêtre contexte</span>
                                                                                <p class="font-medium">{{ $context['stats']['context_window_size'] ?? 0 }} messages</p>
                                                                            </div>
                                                                            <div>
                                                                                <span class="text-gray-500">Sources docs</span>
                                                                                <p class="font-medium">{{ $context['stats']['document_count'] ?? 0 }}</p>
                                                                            </div>
                                                                            <div>
                                                                                <span class="text-gray-500">Sources apprises</span>
                                                                                <p class="font-medium">{{ $context['stats']['learned_count'] ?? 0 }}</p>
                                                                            </div>
                                                                            <div>
                                                                                <span class="text-gray-500">Historique</span>
                                                                                <p class="font-medium">{{ $context['stats']['history_count'] ?? 0 }} msgs</p>
                                                                            </div>
                                                                            <div>
                                                                                <span class="text-gray-500">Filtrage catégorie</span>
                                                                                <p class="font-medium">{{ ($context['stats']['use_category_filtering'] ?? false) ? 'Oui' : 'Non' }}</p>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                @endif

                                                                {{-- Category Detection --}}
                                                                @if(!empty($context['category_detection']))
                                                                    <div class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-4 border border-purple-200 dark:border-purple-700">
                                                                        <h3 class="font-semibold text-purple-700 dark:text-purple-300 mb-3 flex items-center gap-2">
                                                                            <x-heroicon-o-tag class="w-4 h-4" />
                                                                            Détection de catégorie
                                                                        </h3>
                                                                        <div class="grid grid-cols-2 gap-4 text-sm">
                                                                            <div>
                                                                                <span class="text-purple-500">Méthode</span>
                                                                                <p class="font-medium">{{ $context['category_detection']['method'] ?? '-' }}</p>
                                                                            </div>
                                                                            <div>
                                                                                <span class="text-purple-500">Confiance</span>
                                                                                <p class="font-medium">{{ round(($context['category_detection']['confidence'] ?? 0) * 100) }}%</p>
                                                                            </div>
                                                                            @if(!empty($context['category_detection']['categories']))
                                                                                <div class="col-span-2">
                                                                                    <span class="text-purple-500">Catégories détectées</span>
                                                                                    <div class="flex flex-wrap gap-1 mt-1">
                                                                                        @foreach($context['category_detection']['categories'] as $cat)
                                                                                            <span class="px-2 py-0.5 bg-purple-100 dark:bg-purple-800 text-purple-700 dark:text-purple-200 rounded text-xs">{{ $cat['name'] ?? $cat }}</span>
                                                                                        @endforeach
                                                                                    </div>
                                                                                </div>
                                                                            @endif
                                                                            @if(isset($context['category_detection']['used_fallback']))
                                                                                <div>
                                                                                    <span class="text-purple-500">Fallback utilisé</span>
                                                                                    <p class="font-medium">{{ $context['category_detection']['used_fallback'] ? 'Oui' : 'Non' }}</p>
                                                                                </div>
                                                                            @endif
                                                                        </div>
                                                                    </div>
                                                                @endif

                                                                {{-- System Prompt Sent --}}
                                                                @if(!empty($context['system_prompt_sent']))
                                                                    <div class="border border-blue-200 dark:border-blue-700 rounded-lg overflow-hidden">
                                                                        <div class="bg-blue-50 dark:bg-blue-900/30 px-4 py-2 border-b border-blue-200 dark:border-blue-700">
                                                                            <h3 class="font-semibold text-blue-700 dark:text-blue-300 flex items-center gap-2">
                                                                                <x-heroicon-o-command-line class="w-4 h-4" />
                                                                                System Prompt envoyé au LLM
                                                                            </h3>
                                                                        </div>
                                                                        <pre class="p-4 text-xs text-gray-700 dark:text-gray-300 whitespace-pre-wrap max-h-64 overflow-y-auto bg-gray-50 dark:bg-gray-900">{{ $context['system_prompt_sent'] }}</pre>
                                                                    </div>
                                                                @endif

                                                                {{-- Conversation History --}}
                                                                @if(!empty($context['conversation_history']))
                                                                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                                                                        <div class="bg-gray-100 dark:bg-gray-800 px-4 py-2 border-b border-gray-200 dark:border-gray-700">
                                                                            <h3 class="font-semibold text-gray-700 dark:text-gray-300 flex items-center gap-2">
                                                                                <x-heroicon-o-clock class="w-4 h-4" />
                                                                                Historique de conversation (fenêtre glissante: {{ count($context['conversation_history']) }} msgs)
                                                                            </h3>
                                                                        </div>
                                                                        <div class="p-4 space-y-2 max-h-48 overflow-y-auto bg-gray-50 dark:bg-gray-900">
                                                                            @foreach($context['conversation_history'] as $histMsg)
                                                                                <div class="text-xs">
                                                                                    <span class="font-semibold {{ $histMsg['role'] === 'user' ? 'text-blue-600' : 'text-green-600' }}">
                                                                                        [{{ $histMsg['timestamp'] ?? '' }}] {{ $histMsg['role'] === 'user' ? 'User' : 'Assistant' }}:
                                                                                    </span>
                                                                                    <span class="text-gray-600 dark:text-gray-400">{{ \Illuminate\Support\Str::limit($histMsg['content'], 200) }}</span>
                                                                                </div>
                                                                            @endforeach
                                                                        </div>
                                                                    </div>
                                                                @endif

                                                                {{-- Learned Sources --}}
                                                                @if(!empty($learnedSources))
                                                                    <div class="space-y-3">
                                                                        <h3 class="font-semibold text-amber-700 dark:text-amber-300 flex items-center gap-2">
                                                                            <x-heroicon-o-light-bulb class="w-4 h-4" />
                                                                            Sources apprises ({{ count($learnedSources) }})
                                                                        </h3>
                                                                        @foreach($learnedSources as $learned)
                                                                            <div class="border border-amber-200 dark:border-amber-700 rounded-lg p-3 bg-amber-50 dark:bg-amber-900/20">
                                                                                <div class="flex justify-between items-start mb-2">
                                                                                    <span class="font-medium text-sm text-amber-800 dark:text-amber-200">Q: {{ $learned['question'] ?? '' }}</span>
                                                                                    <span class="text-xs px-2 py-0.5 rounded bg-amber-100 dark:bg-amber-800 text-amber-700 dark:text-amber-200">{{ $learned['score'] ?? 0 }}%</span>
                                                                                </div>
                                                                                <pre class="text-xs text-gray-600 dark:text-gray-400 whitespace-pre-wrap max-h-24 overflow-y-auto">{{ $learned['answer'] ?? '' }}</pre>
                                                                                @if(isset($learned['message_id']))
                                                                                    <p class="text-xs text-gray-400 mt-1">Message ID: {{ $learned['message_id'] }}</p>
                                                                                @endif
                                                                            </div>
                                                                        @endforeach
                                                                    </div>
                                                                @endif

                                                                {{-- Document Sources RAG --}}
                                                                @if(!empty($documentSources))
                                                                    <div class="space-y-3">
                                                                        <h3 class="font-semibold text-cyan-700 dark:text-cyan-300 flex items-center gap-2">
                                                                            <x-heroicon-o-document-text class="w-4 h-4" />
                                                                            Documents RAG ({{ count($documentSources) }})
                                                                        </h3>
                                                                        @foreach($documentSources as $doc)
                                                                            @php
                                                                                $hasEmptyContent = empty(trim($doc['content'] ?? ''));
                                                                            @endphp
                                                                            <div class="border {{ $hasEmptyContent ? 'border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20' : 'border-cyan-200 dark:border-cyan-700 bg-cyan-50 dark:bg-cyan-900/20' }} rounded-lg p-3">
                                                                                <div class="flex justify-between items-start mb-2">
                                                                                    <div>
                                                                                        <span class="font-medium text-sm">{{ $doc['source_doc'] ?? 'Document #' . ($doc['index'] ?? $loop->iteration) }}</span>
                                                                                        @if(!empty($doc['type']))
                                                                                            <span class="ml-2 text-xs px-1.5 py-0.5 rounded bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300">{{ $doc['type'] }}</span>
                                                                                        @endif
                                                                                        @if(!empty($doc['category']))
                                                                                            <span class="ml-1 text-xs px-1.5 py-0.5 rounded bg-purple-100 dark:bg-purple-800 text-purple-600 dark:text-purple-300">{{ $doc['category'] }}</span>
                                                                                        @endif
                                                                                    </div>
                                                                                    <span class="text-xs px-2 py-0.5 rounded {{ $hasEmptyContent ? 'bg-red-100 dark:bg-red-800 text-red-700 dark:text-red-200' : 'bg-cyan-100 dark:bg-cyan-800 text-cyan-700 dark:text-cyan-200' }}">{{ $doc['score'] ?? 0 }}%</span>
                                                                                </div>
                                                                                @if(!empty($doc['question']))
                                                                                    <p class="text-xs text-gray-500 mb-1"><strong>Q:</strong> {{ $doc['question'] }}</p>
                                                                                @endif
                                                                                @if($hasEmptyContent)
                                                                                    <div class="p-2 bg-red-100 dark:bg-red-900/50 rounded text-red-600 dark:text-red-300 text-sm flex items-center gap-2">
                                                                                        <x-heroicon-o-exclamation-triangle class="w-4 h-4 flex-shrink-0" />
                                                                                        <span>Contenu vide - problème d'indexation (ID: {{ $doc['id'] ?? 'N/A' }})</span>
                                                                                    </div>
                                                                                @else
                                                                                    <pre class="text-xs text-gray-600 dark:text-gray-400 whitespace-pre-wrap max-h-32 overflow-y-auto bg-white dark:bg-gray-800 p-2 rounded">{{ $doc['content'] }}</pre>
                                                                                @endif
                                                                                @if(!empty($doc['metadata']))
                                                                                    <details class="mt-2">
                                                                                        <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-700">Métadonnées</summary>
                                                                                        <pre class="text-xs text-gray-500 mt-1 p-2 bg-gray-100 dark:bg-gray-800 rounded overflow-x-auto">{{ json_encode($doc['metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                                                                    </details>
                                                                                @endif
                                                                            </div>
                                                                        @endforeach
                                                                    </div>
                                                                @endif

                                                                {{-- No sources --}}
                                                                @if(empty($documentSources) && empty($learnedSources) && empty($context['system_prompt_sent']))
                                                                    <p class="text-gray-500 text-center py-8">Aucune donnée de contexte RAG disponible</p>
                                                                @endif

                                                                {{-- Rapport pour analyse --}}
                                                                <div class="border-2 border-indigo-300 dark:border-indigo-600 rounded-lg overflow-hidden">
                                                                    <div class="bg-indigo-50 dark:bg-indigo-950 px-4 py-3 border-b border-indigo-200 dark:border-indigo-700">
                                                                        <h3 class="font-semibold text-indigo-700 dark:text-indigo-300 flex items-center gap-2">
                                                                            <x-heroicon-o-clipboard-document class="w-5 h-5" />
                                                                            Rapport pour analyse (copier pour Claude)
                                                                        </h3>
                                                                    </div>
                                                                    <div class="p-4 bg-gray-50 dark:bg-gray-900">
                                                                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                                                                            Cliquez sur le bouton ci-dessous pour copier un rapport complet que vous pouvez envoyer à Claude ou un autre LLM pour analyser pourquoi l'IA n'a pas bien répondu.
                                                                        </p>
                                                                        <div class="flex items-center gap-3 mb-4">
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
                                                                                        Copié !
                                                                                    </span>
                                                                                </template>
                                                                            </button>
                                                                        </div>

                                                                        {{-- Aperçu complet du rapport en lecture humaine --}}
                                                                        <details class="mt-4">
                                                                            <summary class="text-sm text-indigo-600 dark:text-indigo-400 cursor-pointer hover:text-indigo-800 dark:hover:text-indigo-300 font-medium">
                                                                                📄 Lire le rapport complet
                                                                            </summary>
                                                                            <div class="mt-3 p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 max-h-[80vh] overflow-y-auto">
                                                                                <div class="space-y-4">
                                                                                    {{-- Question utilisateur --}}
                                                                                    <h4 class="text-base font-bold text-gray-800 dark:text-gray-200 mb-2">📝 Question utilisateur</h4>
                                                                                    <pre class="text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700 p-3 rounded text-sm font-sans" style="white-space: pre-wrap; word-wrap: break-word;">{{ $userQuestion ?: '(Non disponible)' }}</pre>

                                                                                    {{-- Réponse IA complète --}}
                                                                                    <h4 class="text-base font-bold text-gray-800 dark:text-gray-200 mt-4 mb-2">🤖 Réponse de l'IA</h4>
                                                                                    <pre class="text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700 p-3 rounded text-sm font-sans" style="white-space: pre-wrap; word-wrap: break-word;">{{ $aiResponse ?: '(Non disponible)' }}</pre>

                                                                                    {{-- Prompt système --}}
                                                                                    @if(!empty($systemPrompt))
                                                                                        <h4 class="text-base font-bold text-gray-800 dark:text-gray-200 mt-4 mb-2">⚙️ Prompt système</h4>
                                                                                        <pre class="text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700 p-3 rounded text-xs font-sans max-h-48 overflow-y-auto" style="white-space: pre-wrap; word-wrap: break-word;">{{ $systemPrompt }}</pre>
                                                                                    @endif

                                                                                    {{-- Historique de conversation --}}
                                                                                    @if(!empty($conversationHistory))
                                                                                        <h4 class="text-base font-bold text-gray-800 dark:text-gray-200 mt-4 mb-2">💬 Historique de conversation ({{ count($conversationHistory) }} messages)</h4>
                                                                                        <div class="bg-gray-100 dark:bg-gray-700 p-3 rounded space-y-2 max-h-64 overflow-y-auto">
                                                                                            @foreach($conversationHistory as $histMsg)
                                                                                                <div class="text-xs border-l-2 {{ ($histMsg['role'] ?? '') === 'user' ? 'border-blue-500' : 'border-green-500' }} bg-white dark:bg-gray-800 pl-3 py-2 rounded-r">
                                                                                                    <span class="font-bold {{ ($histMsg['role'] ?? '') === 'user' ? 'text-blue-600 dark:text-blue-400' : 'text-green-600 dark:text-green-400' }}">
                                                                                                        [{{ $histMsg['role'] ?? 'unknown' }}]
                                                                                                    </span>
                                                                                                    <pre class="mt-1 text-gray-700 dark:text-gray-300 text-xs font-sans" style="white-space: pre-wrap; word-wrap: break-word;">{{ $histMsg['content'] ?? '' }}</pre>
                                                                                                </div>
                                                                                            @endforeach
                                                                                        </div>
                                                                                    @endif

                                                                                    {{-- Filtrage par catégorie --}}
                                                                                    <h4 class="text-base font-bold text-gray-800 dark:text-gray-200 mt-4 mb-2">🏷️ Filtrage par catégorie</h4>
                                                                                    <div class="bg-gray-100 dark:bg-gray-700 p-3 rounded text-sm">
                                                                                        @if(!($stats['use_category_filtering'] ?? false))
                                                                                            <p class="text-gray-600 dark:text-gray-400">Statut: <strong>Désactivé</strong> pour cet agent</p>
                                                                                        @elseif($catDetect)
                                                                                            <ul class="space-y-1 text-gray-600 dark:text-gray-400">
                                                                                                <li>Méthode: <strong>{{ $catDetect['method'] ?? 'N/A' }}</strong></li>
                                                                                                <li>Confiance: <strong>{{ round(($catDetect['confidence'] ?? 0) * 100) }}%</strong></li>
                                                                                                <li>Catégories: <strong>{{ !empty($catDetect['categories']) ? implode(', ', array_map(fn($c) => $c['name'] ?? $c, $catDetect['categories'])) : 'Aucune' }}</strong></li>
                                                                                                <li>Résultats filtrés: {{ $catDetect['filtered_results_count'] ?? 0 }} / {{ $catDetect['total_results_count'] ?? 0 }}</li>
                                                                                                <li>Fallback utilisé: {{ ($catDetect['used_fallback'] ?? false) ? 'Oui' : 'Non' }}</li>
                                                                                            </ul>
                                                                                        @else
                                                                                            <p class="text-gray-600 dark:text-gray-400">Statut: Activé mais <strong>aucune catégorie détectée</strong></p>
                                                                                        @endif
                                                                                    </div>

                                                                                    {{-- Évaluation Handoff --}}
                                                                                    <h4 class="text-base font-bold text-gray-800 dark:text-gray-200 mt-4 mb-2">🚨 Évaluation Handoff Humain</h4>
                                                                                    <div class="p-3 rounded-lg {{ $wouldEscalate || $maxRagScore == 0 ? 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700' : 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700' }}">
                                                                                        <ul class="text-sm space-y-1">
                                                                                            <li class="flex items-center gap-2">
                                                                                                <span class="text-gray-600 dark:text-gray-400">Handoff activé:</span>
                                                                                                <span class="font-medium">{{ $handoffEnabled ? 'Oui' : 'Non' }}</span>
                                                                                            </li>
                                                                                            @if($handoffEnabled)
                                                                                                <li class="flex items-center gap-2">
                                                                                                    <span class="text-gray-600 dark:text-gray-400">Seuil d'escalade:</span>
                                                                                                    <span class="font-medium">{{ number_format($escalationThreshold * 100, 0) }}%</span>
                                                                                                </li>
                                                                                                <li class="flex items-center gap-2">
                                                                                                    <span class="text-gray-600 dark:text-gray-400">Score RAG max:</span>
                                                                                                    <span class="font-medium {{ $maxRagScore < $escalationThreshold ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                                                                                                        {{ $maxRagScore > 0 ? number_format($maxRagScore * 100, 1) . '%' : 'Aucun' }}
                                                                                                    </span>
                                                                                                </li>
                                                                                                <li class="flex items-center gap-2">
                                                                                                    <span class="text-gray-600 dark:text-gray-400">Décision:</span>
                                                                                                    @if($wouldEscalate || $maxRagScore == 0)
                                                                                                        <span class="font-bold text-red-600 dark:text-red-400">
                                                                                                            <x-heroicon-o-exclamation-triangle class="w-4 h-4 inline" />
                                                                                                            ESCALADE RECOMMANDÉE
                                                                                                        </span>
                                                                                                    @else
                                                                                                        <span class="font-medium text-green-600 dark:text-green-400">
                                                                                                            <x-heroicon-o-check-circle class="w-4 h-4 inline" />
                                                                                                            Score suffisant
                                                                                                        </span>
                                                                                                    @endif
                                                                                                </li>
                                                                                                <li class="flex items-center gap-2">
                                                                                                    <span class="text-gray-600 dark:text-gray-400">Agents support:</span>
                                                                                                    <span class="font-medium">{{ $agentForHandoff?->supportUsers()->count() ?? 0 }}</span>
                                                                                                </li>
                                                                                            @endif
                                                                                        </ul>
                                                                                    </div>

                                                                                    {{-- Statut réel d'escalade --}}
                                                                                    <h4 class="text-base font-bold text-gray-800 dark:text-gray-200 mt-4 mb-2">📋 Statut réel de la session</h4>
                                                                                    <div class="p-3 rounded-lg {{ $session->isEscalated() ? 'bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-700' : 'bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700' }}">
                                                                                        <ul class="text-sm space-y-1">
                                                                                            <li class="flex items-center gap-2">
                                                                                                <span class="text-gray-600 dark:text-gray-400">Session escaladée:</span>
                                                                                                <span class="font-bold {{ $session->isEscalated() ? 'text-orange-600 dark:text-orange-400' : 'text-gray-600 dark:text-gray-400' }}">
                                                                                                    {{ $session->isEscalated() ? 'OUI' : 'NON' }}
                                                                                                </span>
                                                                                            </li>
                                                                                            @if($session->isEscalated())
                                                                                                <li class="flex items-center gap-2">
                                                                                                    <span class="text-gray-600 dark:text-gray-400">Déclencheur:</span>
                                                                                                    <span class="font-medium text-orange-700 dark:text-orange-300">
                                                                                                        {{ match($session->escalation_reason) {
                                                                                                            'low_confidence' => '📊 Score RAG bas (automatique)',
                                                                                                            'user_request' => '👆 Demande utilisateur (bouton)',
                                                                                                            'user_explicit_request' => '🔍 Détection automatique (message utilisateur)',
                                                                                                            'ai_handoff_request' => '🤖 IA a ajouté [HANDOFF_NEEDED]',
                                                                                                            'ai_uncertainty' => '❓ Incertitude IA',
                                                                                                            'negative_feedback' => '👎 Feedback négatif',
                                                                                                            default => $session->escalation_reason ?? 'Inconnu'
                                                                                                        } }}
                                                                                                    </span>
                                                                                                </li>
                                                                                                <li class="flex items-center gap-2">
                                                                                                    <span class="text-gray-600 dark:text-gray-400">Escalade le:</span>
                                                                                                    <span class="font-medium">{{ $session->escalated_at?->format('d/m/Y H:i:s') ?? 'N/A' }}</span>
                                                                                                </li>
                                                                                            @endif
                                                                                        </ul>
                                                                                    </div>

                                                                                    {{-- Documents RAG complets --}}
                                                                                    @if(!empty($documentSources))
                                                                                        <h4 class="text-base font-bold text-gray-800 dark:text-gray-200 mt-4 mb-2">📚 Documents RAG ({{ count($documentSources) }} sources)</h4>
                                                                                        <div class="space-y-3">
                                                                                            @foreach($documentSources as $doc)
                                                                                                <div class="p-3 rounded border {{ empty(trim($doc['content'] ?? '')) ? 'bg-red-50 dark:bg-red-900/20 border-red-300 dark:border-red-700' : 'bg-cyan-50 dark:bg-cyan-900/20 border-cyan-200 dark:border-cyan-700' }}">
                                                                                                    <div class="flex flex-wrap items-center gap-2 mb-2">
                                                                                                        <span class="font-bold text-sm">#{{ $doc['index'] ?? $loop->iteration }}</span>
                                                                                                        <span class="px-2 py-0.5 rounded text-xs {{ empty(trim($doc['content'] ?? '')) ? 'bg-red-200 dark:bg-red-800 text-red-700 dark:text-red-200' : 'bg-cyan-200 dark:bg-cyan-800 text-cyan-700 dark:text-cyan-200' }}">{{ $doc['score'] ?? 0 }}%</span>
                                                                                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $doc['type'] ?? 'unknown' }}</span>
                                                                                                        @if(!empty($doc['category']))
                                                                                                            <span class="text-xs px-1.5 py-0.5 rounded bg-purple-100 dark:bg-purple-800 text-purple-600 dark:text-purple-300">{{ $doc['category'] }}</span>
                                                                                                        @endif
                                                                                                    </div>
                                                                                                    @if(!empty($doc['source_doc']))
                                                                                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Source: {{ $doc['source_doc'] }}</p>
                                                                                                    @endif
                                                                                                    @if(!empty($doc['question']))
                                                                                                        <p class="text-xs text-gray-600 dark:text-gray-400 mb-2"><strong>Q:</strong> {{ $doc['question'] }}</p>
                                                                                                    @endif
                                                                                                    @if(empty(trim($doc['content'] ?? '')))
                                                                                                        <div class="p-2 bg-red-100 dark:bg-red-900/50 rounded text-red-600 dark:text-red-300 text-sm flex items-center gap-2">
                                                                                                            <x-heroicon-o-exclamation-triangle class="w-4 h-4 flex-shrink-0" />
                                                                                                            <span>[CONTENU VIDE - problème d'indexation]</span>
                                                                                                        </div>
                                                                                                    @else
                                                                                                        <div class="text-xs text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 p-2 rounded whitespace-pre-wrap">{{ $doc['content'] }}</div>
                                                                                                    @endif
                                                                                                </div>
                                                                                            @endforeach
                                                                                        </div>
                                                                                    @endif

                                                                                    {{-- Sources apprises complètes --}}
                                                                                    @if(!empty($learnedSources))
                                                                                        <h4 class="text-base font-bold text-gray-800 dark:text-gray-200 mt-4 mb-2">💡 Sources apprises ({{ count($learnedSources) }} cas)</h4>
                                                                                        <div class="space-y-3">
                                                                                            @foreach($learnedSources as $learned)
                                                                                                <div class="p-3 rounded border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20">
                                                                                                    <div class="flex items-center gap-2 mb-2">
                                                                                                        <span class="font-bold text-sm">#{{ $learned['index'] ?? $loop->iteration }}</span>
                                                                                                        <span class="px-2 py-0.5 rounded text-xs bg-amber-200 dark:bg-amber-800 text-amber-700 dark:text-amber-200">{{ $learned['score'] ?? 0 }}%</span>
                                                                                                    </div>
                                                                                                    <p class="text-sm font-medium text-amber-800 dark:text-amber-200 mb-2">Q: {{ $learned['question'] ?? '' }}</p>
                                                                                                    <div class="text-xs text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 p-2 rounded whitespace-pre-wrap">{{ $learned['answer'] ?? '' }}</div>
                                                                                                </div>
                                                                                            @endforeach
                                                                                        </div>
                                                                                    @endif

                                                                                    {{-- Informations techniques --}}
                                                                                    <h4 class="text-base font-bold text-gray-800 dark:text-gray-200 mt-4 mb-2">⚡ Informations techniques</h4>
                                                                                    <div class="bg-gray-100 dark:bg-gray-700 p-3 rounded text-sm">
                                                                                        <ul class="space-y-1 text-gray-600 dark:text-gray-400">
                                                                                            <li>Agent: <strong>{{ $stats['agent_slug'] ?? 'N/A' }}</strong></li>
                                                                                            <li>Modèle: <strong>{{ $stats['agent_model'] ?? 'N/A' }}</strong></li>
                                                                                            <li>Température: <strong>{{ $stats['temperature'] ?? 'N/A' }}</strong></li>
                                                                                            <li>Fenêtre contexte: <strong>{{ $stats['context_window_size'] ?? 0 }}</strong> messages</li>
                                                                                            <li>Type de réponse: <strong>{{ ($stats['response_type'] ?? '') === 'direct_qr_match' ? 'DIRECT Q/R (sans LLM)' : 'Génération LLM' }}</strong></li>
                                                                                        </ul>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </details>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>

                        {{-- Message Support Agent (gauche, vert) --}}
                        @elseif($isSupport)
                            @php
                                // Trouver la question client précédente pour pré-remplir le formulaire
                                $previousQuestion = '';
                                for ($i = $index - 1; $i >= 0; $i--) {
                                    if (($unifiedMessages[$i]['type'] ?? '') === 'client') {
                                        $previousQuestion = $unifiedMessages[$i]['content'] ?? '';
                                        break;
                                    }
                                }
                            @endphp
                            <div class="flex justify-start" x-data="{
                                showLearnForm: false,
                                learnQuestion: @js($previousQuestion),
                                learnAnswer: @js($message['content'])
                            }">
                                <div style="max-width: 75%;">
                                    <div class="bg-success-50 dark:bg-success-950 border border-success-200 dark:border-success-800 rounded-lg p-3 shadow-sm">
                                        {{-- Header Support --}}
                                        <div class="flex items-center gap-2 mb-2 pb-2 border-b border-success-100 dark:border-success-800">
                                            <x-heroicon-o-user-circle class="w-4 h-4 text-success-600" />
                                            <span class="text-xs font-medium text-success-700 dark:text-success-300">{{ $message['sender_name'] }}</span>
                                            @if($message['learned_at'] ?? false)
                                                <x-filament::badge size="sm" color="primary">Apprise</x-filament::badge>
                                            @endif
                                            @if($message['was_ai_improved'] ?? false)
                                                <x-filament::badge size="sm" color="info">Amélioré par IA</x-filament::badge>
                                            @endif
                                            @if(($message['channel'] ?? 'chat') === 'email')
                                                <x-filament::badge size="sm" color="gray">Email</x-filament::badge>
                                            @endif
                                        </div>

                                        {{-- Contenu --}}
                                        <div class="prose prose-sm dark:prose-invert max-w-none text-success-900 dark:text-success-100">
                                            {!! \Illuminate\Support\Str::markdown($message['content']) !!}
                                        </div>

                                        {{-- Pièces jointes --}}
                                        @if(isset($message['attachments']) && $message['attachments']->count() > 0)
                                            <div class="mt-2 pt-2 border-t border-success-100 dark:border-success-800">
                                                @foreach($message['attachments'] as $attachment)
                                                    <div class="flex items-center gap-2 text-xs text-success-700 dark:text-success-300">
                                                        <x-heroicon-o-paper-clip class="w-3 h-3" />
                                                        <span>{{ $attachment->original_name }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        {{-- Bouton Apprendre et Heure --}}
                                        <div class="flex items-center justify-between mt-2 text-xs text-success-600 dark:text-success-400">
                                            @if(!($message['learned_at'] ?? false))
                                                <x-filament::button
                                                    size="xs"
                                                    color="success"
                                                    icon="heroicon-o-academic-cap"
                                                    x-on:click="showLearnForm = !showLearnForm"
                                                >
                                                    Apprendre
                                                </x-filament::button>
                                            @else
                                                <span></span>
                                            @endif
                                            <span>{{ $message['created_at']->format('H:i') }}</span>
                                        </div>

                                        {{-- Formulaire d'apprentissage --}}
                                        <div x-show="showLearnForm" x-cloak class="mt-3 pt-3 border-t border-success-200 dark:border-success-700 space-y-3">
                                            <div>
                                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Question (du client)</label>
                                                <textarea
                                                    x-model="learnQuestion"
                                                    rows="2"
                                                    class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm"
                                                    placeholder="Question du client..."
                                                ></textarea>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Réponse (à enseigner)</label>
                                                <textarea
                                                    x-model="learnAnswer"
                                                    rows="3"
                                                    class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm"
                                                    placeholder="Réponse à enseigner à l'IA..."
                                                ></textarea>
                                            </div>
                                            <div class="flex gap-2">
                                                <x-filament::button
                                                    size="xs"
                                                    color="success"
                                                    icon="heroicon-o-check"
                                                    x-on:click="$wire.learnFromSupportMessageWithEdit({{ $message['original_id'] }}, learnQuestion, learnAnswer); showLearnForm = false"
                                                >
                                                    Enregistrer
                                                </x-filament::button>
                                                <x-filament::button
                                                    size="xs"
                                                    color="gray"
                                                    x-on:click="showLearnForm = false"
                                                >
                                                    Annuler
                                                </x-filament::button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @empty
                        <div class="flex items-center justify-center h-full text-gray-400">
                            <div class="text-center">
                                <x-heroicon-o-chat-bubble-left-ellipsis class="w-12 h-12 mx-auto mb-2" />
                                <p>Aucun message dans cette session</p>
                            </div>
                        </div>
                    @endforelse
                </div>

                {{-- Zone de réponse Support (si escaladé et autorisé) --}}
                @if($isEscalated && $canHandleSupport && $session->support_status !== 'resolved')
                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        {{-- Suggestion IA --}}
                        @if($this->suggestedResponse)
                            <div class="bg-blue-50 dark:bg-blue-900/30 rounded-lg p-4 mb-4 border border-blue-200 dark:border-blue-700">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center gap-2 text-blue-700 dark:text-blue-300">
                                        <x-heroicon-o-sparkles class="w-5 h-5" />
                                        <span class="font-medium">Suggestion IA</span>
                                    </div>
                                    <div class="flex gap-2">
                                        <x-filament::button wire:click="useSuggestion" size="sm" color="success">
                                            Utiliser
                                        </x-filament::button>
                                        <x-filament::button wire:click="$set('suggestedResponse', null)" size="sm" color="gray">
                                            Ignorer
                                        </x-filament::button>
                                    </div>
                                </div>
                                <div class="prose prose-sm dark:prose-invert max-w-none text-blue-800 dark:text-blue-200">
                                    {!! \Illuminate\Support\Str::markdown($this->suggestedResponse) !!}
                                </div>
                            </div>
                        @endif

                        {{-- Mode Apprentissage Accéléré : Zone verrouillée --}}
                        @if($this->isAcceleratedLearningMode() && !$this->canRespondFreely)
                            <div class="p-4 bg-gray-100 dark:bg-gray-800 rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600">
                                <div class="flex items-center gap-3 text-gray-500 dark:text-gray-400">
                                    <x-heroicon-o-lock-closed class="w-6 h-6" />
                                    <div>
                                        <p class="font-medium">Zone de réponse verrouillée</p>
                                        <p class="text-sm">Utilisez les boutons de la réponse IA ci-dessus pour :</p>
                                        <ul class="text-sm mt-1 list-disc list-inside">
                                            <li><strong>Valider</strong> - Si la réponse est correcte</li>
                                            <li><strong>Corriger</strong> - Pour ajuster avant envoi</li>
                                            <li><strong>Rejeter</strong> - Pour rédiger votre propre réponse</li>
                                        </ul>
                                        @if($session->agent?->allowsSkipInAcceleratedMode())
                                            <div class="mt-3">
                                                <x-filament::button
                                                    wire:click="skipToFreeResponse"
                                                    size="sm"
                                                    color="gray"
                                                    icon="heroicon-o-forward"
                                                >
                                                    Passer (cas exceptionnel)
                                                </x-filament::button>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @elseif($this->isAcceleratedLearningMode() && $this->canRespondFreely)
                            {{-- Mode déverrouillé : zone de réponse libre avec apprentissage --}}
                            <div class="space-y-3">
                                @if($this->rejectedMessageId)
                                    <div class="p-2 bg-primary-50 dark:bg-primary-950 rounded text-xs text-primary-700 dark:text-primary-300">
                                        <x-heroicon-o-academic-cap class="w-4 h-4 inline" />
                                        Votre réponse sera automatiquement indexée pour l'apprentissage de l'IA.
                                    </div>
                                @endif

                                <div class="flex gap-2">
                                    <textarea
                                        wire:model="supportMessage"
                                        rows="3"
                                        class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm resize-none"
                                        placeholder="Rédigez votre réponse..."
                                        wire:keydown.ctrl.enter="{{ $this->rejectedMessageId ? 'sendAndLearn' : 'sendSupportMessage' }}"
                                    ></textarea>
                                    <div class="flex flex-col gap-2">
                                        @if($this->rejectedMessageId)
                                            <x-filament::button
                                                wire:click="sendAndLearn"
                                                icon="heroicon-o-paper-airplane"
                                                color="success"
                                            >
                                                Envoyer et Apprendre
                                            </x-filament::button>
                                        @else
                                            <x-filament::button
                                                wire:click="sendSupportMessage"
                                                icon="heroicon-o-paper-airplane"
                                                color="primary"
                                            >
                                                Envoyer
                                            </x-filament::button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @else
                            {{-- Mode normal : zone de réponse toujours visible --}}
                            <div class="flex gap-2">
                                <textarea
                                    wire:model="supportMessage"
                                    rows="2"
                                    class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm resize-none"
                                    placeholder="Tapez votre réponse au client..."
                                    wire:keydown.ctrl.enter="sendSupportMessage"
                                ></textarea>
                                <div class="flex flex-col gap-2">
                                    <x-filament::button
                                        wire:click="sendSupportMessage"
                                        icon="heroicon-o-paper-airplane"
                                        color="success"
                                    >
                                        Envoyer
                                    </x-filament::button>
                                    <x-filament::button
                                        wire:click="suggestAiResponse"
                                        icon="heroicon-o-sparkles"
                                        color="gray"
                                        size="sm"
                                        title="Demander une suggestion à l'IA"
                                    >
                                        Suggérer
                                    </x-filament::button>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">
                                <kbd class="px-1 py-0.5 rounded bg-gray-100 dark:bg-gray-800">Ctrl+Entrée</kbd> pour envoyer
                            </p>
                        @endif
                    </div>
                @elseif($session->support_status === 'resolved')
                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-center gap-2 text-success-600 dark:text-success-400">
                            <x-heroicon-o-check-circle class="w-5 h-5" />
                            <span>Cette conversation est résolue</span>
                        </div>
                    </div>
                @endif
            </x-filament::section>
        </div>
    </div>

    {{-- WebSocket Soketi for real-time updates --}}
    @push('scripts')
    <style>
        /* Escalation Toast Notification */
        .escalation-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s ease;
        }

        .escalation-toast.visible {
            opacity: 1;
            transform: translateX(0);
        }

        .escalation-toast-content {
            display: flex;
            align-items: center;
            gap: 12px;
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            color: white;
            padding: 16px 20px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(249, 115, 22, 0.3);
            cursor: pointer;
            min-width: 300px;
            max-width: 400px;
        }

        .escalation-toast-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .escalation-toast-text {
            flex-grow: 1;
        }

        .escalation-toast-text strong {
            display: block;
            font-size: 14px;
            margin-bottom: 2px;
        }

        .escalation-toast-text span {
            font-size: 12px;
            opacity: 0.9;
        }

        .escalation-toast-link {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            flex-shrink: 0;
            transition: background 0.2s;
        }

        .escalation-toast-link:hover {
            background: rgba(255, 255, 255, 0.3);
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/pusher-js@8.3.0/dist/web/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.15.3/dist/echo.iife.js"></script>
    <script>
        (function() {
            // Soketi config from Laravel
            var soketiConfig = {
                key: @json(config('broadcasting.connections.pusher.key')),
                frontendHost: @json(config('broadcasting.connections.pusher.frontend.host')) || window.location.hostname,
                frontendPort: @json(config('broadcasting.connections.pusher.frontend.port')) || (window.location.protocol === 'https:' ? 443 : 80),
                frontendScheme: @json(config('broadcasting.connections.pusher.frontend.scheme')) || window.location.protocol.replace(':', ''),
                cluster: @json(config('broadcasting.connections.pusher.options.cluster', 'mt1'))
            };

            // ═══════════════════════════════════════════════════════════════
            // SOKETI DEBUG - Admin Panel
            // ═══════════════════════════════════════════════════════════════
            console.group('🔌 SOKETI WEBSOCKET DEBUG (Admin)');
            console.log('📋 Configuration:', JSON.stringify(soketiConfig, null, 2));
            console.log('🔑 Clé Soketi:', soketiConfig.key || '(vide)');
            console.log('🏠 Host:', soketiConfig.frontendHost);
            console.log('🚪 Port:', soketiConfig.frontendPort);
            console.log('🔒 Scheme:', soketiConfig.frontendScheme);

            var expectedWsUrl = (soketiConfig.frontendScheme === 'https' ? 'wss://' : 'ws://') +
                soketiConfig.frontendHost + '/app/' + soketiConfig.key;
            console.log('🔗 URL WebSocket:', expectedWsUrl);
            console.log('📦 Echo:', typeof Echo !== 'undefined' ? '✅' : '❌');
            console.log('📦 Pusher:', typeof Pusher !== 'undefined' ? '✅' : '❌');
            console.groupEnd();

            // Mock channel for fallback
            var mockChannel = {
                listen: function() { return this; },
                listenForWhisper: function() { return this; },
                notification: function() { return this; },
                stopListening: function() { return this; },
                stopListeningForWhisper: function() { return this; },
                subscribed: function() { return this; },
                error: function() { return this; }
            };

            if (typeof Echo !== 'undefined' && typeof Pusher !== 'undefined' && soketiConfig.key && soketiConfig.key !== 'app-key') {
                Pusher.logToConsole = true;

                var useTLS = soketiConfig.frontendScheme === 'https';

                // Get CSRF token for auth endpoint
                var csrfToken = document.querySelector('meta[name="csrf-token"]');
                var authHeaders = {};
                if (csrfToken) {
                    authHeaders['X-CSRF-TOKEN'] = csrfToken.getAttribute('content');
                }

                var echoConfig = {
                    broadcaster: 'pusher',
                    key: soketiConfig.key,
                    wsHost: soketiConfig.frontendHost,
                    wsPort: useTLS ? 443 : soketiConfig.frontendPort,
                    wssPort: useTLS ? 443 : soketiConfig.frontendPort,
                    forceTLS: useTLS,
                    encrypted: useTLS,
                    disableStats: true,
                    enabledTransports: ['ws', 'wss'],
                    cluster: soketiConfig.cluster,
                    // Required for presence channels authentication
                    authEndpoint: '/broadcasting/auth',
                    auth: {
                        headers: authHeaders
                    }
                };

                console.log('🔧 Echo Config:', JSON.stringify(echoConfig, null, 2));

                window.Echo = new Echo(echoConfig);

                window.Echo.connector.pusher.connection.bind('connecting', function() {
                    console.log('🔄 Soketi: CONNECTING...');
                });

                window.Echo.connector.pusher.connection.bind('connected', function() {
                    console.log('✅ Soketi: CONNECTED !');
                });

                window.Echo.connector.pusher.connection.bind('error', function(err) {
                    console.error('❌ Soketi: ERROR', err);
                });

                window.Echo.connector.pusher.connection.bind('unavailable', function() {
                    console.warn('⚠️ Soketi: UNAVAILABLE');
                });

                window.Echo.connector.pusher.connection.bind('state_change', function(states) {
                    console.log('🔀 Soketi:', states.previous, '→', states.current);
                });

            } else {
                console.group('⚠️ SOKETI NON CONFIGURÉ (Admin)');
                if (!soketiConfig.key) console.warn('   Clé vide');
                if (soketiConfig.key === 'app-key') console.warn('   Clé par défaut');
                console.groupEnd();

                window.Echo = {
                    channel: function() { return mockChannel; },
                    private: function() { return mockChannel; },
                    encryptedPrivate: function() { return mockChannel; },
                    presence: function() { return mockChannel; },
                    join: function() { return mockChannel; },
                    leave: function() {},
                    leaveChannel: function() {},
                    leaveAllChannels: function() {},
                    socketId: function() { return null; },
                    connector: {
                        pusher: {
                            connection: {
                                state: 'unavailable',
                                bind: function() {}
                            }
                        }
                    }
                };
            }

            // Helper pour vérifier le statut
            window.soketiStatus = function() {
                console.group('📊 SOKETI STATUS (Admin)');
                if (window.Echo && window.Echo.connector && window.Echo.connector.pusher) {
                    console.log('État:', window.Echo.connector.pusher.connection.state);
                    console.log('Socket ID:', window.Echo.socketId());
                } else {
                    console.log('Mode: Mock/Polling');
                }
                console.groupEnd();
            };
            console.log('💡 Tapez soketiStatus() pour voir l\'état');

            // ═══════════════════════════════════════════════════════════════
            // ÉCOUTE DES MESSAGES EN TEMPS RÉEL
            // ═══════════════════════════════════════════════════════════════
            var sessionUuid = @json($session->uuid);
            var channelName = 'chat.session.' + sessionUuid;

            console.log('📡 Subscribing to channel:', channelName);

            // S'abonner au canal de la session pour les messages en temps réel
            window.Echo.channel(channelName)
                .listen('.user.message', function(data) {
                    console.log('📨 User message received via WebSocket:', data);
                    // Rafraîchir le composant Livewire pour afficher le nouveau message
                    if (typeof Livewire !== 'undefined') {
                        Livewire.dispatch('refreshMessages');
                    }
                })
                .listen('.completed', function(data) {
                    console.log('🤖 AI response received via WebSocket:', data);
                    // Rafraîchir le composant Livewire pour afficher la réponse IA
                    if (typeof Livewire !== 'undefined') {
                        Livewire.dispatch('refreshMessages');
                    }
                })
                .listen('.failed', function(data) {
                    console.log('❌ AI message failed via WebSocket:', data);
                    if (typeof Livewire !== 'undefined') {
                        Livewire.dispatch('refreshMessages');
                    }
                })
                .listen('.message.new', function(data) {
                    console.log('💬 Support message received via WebSocket:', data);
                    // Rafraîchir pour afficher le nouveau message de support
                    if (typeof Livewire !== 'undefined') {
                        Livewire.dispatch('refreshMessages');
                    }
                })
                .listen('.session.assigned', function(data) {
                    console.log('👤 Session assigned via WebSocket:', data);
                    // Rafraîchir pour mettre à jour le statut
                    if (typeof Livewire !== 'undefined') {
                        Livewire.dispatch('refreshSession');
                        Livewire.dispatch('refreshMessages');
                    }
                })
                .listen('.session.escalated', function(data) {
                    console.log('🚨 Session escalated via WebSocket:', data);
                    // Rafraîchir pour mettre à jour le statut
                    if (typeof Livewire !== 'undefined') {
                        Livewire.dispatch('refreshSession');
                    }
                });

            console.log('✅ WebSocket listeners registered for session:', sessionUuid);

            // Note: La présence des agents est gérée globalement dans global-escalation-listener.blade.php
            // Quand un admin se connecte à l'app, il rejoint automatiquement les canaux de présence

            // ═══════════════════════════════════════════════════════════════
            // ÉCOUTE GLOBALE DES ESCALADES (notifications admin)
            // ═══════════════════════════════════════════════════════════════
            window.Echo.channel('admin.escalations')
                .listen('.session.escalated', function(data) {
                    console.log('🚨 New escalation notification:', data);

                    // Ne pas notifier si c'est la session actuelle (déjà affichée)
                    if (data.session_uuid === sessionUuid) {
                        return;
                    }

                    // Show toast notification
                    showEscalationToast(data);

                    // Browser notification si autorisé
                    if (Notification.permission === 'granted') {
                        var notif = new Notification('🚨 Nouvelle demande de support', {
                            body: (data.user_name || 'Un utilisateur') + ' - ' + (data.agent_name || 'Agent'),
                            icon: '/favicon.ico',
                            tag: 'escalation-' + data.session_id
                        });
                        notif.onclick = function() {
                            window.focus();
                            window.location.href = '/admin/ai-sessions/' + data.session_id;
                        };
                    } else if (Notification.permission !== 'denied') {
                        Notification.requestPermission();
                    }
                });

            // Fonction pour afficher un toast d'escalade
            function showEscalationToast(data) {
                var toast = document.createElement('div');
                toast.className = 'escalation-toast';
                toast.innerHTML = '<div class="escalation-toast-content">' +
                    '<div class="escalation-toast-icon">🚨</div>' +
                    '<div class="escalation-toast-text">' +
                    '<strong>Nouvelle demande de support</strong><br>' +
                    '<span>' + (data.user_name || 'Visiteur') + ' - ' + (data.agent_name || 'Agent') + '</span>' +
                    '</div>' +
                    '<a href="/admin/ai-sessions/' + data.session_id + '" class="escalation-toast-link">Voir →</a>' +
                    '</div>';

                document.body.appendChild(toast);

                // Animation d'entrée
                setTimeout(function() {
                    toast.classList.add('visible');
                }, 10);

                // Disparaître après 10 secondes
                setTimeout(function() {
                    toast.classList.remove('visible');
                    setTimeout(function() {
                        toast.remove();
                    }, 300);
                }, 10000);

                // Clic pour fermer
                toast.addEventListener('click', function(e) {
                    if (e.target.tagName !== 'A') {
                        toast.remove();
                    }
                });
            }

            console.log('✅ Global escalation listener registered');

            // ═══════════════════════════════════════════════════════════════
            // AUTO-SCROLL AU BAS DE LA CONVERSATION
            // ═══════════════════════════════════════════════════════════════
            function scrollToBottom() {
                var chatContainer = document.getElementById('chat-messages');
                if (chatContainer) {
                    chatContainer.scrollTop = chatContainer.scrollHeight;
                }
            }

            // Scroll au chargement initial
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(scrollToBottom, 100);
            });

            // Scroll après chaque refresh Livewire
            document.addEventListener('livewire:navigated', scrollToBottom);

            // Écouter les événements Livewire pour scroll après refresh
            if (typeof Livewire !== 'undefined') {
                Livewire.hook('message.processed', function() {
                    setTimeout(scrollToBottom, 50);
                });
            }

            // Scroll initial si la page est déjà chargée
            if (document.readyState === 'complete') {
                scrollToBottom();
            }
        })();
    </script>
    @endpush
</x-filament-panels::page>

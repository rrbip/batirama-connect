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
                                <div class="max-w-[75%]">
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

                        {{-- Message IA (gauche, gris) --}}
                        @elseif($isAi)
                            <div class="flex justify-start" x-data="{ showCorrection: false, correctedContent: @js($message['content']) }">
                                <div class="max-w-[75%]">
                                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-3 shadow-sm">
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
                                        </div>

                                        {{-- Contenu --}}
                                        <div class="prose prose-sm dark:prose-invert max-w-none">
                                            @if($message['validation_status'] === 'learned' && $message['corrected_content'])
                                                <div class="mb-2 p-2 bg-primary-50 dark:bg-primary-950 rounded text-xs">
                                                    <strong>Contenu corrigé</strong>
                                                </div>
                                                {!! \Illuminate\Support\Str::markdown($message['corrected_content']) !!}
                                            @else
                                                {!! \Illuminate\Support\Str::markdown($message['content'] ?? '') !!}
                                            @endif
                                        </div>

                                        {{-- Métadonnées --}}
                                        <div class="flex items-center justify-between mt-2 text-xs text-gray-400">
                                            <span>{{ $message['created_at']->format('H:i') }}</span>
                                            <div class="flex items-center gap-2">
                                                @if($message['model_used'])
                                                    <span>{{ $message['model_used'] }}</span>
                                                @endif
                                                @if($message['tokens'])
                                                    <span>{{ $message['tokens'] }} tokens</span>
                                                @endif
                                            </div>
                                        </div>

                                        {{-- Boutons de validation (si en attente) --}}
                                        @if($message['is_pending_validation'])
                                            <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                                                <div class="flex flex-wrap gap-2">
                                                    <x-filament::button
                                                        size="xs"
                                                        color="success"
                                                        icon="heroicon-o-check"
                                                        wire:click="validateMessage({{ $message['original_id'] }})"
                                                    >
                                                        Valider
                                                    </x-filament::button>

                                                    <x-filament::button
                                                        size="xs"
                                                        color="primary"
                                                        icon="heroicon-o-pencil"
                                                        x-on:click="showCorrection = !showCorrection"
                                                    >
                                                        Corriger
                                                    </x-filament::button>

                                                    <x-filament::button
                                                        size="xs"
                                                        color="danger"
                                                        icon="heroicon-o-x-mark"
                                                        wire:click="rejectMessage({{ $message['original_id'] }})"
                                                    >
                                                        Rejeter
                                                    </x-filament::button>
                                                </div>

                                                {{-- Formulaire de correction --}}
                                                <div x-show="showCorrection" x-cloak class="mt-3 space-y-2">
                                                    <textarea
                                                        x-model="correctedContent"
                                                        rows="4"
                                                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm"
                                                        placeholder="Entrez la réponse corrigée..."
                                                    ></textarea>
                                                    <div class="flex gap-2">
                                                        <x-filament::button
                                                            size="xs"
                                                            color="primary"
                                                            x-on:click="$wire.learnFromMessage({{ $message['original_id'] }}, correctedContent); showCorrection = false"
                                                        >
                                                            Enregistrer
                                                        </x-filament::button>
                                                        <x-filament::button
                                                            size="xs"
                                                            color="gray"
                                                            x-on:click="showCorrection = false"
                                                        >
                                                            Annuler
                                                        </x-filament::button>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif

                                        {{-- Bouton contexte RAG --}}
                                        @if(!empty($message['rag_context']))
                                            <button
                                                type="button"
                                                x-data
                                                x-on:click="$dispatch('open-modal', { id: 'context-modal-{{ $message['id'] }}' })"
                                                class="mt-2 inline-flex items-center gap-1 px-2 py-1 text-xs text-gray-500 hover:text-gray-700 dark:hover:text-gray-300"
                                            >
                                                <x-heroicon-o-document-magnifying-glass class="w-3 h-3" />
                                                Voir le contexte
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>

                        {{-- Message Support Agent (gauche, vert) --}}
                        @elseif($isSupport)
                            <div class="flex justify-start">
                                <div class="max-w-[75%]">
                                    <div class="bg-success-50 dark:bg-success-950 border border-success-200 dark:border-success-800 rounded-lg p-3 shadow-sm">
                                        {{-- Header Support --}}
                                        <div class="flex items-center gap-2 mb-2 pb-2 border-b border-success-100 dark:border-success-800">
                                            <x-heroicon-o-user-circle class="w-4 h-4 text-success-600" />
                                            <span class="text-xs font-medium text-success-700 dark:text-success-300">{{ $message['sender_name'] }}</span>
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

                                        {{-- Heure --}}
                                        <div class="flex items-center justify-end mt-2 text-xs text-success-600 dark:text-success-400">
                                            <span>{{ $message['created_at']->format('H:i') }}</span>
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

                        {{-- Formulaire de réponse --}}
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
</x-filament-panels::page>

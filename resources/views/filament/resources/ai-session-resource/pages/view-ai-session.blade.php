<x-filament-panels::page>
    @php
        $session = $this->record;
        $messages = $this->getMessages();
        $stats = $this->getSessionStats();
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        {{-- Sidebar: Infos Session --}}
        <div class="lg:col-span-1 space-y-4">
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
                        <span class="text-gray-500 dark:text-gray-400">Statut</span>
                        <x-filament::badge color="{{ match($session->status) {
                            'active' => 'success',
                            'archived' => 'gray',
                            'deleted' => 'danger',
                            default => 'gray'
                        } }}">
                            {{ $session->status }}
                        </x-filament::badge>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Créé le</span>
                        <span class="font-medium">{{ $session->created_at->format('d/m/Y H:i') }}</span>
                    </div>
                </div>
            </x-filament::section>

            {{-- Stats --}}
            <x-filament::section>
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
                        <span class="text-gray-500 dark:text-gray-400">Tokens utilisés</span>
                        <span class="font-medium">{{ number_format($stats['total_tokens']) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">Temps moyen</span>
                        <span class="font-medium">{{ $stats['avg_generation_time'] }}ms</span>
                    </div>
                </div>
            </x-filament::section>

            {{-- Validation Stats --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-clipboard-document-check class="w-5 h-5" />
                        Validation
                    </div>
                </x-slot>

                <div class="space-y-3 text-sm">
                    @if($stats['pending_validation'] > 0)
                        <div class="flex justify-between items-center">
                            <span class="text-warning-600 dark:text-warning-400">En attente</span>
                            <x-filament::badge color="warning">{{ $stats['pending_validation'] }}</x-filament::badge>
                        </div>
                    @endif
                    @if($stats['validated'] > 0)
                        <div class="flex justify-between items-center">
                            <span class="text-success-600 dark:text-success-400">Validées</span>
                            <x-filament::badge color="success">{{ $stats['validated'] }}</x-filament::badge>
                        </div>
                    @endif
                    @if($stats['learned'] > 0)
                        <div class="flex justify-between items-center">
                            <span class="text-primary-600 dark:text-primary-400">Apprises</span>
                            <x-filament::badge color="primary">{{ $stats['learned'] }}</x-filament::badge>
                        </div>
                    @endif
                    @if($stats['rejected'] > 0)
                        <div class="flex justify-between items-center">
                            <span class="text-danger-600 dark:text-danger-400">Rejetées</span>
                            <x-filament::badge color="danger">{{ $stats['rejected'] }}</x-filament::badge>
                        </div>
                    @endif
                    @if($stats['pending_validation'] == 0 && $stats['validated'] == 0 && $stats['learned'] == 0 && $stats['rejected'] == 0)
                        <p class="text-gray-500 text-center">Aucune réponse IA</p>
                    @endif
                </div>
            </x-filament::section>
        </div>

        {{-- Main: Conversation --}}
        <div class="lg:col-span-3">
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-chat-bubble-left-right class="w-5 h-5" />
                            Conversation
                        </div>
                        <span class="text-sm text-gray-500">{{ $messages->count() }} messages</span>
                    </div>
                </x-slot>

                <div class="space-y-4 max-h-[600px] overflow-y-auto p-2">
                    @forelse($messages as $message)
                        @php
                            $isUser = $message->role === 'user';
                            $isAssistant = $message->role === 'assistant';
                            $isPending = $message->validation_status === 'pending';
                            $isValidated = $message->validation_status === 'validated';
                            $isLearned = $message->validation_status === 'learned';
                            $isRejected = $message->validation_status === 'rejected';
                        @endphp

                        <div class="flex {{ $isUser ? 'justify-end' : 'justify-start' }}"
                             x-data="{ showCorrection: false, correctedContent: @js($message->content) }">
                            <div class="max-w-[85%] {{ $isUser
                                ? 'bg-primary-500 text-white'
                                : 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700'
                            }} rounded-lg p-4 shadow-sm">

                                {{-- Header avec badge de validation --}}
                                @if($isAssistant)
                                    <div class="flex items-center gap-2 mb-2 pb-2 border-b border-gray-100 dark:border-gray-700">
                                        <x-heroicon-o-cpu-chip class="w-4 h-4 text-gray-400" />
                                        <span class="text-xs text-gray-500">{{ $session->agent?->name }}</span>

                                        @if($isPending)
                                            <x-filament::badge color="warning" size="sm">En attente</x-filament::badge>
                                        @elseif($isValidated)
                                            <x-filament::badge color="success" size="sm">Validée</x-filament::badge>
                                        @elseif($isLearned)
                                            <x-filament::badge color="primary" size="sm">Apprise</x-filament::badge>
                                        @elseif($isRejected)
                                            <x-filament::badge color="danger" size="sm">Rejetée</x-filament::badge>
                                        @endif
                                    </div>
                                @endif

                                {{-- Contenu --}}
                                <div class="prose prose-sm dark:prose-invert max-w-none {{ $isUser ? 'prose-invert' : '' }}">
                                    @if($isLearned && $message->corrected_content)
                                        <div class="mb-2 p-2 bg-primary-50 dark:bg-primary-950 rounded text-xs">
                                            <strong>Contenu corrigé :</strong>
                                        </div>
                                        {!! \Illuminate\Support\Str::markdown($message->corrected_content) !!}
                                        <details class="mt-2">
                                            <summary class="text-xs text-gray-500 cursor-pointer">Voir l'original</summary>
                                            <div class="mt-2 p-2 bg-gray-100 dark:bg-gray-900 rounded text-sm opacity-75">
                                                {!! \Illuminate\Support\Str::markdown($message->content) !!}
                                            </div>
                                        </details>
                                    @else
                                        {!! \Illuminate\Support\Str::markdown($message->content) !!}
                                    @endif
                                </div>

                                {{-- Métadonnées --}}
                                <div class="flex items-center justify-between mt-3 pt-2 border-t {{ $isUser ? 'border-primary-400' : 'border-gray-100 dark:border-gray-700' }}">
                                    <span class="text-xs {{ $isUser ? 'text-primary-200' : 'text-gray-400' }}">
                                        {{ $message->created_at?->format('H:i') }}
                                    </span>

                                    @if($isAssistant && $message->tokens_completion)
                                        <span class="text-xs text-gray-400">
                                            {{ $message->tokens_prompt + $message->tokens_completion }} tokens
                                            · {{ $message->generation_time_ms }}ms
                                        </span>
                                    @endif
                                </div>

                                {{-- Sources RAG --}}
                                @if($isAssistant && !empty($message->rag_context['sources']))
                                    <details class="mt-2">
                                        <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-700">
                                            {{ count($message->rag_context['sources']) }} source(s) utilisée(s)
                                        </summary>
                                        <ul class="mt-1 text-xs text-gray-500 space-y-1">
                                            @foreach($message->rag_context['sources'] as $source)
                                                <li class="flex items-center gap-1">
                                                    <x-heroicon-o-document class="w-3 h-3" />
                                                    {{ \Illuminate\Support\Str::limit($source['content'] ?? 'Document', 100) }}
                                                    <span class="text-gray-400">({{ number_format($source['score'] ?? 0, 2) }})</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif

                                {{-- Boutons d'action pour réponses en attente --}}
                                @if($isAssistant && $isPending)
                                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                                        <div class="flex flex-wrap gap-2">
                                            <x-filament::button
                                                size="xs"
                                                color="success"
                                                icon="heroicon-o-check"
                                                wire:click="validateMessage({{ $message->id }})"
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
                                                wire:click="rejectMessage({{ $message->id }})"
                                            >
                                                Rejeter
                                            </x-filament::button>
                                        </div>

                                        {{-- Formulaire de correction --}}
                                        <div x-show="showCorrection" x-cloak class="mt-3 space-y-2">
                                            <textarea
                                                x-model="correctedContent"
                                                rows="6"
                                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm"
                                                placeholder="Entrez la réponse corrigée..."
                                            ></textarea>
                                            <div class="flex gap-2">
                                                <x-filament::button
                                                    size="xs"
                                                    color="primary"
                                                    x-on:click="$wire.learnFromMessage({{ $message->id }}, correctedContent); showCorrection = false"
                                                >
                                                    Enregistrer et apprendre
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

                                {{-- Info validation --}}
                                @if($isAssistant && ($isValidated || $isLearned || $isRejected) && $message->validated_at)
                                    <div class="mt-2 text-xs text-gray-400">
                                        {{ $isValidated ? 'Validée' : ($isLearned ? 'Corrigée' : 'Rejetée') }}
                                        par {{ $message->validator?->name ?? 'Système' }}
                                        le {{ $message->validated_at->format('d/m/Y à H:i') }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="flex items-center justify-center h-32 text-gray-400">
                            <div class="text-center">
                                <x-heroicon-o-chat-bubble-left-ellipsis class="w-12 h-12 mx-auto mb-2" />
                                <p>Aucun message dans cette session</p>
                            </div>
                        </div>
                    @endforelse
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>

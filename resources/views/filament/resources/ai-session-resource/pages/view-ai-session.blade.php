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

                                {{-- Contexte utilisé par l'IA --}}
                                @if($isAssistant && !empty($message->rag_context))
                                    @php
                                        $context = $message->rag_context;
                                        $learnedSources = $context['learned_sources'] ?? [];
                                        $documentSources = $context['document_sources'] ?? [];
                                        $stats = $context['stats'] ?? [];
                                        $totalSources = count($learnedSources) + count($documentSources);
                                    @endphp

                                    @if($totalSources > 0)
                                        <details class="mt-3 border border-gray-200 dark:border-gray-700 rounded-lg">
                                            <summary class="p-2 text-xs text-gray-600 dark:text-gray-400 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg flex items-center gap-2">
                                                <x-heroicon-o-document-magnifying-glass class="w-4 h-4" />
                                                <span>{{ $totalSources }} source(s) utilisée(s) par l'IA</span>
                                                @if(!empty($stats))
                                                    <span class="text-gray-400">
                                                        ({{ $stats['learned_count'] ?? 0 }} apprises, {{ $stats['document_count'] ?? 0 }} docs)
                                                    </span>
                                                @endif
                                            </summary>

                                            <div class="p-3 space-y-4 text-xs border-t border-gray-200 dark:border-gray-700">
                                                {{-- Cas similaires appris --}}
                                                @if(!empty($learnedSources))
                                                    <div>
                                                        <h5 class="font-semibold text-primary-600 dark:text-primary-400 mb-2 flex items-center gap-1">
                                                            <x-heroicon-o-academic-cap class="w-4 h-4" />
                                                            Cas similaires traités ({{ count($learnedSources) }})
                                                        </h5>
                                                        <div class="space-y-2">
                                                            @foreach($learnedSources as $learned)
                                                                <div class="p-2 bg-primary-50 dark:bg-primary-950 rounded border-l-2 border-primary-500">
                                                                    <div class="flex items-center justify-between mb-1">
                                                                        <span class="font-medium">Cas #{{ $learned['index'] }}</span>
                                                                        <x-filament::badge size="sm" color="primary">
                                                                            {{ $learned['score'] }}% similaire
                                                                        </x-filament::badge>
                                                                    </div>
                                                                    <div class="text-gray-600 dark:text-gray-400">
                                                                        <strong>Q:</strong> {{ \Illuminate\Support\Str::limit($learned['question'], 150) }}
                                                                    </div>
                                                                    <details class="mt-1">
                                                                        <summary class="cursor-pointer text-primary-600 hover:underline">Voir la réponse validée</summary>
                                                                        <div class="mt-1 p-2 bg-white dark:bg-gray-900 rounded text-gray-700 dark:text-gray-300">
                                                                            {{ $learned['answer'] }}
                                                                        </div>
                                                                    </details>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif

                                                {{-- Documents RAG --}}
                                                @if(!empty($documentSources))
                                                    <div>
                                                        <h5 class="font-semibold text-info-600 dark:text-info-400 mb-2 flex items-center gap-1">
                                                            <x-heroicon-o-document-text class="w-4 h-4" />
                                                            Documents indexés ({{ count($documentSources) }})
                                                        </h5>
                                                        <div class="space-y-2">
                                                            @foreach($documentSources as $doc)
                                                                <div class="p-2 bg-gray-50 dark:bg-gray-900 rounded border-l-2 border-info-500">
                                                                    <div class="flex items-center justify-between mb-1">
                                                                        <span class="font-medium">Document #{{ $doc['index'] }}</span>
                                                                        <x-filament::badge size="sm" color="info">
                                                                            {{ $doc['score'] }}% pertinent
                                                                        </x-filament::badge>
                                                                    </div>
                                                                    <details>
                                                                        <summary class="cursor-pointer text-info-600 hover:underline">Voir le contenu</summary>
                                                                        <div class="mt-1 p-2 bg-white dark:bg-gray-800 rounded text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $doc['content'] }}</div>
                                                                    </details>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif

                                                {{-- Prompt système complet --}}
                                                @if(!empty($context['system_prompt_sent']))
                                                    @php
                                                        $promptSections = preg_split('/(?=## )/', $context['system_prompt_sent']);
                                                        $promptSections = array_filter($promptSections, fn($s) => trim($s) !== '');
                                                        $promptSections = array_values($promptSections);
                                                    @endphp
                                                    <div>
                                                        <details>
                                                            <summary class="cursor-pointer text-gray-500 hover:text-gray-700 flex items-center gap-1">
                                                                <x-heroicon-o-command-line class="w-4 h-4" />
                                                                Voir le prompt système complet envoyé ({{ count($promptSections) }} sections)
                                                            </summary>
                                                            <div class="mt-2 space-y-3">
                                                                @foreach($promptSections as $index => $section)
                                                                    @php
                                                                        $section = trim($section);
                                                                        $isSystemPrompt = $index === 0 && !str_starts_with($section, '## ');
                                                                        $sectionTitle = '';
                                                                        $sectionContent = $section;

                                                                        if (preg_match('/^## (.+?)[\r\n]/', $section, $matches)) {
                                                                            $sectionTitle = trim($matches[1]);
                                                                            $sectionContent = trim(substr($section, strlen($matches[0])));
                                                                        } elseif ($isSystemPrompt) {
                                                                            $sectionTitle = 'Instructions Agent';
                                                                        }

                                                                        $isSimilaires = str_contains($sectionTitle, 'SIMILAIRES');
                                                                        $isDocumentaire = str_contains($sectionTitle, 'DOCUMENTAIRE');
                                                                        $isHistorique = str_contains($sectionTitle, 'HISTORIQUE');
                                                                        $isInstructions = str_contains($sectionTitle, 'Instructions');
                                                                    @endphp

                                                                    @if($isSimilaires)
                                                                        <div class="border border-primary-200 dark:border-primary-800 rounded-lg overflow-hidden">
                                                                            <div class="bg-primary-50 dark:bg-primary-950 px-3 py-2 flex items-center gap-2 border-b border-primary-200 dark:border-primary-800">
                                                                                <x-heroicon-o-academic-cap class="w-4 h-4 text-primary-600 dark:text-primary-400" />
                                                                                <span class="font-semibold text-primary-700 dark:text-primary-300">{{ $sectionTitle }}</span>
                                                                            </div>
                                                                            <div class="p-3 bg-white dark:bg-gray-900 text-xs text-gray-700 dark:text-gray-300 whitespace-pre-wrap max-h-48 overflow-y-auto">{{ $sectionContent }}</div>
                                                                        </div>
                                                                    @elseif($isDocumentaire)
                                                                        <div class="border border-info-200 dark:border-info-800 rounded-lg overflow-hidden">
                                                                            <div class="bg-info-50 dark:bg-info-950 px-3 py-2 flex items-center gap-2 border-b border-info-200 dark:border-info-800">
                                                                                <x-heroicon-o-document-text class="w-4 h-4 text-info-600 dark:text-info-400" />
                                                                                <span class="font-semibold text-info-700 dark:text-info-300">{{ $sectionTitle }}</span>
                                                                            </div>
                                                                            <div class="p-3 bg-white dark:bg-gray-900 text-xs text-gray-700 dark:text-gray-300 whitespace-pre-wrap max-h-48 overflow-y-auto">{{ $sectionContent }}</div>
                                                                        </div>
                                                                    @elseif($isHistorique)
                                                                        <div class="border border-warning-200 dark:border-warning-800 rounded-lg overflow-hidden">
                                                                            <div class="bg-warning-50 dark:bg-warning-950 px-3 py-2 flex items-center gap-2 border-b border-warning-200 dark:border-warning-800">
                                                                                <x-heroicon-o-chat-bubble-left-right class="w-4 h-4 text-warning-600 dark:text-warning-400" />
                                                                                <span class="font-semibold text-warning-700 dark:text-warning-300">{{ $sectionTitle }}</span>
                                                                            </div>
                                                                            <div class="p-3 bg-white dark:bg-gray-900 text-xs text-gray-700 dark:text-gray-300 whitespace-pre-wrap max-h-48 overflow-y-auto">{{ $sectionContent }}</div>
                                                                        </div>
                                                                    @elseif($isInstructions)
                                                                        <div class="border border-success-200 dark:border-success-800 rounded-lg overflow-hidden">
                                                                            <div class="bg-success-50 dark:bg-success-950 px-3 py-2 flex items-center gap-2 border-b border-success-200 dark:border-success-800">
                                                                                <x-heroicon-o-cog-6-tooth class="w-4 h-4 text-success-600 dark:text-success-400" />
                                                                                <span class="font-semibold text-success-700 dark:text-success-300">{{ $sectionTitle }}</span>
                                                                            </div>
                                                                            <div class="p-3 bg-white dark:bg-gray-900 text-xs text-gray-700 dark:text-gray-300 whitespace-pre-wrap max-h-48 overflow-y-auto">{{ $sectionContent }}</div>
                                                                        </div>
                                                                    @else
                                                                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                                                                            <div class="bg-gray-50 dark:bg-gray-800 px-3 py-2 flex items-center gap-2 border-b border-gray-200 dark:border-gray-700">
                                                                                <x-heroicon-o-document class="w-4 h-4 text-gray-600 dark:text-gray-400" />
                                                                                <span class="font-semibold text-gray-700 dark:text-gray-300">{{ $sectionTitle ?: 'Section ' . ($index + 1) }}</span>
                                                                            </div>
                                                                            <div class="p-3 bg-white dark:bg-gray-900 text-xs text-gray-700 dark:text-gray-300 whitespace-pre-wrap max-h-48 overflow-y-auto">{{ $sectionContent }}</div>
                                                                        </div>
                                                                    @endif
                                                                @endforeach
                                                            </div>
                                                        </details>
                                                    </div>
                                                @endif
                                            </div>
                                        </details>
                                    @endif
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

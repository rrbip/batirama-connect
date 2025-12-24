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
                        <span class="text-gray-500 dark:text-gray-400">Cree le</span>
                        <span class="font-medium">{{ $session->created_at->format('d/m/Y H:i') }}</span>
                    </div>
                </div>

                @if($session->uuid)
                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <div class="text-xs text-gray-500">
                            Session: {{ Str::limit($session->uuid, 8) }}
                        </div>
                    </div>
                @endif
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
                        <span class="text-gray-500 dark:text-gray-400">Tokens utilises</span>
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
                            <span class="text-success-600 dark:text-success-400">Validees</span>
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
                            <span class="text-danger-600 dark:text-danger-400">Rejetees</span>
                            <x-filament::badge color="danger">{{ $stats['rejected'] }}</x-filament::badge>
                        </div>
                    @endif
                    @if($stats['pending_validation'] == 0 && $stats['validated'] == 0 && $stats['learned'] == 0 && $stats['rejected'] == 0)
                        <p class="text-gray-500 text-center">Aucune reponse IA</p>
                    @endif
                </div>
            </x-filament::section>

            {{-- Guide des actions --}}
            <x-filament::section collapsible collapsed>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-question-mark-circle class="w-5 h-5" />
                        Guide des actions
                    </div>
                </x-slot>

                <div class="space-y-4 text-xs">
                    {{-- Valider --}}
                    <div class="flex gap-3">
                        <div class="flex-shrink-0">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded bg-success-100 dark:bg-success-900">
                                <x-heroicon-o-check class="w-4 h-4 text-success-600 dark:text-success-400" />
                            </span>
                        </div>
                        <div>
                            <p class="font-semibold text-success-600 dark:text-success-400">Valider</p>
                            <p class="text-gray-500 dark:text-gray-400">
                                Marque la reponse comme correcte. Utile pour le suivi qualite mais n'impacte pas les futures reponses.
                            </p>
                        </div>
                    </div>

                    {{-- Corriger --}}
                    <div class="flex gap-3">
                        <div class="flex-shrink-0">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded bg-primary-100 dark:bg-primary-900">
                                <x-heroicon-o-pencil class="w-4 h-4 text-primary-600 dark:text-primary-400" />
                            </span>
                        </div>
                        <div>
                            <p class="font-semibold text-primary-600 dark:text-primary-400">Corriger</p>
                            <p class="text-gray-500 dark:text-gray-400">
                                Modifie la reponse et l'indexe pour l'apprentissage. Les futures questions similaires beneficieront de cette correction via le RAG.
                            </p>
                        </div>
                    </div>

                    {{-- Rejeter --}}
                    <div class="flex gap-3">
                        <div class="flex-shrink-0">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded bg-danger-100 dark:bg-danger-900">
                                <x-heroicon-o-x-mark class="w-4 h-4 text-danger-600 dark:text-danger-400" />
                            </span>
                        </div>
                        <div>
                            <p class="font-semibold text-danger-600 dark:text-danger-400">Rejeter</p>
                            <p class="text-gray-500 dark:text-gray-400">
                                Marque la reponse comme incorrecte. Utile pour identifier les problemes mais n'indexe rien.
                            </p>
                        </div>
                    </div>

                    {{-- Info supplementaire --}}
                    <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
                        <p class="text-gray-400 dark:text-gray-500 italic">
                            Seul "Corriger" ameliore les futures reponses de l'agent en indexant la correction dans la base vectorielle.
                        </p>
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- Main: Conversation --}}
        <div class="lg:col-span-3">
            <x-filament::section class="h-full">
                <x-slot name="heading">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-chat-bubble-left-right class="w-5 h-5" />
                            Conversation
                        </div>
                        <span class="text-sm text-gray-500">{{ $messages->count() }} messages</span>
                    </div>
                </x-slot>

                {{-- Zone de messages avec style test-agent --}}
                <div class="h-[600px] overflow-y-auto space-y-4 p-4 bg-gray-50 dark:bg-gray-900 rounded-lg" id="chat-messages">
                    @forelse($messages as $index => $message)
                        @php
                            $isUser = $message->role === 'user';
                            $isAssistant = $message->role === 'assistant';
                            $isPending = $message->validation_status === 'pending';
                            $isValidated = $message->validation_status === 'validated';
                            $isLearned = $message->validation_status === 'learned';
                            $isRejected = $message->validation_status === 'rejected';
                        @endphp

                        {{-- Message utilisateur --}}
                        @if($isUser)
                            <div class="flex justify-end">
                                <div class="max-w-[80%]">
                                    <div class="bg-primary-500 text-white rounded-lg p-3 shadow-sm">
                                        <div class="prose prose-sm prose-invert max-w-none">
                                            {!! \Illuminate\Support\Str::markdown($message->content) !!}
                                        </div>
                                        <div class="flex items-center justify-between mt-2 text-xs text-primary-200">
                                            <span>{{ $message->created_at?->format('H:i') }}</span>
                                        </div>
                                    </div>

                                    {{-- Bouton pour voir le contexte envoye a l'IA (sur le message utilisateur qui precede) --}}
                                    @php
                                        // Trouver le message assistant suivant pour afficher son contexte
                                        $nextMessage = $messages->get($index + 1);
                                        $hasContext = $nextMessage && $nextMessage->role === 'assistant' && !empty($nextMessage->rag_context);
                                    @endphp

                                    @if($hasContext)
                                        @php
                                            $context = $nextMessage->rag_context;
                                            $learnedSources = $context['learned_sources'] ?? [];
                                            $documentSources = $context['document_sources'] ?? [];
                                            $conversationHistory = $context['conversation_history'] ?? [];
                                            $systemPrompt = $context['system_prompt_sent'] ?? '';
                                            $contextStats = $context['stats'] ?? [];
                                            $totalSources = count($learnedSources) + count($documentSources);
                                            $modalId = 'context-modal-' . ($message->uuid ?? $index);
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
                                                                                @if(isset($contextStats['context_window_size']))
                                                                                    <span class="text-xs font-normal text-violet-500 dark:text-violet-400">(fenetre: {{ $contextStats['context_window_size'] }} echanges max)</span>
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
                        @elseif($isAssistant)
                            <div class="flex justify-start"
                                 x-data="{ showCorrection: false, correctedContent: @js($message->content) }">
                                <div class="max-w-[80%]">
                                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-3 shadow-sm">
                                        {{-- Header avec badge de validation --}}
                                        <div class="flex items-center gap-2 mb-2 pb-2 border-b border-gray-100 dark:border-gray-700">
                                            <x-heroicon-o-cpu-chip class="w-4 h-4 text-gray-400" />
                                            <span class="text-xs text-gray-500">{{ $session->agent?->name }}</span>

                                            @if($isPending)
                                                <x-filament::badge color="warning" size="sm">En attente</x-filament::badge>
                                            @elseif($isValidated)
                                                <x-filament::badge color="success" size="sm">Validee</x-filament::badge>
                                            @elseif($isLearned)
                                                <x-filament::badge color="primary" size="sm">Apprise</x-filament::badge>
                                            @elseif($isRejected)
                                                <x-filament::badge color="danger" size="sm">Rejetee</x-filament::badge>
                                            @endif
                                        </div>

                                        {{-- Contenu --}}
                                        <div class="prose prose-sm dark:prose-invert max-w-none">
                                            @if($isLearned && $message->corrected_content)
                                                <div class="mb-2 p-2 bg-primary-50 dark:bg-primary-950 rounded text-xs">
                                                    <strong>Contenu corrige :</strong>
                                                </div>
                                                {!! \Illuminate\Support\Str::markdown($message->corrected_content) !!}
                                                <details class="mt-2">
                                                    <summary class="text-xs text-gray-500 cursor-pointer">Voir l'original</summary>
                                                    <div class="mt-2 p-2 bg-gray-100 dark:bg-gray-900 rounded text-sm opacity-75">
                                                        {!! \Illuminate\Support\Str::markdown($message->content) !!}
                                                    </div>
                                                </details>
                                            @else
                                                {!! \Illuminate\Support\Str::markdown($message->content ?? '') !!}
                                            @endif
                                        </div>

                                        {{-- Metadonnees --}}
                                        <div class="flex items-center justify-between mt-2 text-xs text-gray-400">
                                            <span>{{ $message->created_at?->format('H:i') }}</span>
                                            <div class="flex items-center gap-2">
                                                @if($message->model_used)
                                                    <span class="text-gray-400">{{ $message->model_used }}</span>
                                                @endif
                                                @if($message->tokens_completion)
                                                    <span>{{ $message->tokens_prompt + $message->tokens_completion }} tokens</span>
                                                @endif
                                                @if($message->generation_time_ms)
                                                    <span>{{ number_format($message->generation_time_ms / 1000, 1) }}s</span>
                                                @endif
                                            </div>
                                        </div>

                                        {{-- Boutons d'action pour reponses en attente --}}
                                        @if($isPending)
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
                                                        placeholder="Entrez la reponse corrigee..."
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
                                        @if(($isValidated || $isLearned || $isRejected) && $message->validated_at)
                                            <div class="mt-2 text-xs text-gray-400">
                                                {{ $isValidated ? 'Validee' : ($isLearned ? 'Corrigee' : 'Rejetee') }}
                                                par {{ $message->validator?->name ?? 'Systeme' }}
                                                le {{ $message->validated_at->format('d/m/Y a H:i') }}
                                            </div>
                                        @endif
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
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>

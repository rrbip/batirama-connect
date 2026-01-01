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
                                            <div x-data="{
                                                openContext: false,
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
[{{ $historyMsg['role'] ?? 'unknown' }}] {{ addslashes($historyMsg['content'] ?? '') }}
@endforeach

### Filtrage par categorie
@if(!($stats['use_category_filtering'] ?? false))
- Statut: Desactive pour cet agent
@elseif($catDetect)
- Methode de detection: {{ $catDetect['method'] ?? 'N/A' }}
- Confiance: {{ round(($catDetect['confidence'] ?? 0) * 100) }}%
- Categories detectees: {{ !empty($catDetect['categories']) ? implode(', ', array_map(fn($c) => $c['name'] ?? $c, $catDetect['categories'])) : 'Aucune' }}
- Resultats filtres: {{ $catDetect['filtered_results_count'] ?? 0 }}
- Resultats totaux: {{ $catDetect['total_results_count'] ?? 0 }}
- Fallback utilise: {{ ($catDetect['used_fallback'] ?? false) ? 'Oui' : 'Non' }}
@else
- Statut: Active mais aucune categorie detectee
@endif

### Documents RAG ({{ count($documentSources) }} sources)
@foreach($documentSources as $doc)
--- Document #{{ $doc['index'] ?? $loop->iteration }} ({{ $doc['score'] ?? 0 }}% pertinent) ---
Type: {{ $doc['type'] ?? 'unknown' }}
Categorie: {{ $doc['category'] ?? 'Non categorise' }}
Source: {{ $doc['source_doc'] ?? 'N/A' }}
@if(!empty($doc['question']))
Question matchee: {{ addslashes($doc['question'] ?? '') }}
@endif
Contenu: {{ addslashes($doc['content'] ?? '[VIDE]') }}
@endforeach

### Sources d'apprentissage ({{ count($learnedSources) }} cas)
@foreach($learnedSources as $learned)
--- Cas #{{ $learned['index'] ?? $loop->iteration }} ({{ $learned['score'] ?? 0 }}% similaire) ---
Q: {{ addslashes($learned['question'] ?? '') }}
R: {{ addslashes($learned['answer'] ?? '') }}
@endforeach

## Evaluation Handoff Humain
- Handoff active: {{ $handoffEnabled ? 'Oui' : 'Non' }}
@if($handoffEnabled)
- Seuil d'escalade configure: {{ number_format($escalationThreshold * 100, 0) }}%
- Score RAG maximum obtenu: {{ $maxRagScore > 0 ? number_format($maxRagScore * 100, 1) . '%' : 'Aucun document trouve' }}
- Decision d'escalade: {{ $wouldEscalate ? 'OUI - Score RAG insuffisant (< seuil)' : ($maxRagScore == 0 ? 'OUI - Aucune source trouvee' : 'NON - Score suffisant') }}
- Agents de support configures: {{ $agentForHandoff?->supportUsers()->count() ?? 0 }}
@if($agentForHandoff?->support_email)
- Email de support: {{ $agentForHandoff->support_email }}
@endif
@endif

## Informations techniques
- Agent: {{ $stats['agent_slug'] ?? 'N/A' }}
- Modele: {{ $stats['agent_model'] ?? 'N/A' }}
- Temperature: {{ $stats['temperature'] ?? 'N/A' }}
- Fenetre contexte: {{ $stats['context_window_size'] ?? 0 }} messages
- Filtrage categorie: {{ ($stats['use_category_filtering'] ?? false) ? 'Active' : 'Desactive' }}
- Type de reponse: {{ ($stats['response_type'] ?? '') === 'direct_qr_match' ? 'DIRECT Q/R (sans appel LLM)' : 'Generation LLM' }}`;
                                                }
                                            }">
                                                <button
                                                    type="button"
                                                    @click="openContext = true"
                                                    class="mt-2 inline-flex items-center gap-1 px-2 py-1 text-xs text-gray-500 hover:text-gray-700 dark:hover:text-gray-300"
                                                >
                                                    <x-heroicon-o-document-magnifying-glass class="w-3 h-3" />
                                                    Voir le contexte ({{ $totalSources }})
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
                                                                                <div class="prose prose-sm dark:prose-invert max-w-none">
                                                                                    {{-- Question utilisateur --}}
                                                                                    <h4 class="text-base font-bold text-gray-800 dark:text-gray-200 mb-2">📝 Question utilisateur</h4>
                                                                                    <div class="text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700 p-3 rounded whitespace-pre-wrap">{{ $userQuestion ?: '(Non disponible)' }}</div>

                                                                                    {{-- Réponse IA complète --}}
                                                                                    <h4 class="text-base font-bold text-gray-800 dark:text-gray-200 mt-4 mb-2">🤖 Réponse de l'IA</h4>
                                                                                    <div class="text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700 p-3 rounded whitespace-pre-wrap">{{ $aiResponse ?: '(Non disponible)' }}</div>

                                                                                    {{-- Prompt système --}}
                                                                                    @if(!empty($systemPrompt))
                                                                                        <h4 class="text-base font-bold text-gray-800 dark:text-gray-200 mt-4 mb-2">⚙️ Prompt système</h4>
                                                                                        <div class="text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700 p-3 rounded text-xs whitespace-pre-wrap max-h-48 overflow-y-auto">{{ $systemPrompt }}</div>
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
                                                                                                    <div class="mt-1 text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $histMsg['content'] ?? '' }}</div>
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

<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Sélection de l'agent --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-cpu-chip class="w-5 h-5" />
                    Sélection de l'agent
                </div>
            </x-slot>

            <div class="flex items-end gap-4">
                <div class="flex-1 max-w-md">
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 block">
                        Agent IA
                    </label>
                    <select
                        wire:model.live="selectedAgentId"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white shadow-sm focus:border-primary-500 focus:ring-primary-500"
                    >
                        @foreach($this->getAgents() as $agent)
                            <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                        @endforeach
                    </select>
                </div>

                @if($this->isAdmin())
                    <x-filament::button
                        wire:click="toggleAddForm"
                        color="{{ $showAddForm ? 'gray' : 'success' }}"
                        icon="{{ $showAddForm ? 'heroicon-o-x-mark' : 'heroicon-o-plus' }}"
                    >
                        {{ $showAddForm ? 'Annuler' : 'Ajouter une FAQ' }}
                    </x-filament::button>
                @endif
            </div>
        </x-filament::section>

        {{-- Formulaire d'ajout (admin seulement) --}}
        @if($this->isAdmin() && $showAddForm)
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-plus-circle class="w-5 h-5 text-success-500" />
                        Nouvelle FAQ
                    </div>
                </x-slot>
                <x-slot name="description">
                    Cette question/réponse sera indexée et utilisée par l'IA pour répondre aux questions similaires.
                </x-slot>

                <form wire:submit="saveFaq" class="space-y-4">
                    {{ $this->addFaqForm }}

                    <div class="flex justify-end gap-2">
                        <x-filament::button
                            type="button"
                            wire:click="toggleAddForm"
                            color="gray"
                        >
                            Annuler
                        </x-filament::button>
                        <x-filament::button
                            type="submit"
                            color="success"
                            icon="heroicon-o-check"
                        >
                            Enregistrer et indexer
                        </x-filament::button>
                    </div>
                </form>
            </x-filament::section>
        @endif

        {{-- Liste des FAQs --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-list-bullet class="w-5 h-5" />
                        Questions/Réponses apprises
                        @if($this->getSelectedAgent())
                            <span class="text-sm text-gray-500 dark:text-gray-400">- {{ $this->getSelectedAgent()->name }}</span>
                        @endif
                    </div>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        @if(!empty($search))
                            {{ $this->getTotalFilteredCount() }} / {{ count($faqs) }} FAQ(s)
                        @else
                            {{ count($faqs) }} FAQ(s)
                        @endif
                    </span>
                </div>
            </x-slot>

            {{-- Barre de recherche --}}
            <div class="mb-4">
                <x-filament::input.wrapper>
                    <x-filament::input.prefix>
                        <x-heroicon-o-magnifying-glass class="w-5 h-5 text-gray-400" />
                    </x-filament::input.prefix>
                    <x-filament::input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Rechercher dans les questions et réponses..."
                    />
                    @if(!empty($search))
                        <x-filament::input.suffix>
                            <button
                                type="button"
                                wire:click="$set('search', '')"
                                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                            >
                                <x-heroicon-o-x-mark class="w-5 h-5" />
                            </button>
                        </x-filament::input.suffix>
                    @endif
                </x-filament::input.wrapper>
            </div>

            @if($this->getTotalFilteredCount() === 0)
                <div class="text-center py-12">
                    <x-heroicon-o-chat-bubble-left-right class="w-12 h-12 mx-auto text-gray-400 mb-4" />
                    @if(!empty($search))
                        <p class="text-gray-600 dark:text-gray-300">
                            Aucune FAQ ne correspond à votre recherche.
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            Essayez avec d'autres mots-clés.
                        </p>
                    @else
                        <p class="text-gray-600 dark:text-gray-300">
                            Aucune FAQ pour cet agent.
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            Les FAQs sont créées quand vous validez ou corrigez des réponses IA, ou en les ajoutant manuellement.
                        </p>
                    @endif
                </div>
            @else
                <div class="space-y-4">
                    @foreach($this->getPaginatedFaqs() as $faq)
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                            {{-- Question --}}
                            <div class="bg-gray-50 dark:bg-gray-800 px-4 py-3 flex items-start justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <x-heroicon-o-chat-bubble-left class="w-4 h-4 text-primary-500" />
                                        <span class="text-xs font-medium text-primary-600 dark:text-primary-400 uppercase">
                                            Question
                                        </span>
                                        @if($faq['is_manual'])
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">
                                                Manuel
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                                Validé
                                            </span>
                                        @endif
                                    </div>
                                    <p class="text-gray-900 dark:text-gray-50 font-medium">
                                        {{ $faq['question'] }}
                                    </p>
                                </div>

                                @if($this->isAdmin())
                                    <x-filament::icon-button
                                        wire:click="deleteFaq('{{ $faq['id'] }}')"
                                        wire:confirm="Êtes-vous sûr de vouloir supprimer cette FAQ ? L'IA n'utilisera plus cette réponse."
                                        icon="heroicon-o-trash"
                                        color="danger"
                                        size="sm"
                                        tooltip="Supprimer"
                                    />
                                @endif
                            </div>

                            {{-- Réponse --}}
                            <div class="px-4 py-3 bg-white dark:bg-gray-900">
                                <div class="flex items-center gap-2 mb-1">
                                    <x-heroicon-o-chat-bubble-left-right class="w-4 h-4 text-success-500" />
                                    <span class="text-xs font-medium text-success-600 dark:text-success-400 uppercase">
                                        Réponse
                                    </span>
                                </div>
                                <p class="text-gray-800 dark:text-gray-100 whitespace-pre-wrap">{{ $faq['answer'] }}</p>

                                @if($faq['validated_at'])
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                        Ajoutée le {{ \Carbon\Carbon::parse($faq['validated_at'])->format('d/m/Y H:i') }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Pagination --}}
                @if($this->getTotalPages() > 1)
                    <div class="mt-6 flex items-center justify-between border-t border-gray-200 dark:border-gray-700 pt-4">
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            Affichage de {{ ($currentPage - 1) * $perPage + 1 }} à {{ min($currentPage * $perPage, $this->getTotalFilteredCount()) }} sur {{ $this->getTotalFilteredCount() }} FAQ(s)
                        </div>
                        <div class="flex items-center gap-2">
                            <x-filament::button
                                wire:click="previousPage"
                                :disabled="$currentPage <= 1"
                                size="sm"
                                color="gray"
                                icon="heroicon-o-chevron-left"
                            >
                                Précédent
                            </x-filament::button>

                            <div class="flex items-center gap-1">
                                @for($page = max(1, $currentPage - 2); $page <= min($this->getTotalPages(), $currentPage + 2); $page++)
                                    <button
                                        wire:click="goToPage({{ $page }})"
                                        class="px-3 py-1 text-sm rounded-lg {{ $page === $currentPage ? 'bg-primary-500 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600' }}"
                                    >
                                        {{ $page }}
                                    </button>
                                @endfor
                            </div>

                            <x-filament::button
                                wire:click="nextPage"
                                :disabled="$currentPage >= $this->getTotalPages()"
                                size="sm"
                                color="gray"
                                icon="heroicon-o-chevron-right"
                                icon-position="after"
                            >
                                Suivant
                            </x-filament::button>
                        </div>
                    </div>
                @endif
            @endif
        </x-filament::section>

        {{-- Info --}}
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-information-circle class="w-5 h-5" />
                    Comment ça fonctionne ?
                </div>
            </x-slot>

            <div class="prose dark:prose-invert prose-sm max-w-none">
                <p>Les FAQs sont stockées dans la base vectorielle Qdrant et utilisées par l'IA pour améliorer ses réponses.</p>

                <h4>Sources des FAQs :</h4>
                <ul>
                    <li><strong>Validation</strong> : Quand vous validez une réponse IA dans les sessions, elle est automatiquement ajoutée.</li>
                    <li><strong>Correction</strong> : Quand vous corrigez une réponse IA, la version corrigée est ajoutée.</li>
                    <li><strong>Manuel</strong> : Vous pouvez ajouter des Q&A directement depuis cette page (admin).</li>
                </ul>

                <h4>Utilisation par l'IA :</h4>
                <p>
                    Quand un utilisateur pose une question, l'IA cherche d'abord des FAQs similaires.
                    Si une FAQ a un score de similarité élevé (≥85%), sa réponse est utilisée comme référence pour générer la réponse finale.
                </p>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

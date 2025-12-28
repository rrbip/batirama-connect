<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Infolist avec les statistiques --}}
        {{ $this->infolist }}

        {{-- Table des URLs --}}
        <x-filament::section>
            <x-slot name="heading">
                URLs crawlées ({{ $this->record->pages_discovered }})
            </x-slot>

            {{ $this->table }}
        </x-filament::section>
    </div>

    {{-- Modal d'édition d'un agent config --}}
    <x-filament::modal id="edit-agent-config" width="xl">
        <x-slot name="heading">
            Modifier la configuration de l'agent
        </x-slot>

        <div class="space-y-4">
            <div>
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                        Mode de filtrage
                    </span>
                </label>
                <div class="mt-2 space-y-2">
                    <label class="flex items-center gap-2">
                        <input type="radio" value="exclude"
                            wire:model="editFormData.url_filter_mode"
                            class="fi-radio-input rounded-full border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
                        <span class="text-sm text-gray-700 dark:text-gray-300">Exclure les patterns (indexe tout sauf les URLs matchant)</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" value="include"
                            wire:model="editFormData.url_filter_mode"
                            class="fi-radio-input rounded-full border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
                        <span class="text-sm text-gray-700 dark:text-gray-300">Inclure uniquement (indexe seulement les URLs matchant)</span>
                    </label>
                </div>
            </div>

            <div>
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                        Patterns d'URLs
                    </span>
                </label>
                <textarea
                    wire:model="editFormData.url_patterns"
                    rows="3"
                    placeholder="/blog/*&#10;/products/*.html"
                    class="fi-input block w-full rounded-lg border-gray-300 shadow-sm focus:ring-primary-500 focus:border-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:text-sm mt-1"
                ></textarea>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Un pattern par ligne. Vide = tout indexer</p>
            </div>

            <div>
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                        Types de contenu à indexer
                    </span>
                </label>
                <div class="mt-2 space-y-2">
                    @foreach(['html' => 'HTML', 'pdf' => 'PDF', 'image' => 'Images', 'document' => 'Documents (Word, texte...)'] as $value => $label)
                        <label class="flex items-center gap-2">
                            <input type="checkbox" value="{{ $value }}"
                                wire:model="editFormData.content_types"
                                class="fi-checkbox-input rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div>
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                        Stratégie de chunking
                    </span>
                </label>
                <select
                    wire:model="editFormData.chunk_strategy"
                    class="fi-select-input block w-full rounded-lg border-gray-300 shadow-sm focus:ring-primary-500 focus:border-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white sm:text-sm mt-1"
                >
                    <option value="">Par défaut de l'agent</option>
                    <option value="simple">Simple (découpage par taille)</option>
                    <option value="html_semantic">HTML Sémantique (balises)</option>
                    <option value="llm_assisted">LLM (découpage intelligent)</option>
                </select>
            </div>

            {{-- Langues à indexer --}}
            <div x-data="{ expanded: false }">
                <button type="button" @click="expanded = !expanded" class="flex items-center gap-2 w-full text-left">
                    <span class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                        <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                            Langues à indexer
                        </span>
                    </span>
                    <x-heroicon-m-chevron-down class="w-4 h-4 text-gray-500 transition-transform" x-bind:class="{ 'rotate-180': expanded }" />
                </button>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Laissez vide pour indexer toutes les langues</p>

                <div x-show="expanded" x-collapse class="mt-3 space-y-3">
                    @php
                        $continents = \App\Services\Marketplace\LanguageDetector::getLocalesByContinent();
                    @endphp

                    @foreach($continents as $continentKey => $continent)
                        <div x-data="{ open: false }" class="border border-gray-200 dark:border-gray-700 rounded-lg">
                            <button type="button" @click="open = !open" class="flex items-center justify-between w-full px-3 py-2 text-left bg-gray-50 dark:bg-gray-800 rounded-t-lg">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $continent['label'] }}</span>
                                <x-heroicon-m-chevron-down class="w-4 h-4 text-gray-500 transition-transform" x-bind:class="{ 'rotate-180': open }" />
                            </button>
                            <div x-show="open" x-collapse class="px-3 py-2 grid grid-cols-3 gap-2">
                                @foreach($continent['locales'] as $code => $name)
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" value="{{ $code }}"
                                            wire:model="editFormData.allowed_locales"
                                            class="fi-checkbox-input rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
                                        <span class="text-xs text-gray-700 dark:text-gray-300">{{ $name }} ({{ strtoupper($code) }})</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <x-slot name="footerActions">
            <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'edit-agent-config' })">
                Annuler
            </x-filament::button>
            <x-filament::button wire:click="saveAgentConfig">
                Enregistrer
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>

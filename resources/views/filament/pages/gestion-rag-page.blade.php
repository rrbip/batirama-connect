<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Tabs Navigation --}}
        <x-filament::tabs>
            <x-filament::tabs.item
                :active="$activeTab === 'documents'"
                wire:click="setActiveTab('documents')"
                icon="heroicon-o-document-text"
            >
                Documents
            </x-filament::tabs.item>

            <x-filament::tabs.item
                :active="$activeTab === 'categories'"
                wire:click="setActiveTab('categories')"
                icon="heroicon-o-tag"
            >
                Catégories
            </x-filament::tabs.item>

            <x-filament::tabs.item
                :active="$activeTab === 'crawlers'"
                wire:click="setActiveTab('crawlers')"
                icon="heroicon-o-globe-alt"
            >
                Crawler Web
            </x-filament::tabs.item>

            <x-filament::tabs.item
                :active="$activeTab === 'chunking'"
                wire:click="setActiveTab('chunking')"
                icon="heroicon-o-cog-6-tooth"
            >
                Chunking LLM
            </x-filament::tabs.item>
        </x-filament::tabs>

        {{-- Tab Content --}}
        @if($activeTab === 'documents')
            {{-- Documents Tab --}}
            <div class="flex flex-wrap items-center gap-3 mb-4">
                <x-filament::button
                    :href="\App\Filament\Resources\DocumentResource::getUrl('create')"
                    tag="a"
                    icon="heroicon-o-plus"
                >
                    Créer un document
                </x-filament::button>

                <x-filament::button
                    :href="\App\Filament\Resources\DocumentResource::getUrl('bulk-import')"
                    tag="a"
                    color="success"
                    icon="heroicon-o-arrow-up-tray"
                >
                    Import en masse
                </x-filament::button>

                <x-filament::button
                    :href="\App\Filament\Resources\WebCrawlResource::getUrl('create')"
                    tag="a"
                    color="info"
                    icon="heroicon-o-globe-alt"
                >
                    Crawler un site
                </x-filament::button>

                <x-filament::modal id="rebuild-index-modal" width="md">
                    <x-slot name="trigger">
                        <x-filament::button
                            color="warning"
                            icon="heroicon-o-wrench-screwdriver"
                        >
                            Réparer index Qdrant
                        </x-filament::button>
                    </x-slot>

                    <x-slot name="heading">
                        Reconstruire l'index Qdrant
                    </x-slot>

                    <x-slot name="description">
                        Cette action va supprimer tous les points de la collection Qdrant de l'agent et les recréer à partir des chunks en base de données.
                    </x-slot>

                    <form wire:submit="rebuildQdrantIndex(Object.fromEntries(new FormData($event.target)))">
                        <div class="space-y-4">
                            <div>
                                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3" for="rebuild_agent_id">
                                    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">Agent</span>
                                </label>
                                <select
                                    id="rebuild_agent_id"
                                    name="agent_id"
                                    required
                                    class="fi-select-input block w-full border-none py-1.5 pe-8 text-base text-gray-950 transition duration-75 focus:ring-0 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] dark:text-white dark:disabled:text-gray-400 sm:text-sm sm:leading-6 bg-transparent"
                                >
                                    <option value="">Sélectionner un agent...</option>
                                    @foreach(\App\Models\Agent::whereNotNull('qdrant_collection')->get() as $agent)
                                        <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="flex justify-end gap-3">
                                <x-filament::button
                                    type="button"
                                    color="gray"
                                    x-on:click="$dispatch('close-modal', { id: 'rebuild-index-modal' })"
                                >
                                    Annuler
                                </x-filament::button>
                                <x-filament::button
                                    type="submit"
                                    color="warning"
                                >
                                    Reconstruire
                                </x-filament::button>
                            </div>
                        </div>
                    </form>
                </x-filament::modal>
            </div>

            {{ $this->table }}

        @elseif($activeTab === 'categories')
            {{-- Categories Tab --}}
            <div class="flex items-center gap-3 mb-4">
                <x-filament::button
                    :href="\App\Filament\Resources\DocumentCategoryResource::getUrl('create')"
                    tag="a"
                    icon="heroicon-o-plus"
                >
                    Créer une catégorie
                </x-filament::button>
            </div>

            {{ $this->table }}

        @elseif($activeTab === 'crawlers')
            {{-- Crawlers Tab --}}
            <div class="flex items-center gap-3 mb-4">
                <x-filament::button
                    :href="\App\Filament\Resources\WebCrawlResource::getUrl('create')"
                    tag="a"
                    icon="heroicon-o-plus"
                >
                    Nouveau crawl
                </x-filament::button>
            </div>

            {{ $this->table }}

        @elseif($activeTab === 'chunking')
            {{-- LLM Chunking Settings Tab --}}
            <div class="space-y-6">
                {{-- Queue Stats --}}
                <x-filament::section>
                    <x-slot name="heading">
                        File d'attente LLM Chunking
                    </x-slot>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <div class="text-sm text-gray-500 dark:text-gray-400">Jobs en attente</div>
                            <div class="text-2xl font-bold {{ ($queueStats['pending'] ?? 0) > 0 ? 'text-warning-600' : 'text-gray-900 dark:text-white' }}">
                                {{ $queueStats['pending'] ?? 0 }}
                            </div>
                        </div>
                        <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <div class="text-sm text-gray-500 dark:text-gray-400">Jobs en erreur</div>
                            <div class="text-2xl font-bold {{ ($queueStats['failed'] ?? 0) > 0 ? 'text-danger-600' : 'text-gray-900 dark:text-white' }}">
                                {{ $queueStats['failed'] ?? 0 }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                        <strong>Commande worker :</strong>
                        <code class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded">php artisan queue:work --queue=default,llm-chunking</code>
                        <div class="mt-1 text-xs">💡 Les messages IA (default) sont prioritaires sur le chunking LLM</div>
                    </div>
                </x-filament::section>

                {{-- Action Buttons --}}
                <div class="flex items-center gap-3">
                    <x-filament::button
                        wire:click="testLlmConnection"
                        color="info"
                        icon="heroicon-o-signal"
                    >
                        Tester la connexion
                    </x-filament::button>

                    <x-filament::button
                        wire:click="resetLlmPrompt"
                        wire:confirm="Le prompt sera remplacé par la version par défaut. Continuer ?"
                        color="warning"
                        icon="heroicon-o-arrow-path"
                    >
                        Réinitialiser le prompt
                    </x-filament::button>

                    <x-filament::button
                        wire:click="saveLlmSettings"
                        color="success"
                        icon="heroicon-o-check"
                    >
                        Sauvegarder
                    </x-filament::button>
                </div>

                {{-- Settings Form --}}
                <form wire:submit="saveLlmSettings">
                    {{ $this->llmForm }}
                </form>
            </div>
        @endif
    </div>
</x-filament-panels::page>

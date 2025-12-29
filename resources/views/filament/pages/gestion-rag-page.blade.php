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
                :active="$activeTab === 'configuration'"
                wire:click="setActiveTab('configuration')"
                icon="heroicon-o-cog-6-tooth"
            >
                Configuration
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
                                    class="fi-select-input block w-full rounded-lg border border-gray-300 dark:border-gray-600 py-2 px-3 text-base text-gray-900 dark:text-white bg-white dark:bg-gray-700 transition duration-75 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                                >
                                    <option value="" class="text-gray-500">Sélectionner un agent...</option>
                                    @foreach(\App\Models\Agent::whereNotNull('qdrant_collection')->get() as $agent)
                                        <option value="{{ $agent->id }}" class="text-gray-900 dark:text-white bg-white dark:bg-gray-700">{{ $agent->name }}</option>
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

        @elseif($activeTab === 'configuration')
            {{-- Configuration Tab with Collapsible Sections --}}
            <div class="space-y-4">
                {{-- Queue Stats --}}
                <x-filament::section>
                    <x-slot name="heading">
                        File d'attente Pipeline
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
                        <code class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded">php artisan queue:work --queue=default,pipeline,llm-chunking</code>
                    </div>
                </x-filament::section>

                {{-- Test Connection Button --}}
                <div class="flex items-center gap-3">
                    <x-filament::button
                        wire:click="testOllamaConnection"
                        color="info"
                        icon="heroicon-o-signal"
                    >
                        Tester la connexion Ollama
                    </x-filament::button>
                </div>

                {{-- Configuration Vision (Collapsible) --}}
                <x-filament::section
                    :collapsed="true"
                    collapsible
                >
                    <x-slot name="heading">
                        Configuration Vision
                    </x-slot>
                    <x-slot name="description">
                        Paramètres pour l'extraction de texte depuis les images via Vision LLM
                    </x-slot>

                    <div class="space-y-4">
                        {{ $this->visionForm }}

                        <div class="flex items-center gap-3 pt-4 border-t dark:border-gray-700">
                            <x-filament::button
                                wire:click="saveVisionSettings"
                                color="success"
                                icon="heroicon-o-check"
                            >
                                Sauvegarder
                            </x-filament::button>

                            <x-filament::button
                                wire:click="resetVisionPrompt"
                                wire:confirm="Le prompt sera remplacé par la version par défaut. Continuer ?"
                                color="warning"
                                icon="heroicon-o-arrow-path"
                            >
                                Réinitialiser le prompt
                            </x-filament::button>
                        </div>
                    </div>
                </x-filament::section>

                {{-- Configuration Chunking LLM (Collapsible) --}}
                <x-filament::section
                    :collapsed="true"
                    collapsible
                >
                    <x-slot name="heading">
                        Configuration Chunking LLM
                    </x-slot>
                    <x-slot name="description">
                        Paramètres pour le découpage sémantique du texte via LLM
                    </x-slot>

                    <div class="space-y-4">
                        {{ $this->chunkingForm }}

                        <div class="flex items-center gap-3 pt-4 border-t dark:border-gray-700">
                            <x-filament::button
                                wire:click="saveChunkingSettings"
                                color="success"
                                icon="heroicon-o-check"
                            >
                                Sauvegarder
                            </x-filament::button>

                            <x-filament::button
                                wire:click="resetChunkingPrompt"
                                wire:confirm="Le prompt sera remplacé par la version par défaut. Continuer ?"
                                color="warning"
                                icon="heroicon-o-arrow-path"
                            >
                                Réinitialiser le prompt
                            </x-filament::button>
                        </div>
                    </div>
                </x-filament::section>

                {{-- Configuration Q/R Atomique (Collapsible) --}}
                <x-filament::section
                    :collapsed="true"
                    collapsible
                >
                    <x-slot name="heading">
                        Configuration Q/R Atomique
                    </x-slot>
                    <x-slot name="description">
                        Paramètres pour la génération de paires Question/Réponse et l'indexation Qdrant
                    </x-slot>

                    <div class="space-y-4">
                        {{ $this->qrForm }}

                        <div class="flex items-center gap-3 pt-4 border-t dark:border-gray-700">
                            <x-filament::button
                                wire:click="saveQrSettings"
                                color="success"
                                icon="heroicon-o-check"
                            >
                                Sauvegarder
                            </x-filament::button>

                            <x-filament::button
                                wire:click="resetQrPrompt"
                                wire:confirm="Le prompt sera remplacé par la version par défaut. Continuer ?"
                                color="warning"
                                icon="heroicon-o-arrow-path"
                            >
                                Réinitialiser le prompt
                            </x-filament::button>
                        </div>
                    </div>
                </x-filament::section>

                {{-- Outils par défaut par type de fichier (Collapsible) --}}
                <x-filament::section
                    :collapsed="true"
                    collapsible
                >
                    <x-slot name="heading">
                        Outils par défaut par type de fichier
                    </x-slot>
                    <x-slot name="description">
                        Configuration des pipelines de traitement par type de document
                    </x-slot>

                    <div class="space-y-4">
                        {{ $this->toolsForm }}

                        <div class="flex items-center gap-3 pt-4 border-t dark:border-gray-700">
                            <x-filament::button
                                wire:click="saveToolsSettings"
                                color="success"
                                icon="heroicon-o-check"
                            >
                                Sauvegarder
                            </x-filament::button>
                        </div>
                    </div>
                </x-filament::section>
            </div>
        @endif
    </div>
</x-filament-panels::page>

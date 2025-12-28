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
                :active="$activeTab === 'vision'"
                wire:click="setActiveTab('vision')"
                icon="heroicon-o-eye"
            >
                Extraction Vision
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

        @elseif($activeTab === 'vision')
            {{-- Vision Extraction Settings Tab --}}
            <div class="space-y-6">
                {{-- Diagnostic du système --}}
                <x-filament::section collapsible>
                    <x-slot name="heading">
                        Diagnostic du système
                    </x-slot>

                    @if(!empty($visionDiagnostics))
                        <div class="space-y-4">
                            {{-- Statut Ollama --}}
                            <div class="flex items-center gap-3 p-3 rounded-lg {{ ($visionDiagnostics['ollama']['connected'] ?? false) ? 'bg-success-50 dark:bg-success-900/20' : 'bg-danger-50 dark:bg-danger-900/20' }}">
                                @if($visionDiagnostics['ollama']['connected'] ?? false)
                                    <x-heroicon-o-check-circle class="w-6 h-6 text-success-600" />
                                    <div>
                                        <div class="font-medium text-success-700 dark:text-success-400">Ollama connecté</div>
                                        @if($visionDiagnostics['ollama']['configured_model_installed'] ?? false)
                                            <div class="text-sm text-success-600">Modèle {{ $visionDiagnostics['model'] ?? 'inconnu' }} installé</div>
                                        @else
                                            <div class="text-sm text-warning-600">⚠️ Modèle {{ $visionDiagnostics['model'] ?? 'inconnu' }} non installé</div>
                                            <code class="text-xs bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded">ollama pull {{ $visionDiagnostics['model'] ?? '' }}</code>
                                        @endif
                                    </div>
                                @else
                                    <x-heroicon-o-x-circle class="w-6 h-6 text-danger-600" />
                                    <div>
                                        <div class="font-medium text-danger-700 dark:text-danger-400">Ollama non connecté</div>
                                        <div class="text-sm text-danger-600">{{ $visionDiagnostics['ollama']['error'] ?? 'Erreur inconnue' }}</div>
                                    </div>
                                @endif
                            </div>

                            {{-- Convertisseurs PDF --}}
                            <div class="grid grid-cols-2 gap-3">
                                <div class="flex items-center gap-2 p-3 rounded-lg {{ ($visionDiagnostics['pdf_converter']['pdftoppm'] ?? false) ? 'bg-success-50 dark:bg-success-900/20' : 'bg-gray-50 dark:bg-gray-800' }}">
                                    @if($visionDiagnostics['pdf_converter']['pdftoppm'] ?? false)
                                        <x-heroicon-o-check-circle class="w-5 h-5 text-success-600" />
                                        <span class="text-success-700 dark:text-success-400">pdftoppm installé</span>
                                    @else
                                        <x-heroicon-o-x-circle class="w-5 h-5 text-gray-400" />
                                        <span class="text-gray-500">pdftoppm non trouvé</span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 p-3 rounded-lg {{ ($visionDiagnostics['pdf_converter']['imagemagick'] ?? false) ? 'bg-success-50 dark:bg-success-900/20' : 'bg-gray-50 dark:bg-gray-800' }}">
                                    @if($visionDiagnostics['pdf_converter']['imagemagick'] ?? false)
                                        <x-heroicon-o-check-circle class="w-5 h-5 text-success-600" />
                                        <span class="text-success-700 dark:text-success-400">ImageMagick installé</span>
                                    @else
                                        <x-heroicon-o-x-circle class="w-5 h-5 text-gray-400" />
                                        <span class="text-gray-500">ImageMagick non trouvé</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="text-gray-500">Cliquez sur "Tester la connexion" pour rafraîchir</div>
                    @endif
                </x-filament::section>

                {{-- Action Buttons --}}
                <div class="flex items-center gap-3">
                    <x-filament::button
                        wire:click="testVisionConnection"
                        color="info"
                        icon="heroicon-o-signal"
                    >
                        Tester la connexion
                    </x-filament::button>

                    <x-filament::button
                        wire:click="resetVisionPrompt"
                        wire:confirm="Le prompt sera remplacé par la version par défaut. Continuer ?"
                        color="warning"
                        icon="heroicon-o-arrow-path"
                    >
                        Réinitialiser le prompt
                    </x-filament::button>

                    <x-filament::button
                        wire:click="saveVisionSettings"
                        color="success"
                        icon="heroicon-o-check"
                    >
                        Sauvegarder
                    </x-filament::button>
                </div>

                {{-- Settings Form --}}
                <form wire:submit="saveVisionSettings">
                    {{ $this->visionForm }}
                </form>

                {{-- Zone de calibration --}}
                <x-filament::section collapsible collapsed>
                    <x-slot name="heading">
                        Zone de calibration
                    </x-slot>
                    <x-slot name="description">
                        Testez différents prompts sur une image pour calibrer l'extraction.
                    </x-slot>

                    <div class="space-y-6">
                        {{-- Sélection de l'image --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Upload d'image
                                </label>
                                <input
                                    type="file"
                                    wire:model="calibrationImageUpload"
                                    accept="image/*"
                                    class="block w-full text-sm text-gray-500 dark:text-gray-400
                                        file:mr-4 file:py-2 file:px-4
                                        file:rounded-full file:border-0
                                        file:text-sm file:font-semibold
                                        file:bg-primary-50 file:text-primary-700
                                        hover:file:bg-primary-100
                                        dark:file:bg-primary-900/50 dark:file:text-primary-300"
                                />
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Ou URL de l'image
                                </label>
                                <input
                                    type="url"
                                    wire:model="calibrationImageUrl"
                                    placeholder="https://example.com/image.png"
                                    class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-primary-500 focus:ring-primary-500"
                                />
                            </div>
                        </div>

                        {{-- Aperçu de l'image --}}
                        @if($calibrationImageUpload)
                            <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">Aperçu :</p>
                                <img src="{{ $calibrationImageUpload->temporaryUrl() }}" alt="Image de calibration" class="max-h-48 rounded-lg shadow" />
                            </div>
                        @elseif($calibrationImageUrl)
                            <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">Aperçu :</p>
                                <img src="{{ $calibrationImageUrl }}" alt="Image de calibration" class="max-h-48 rounded-lg shadow" />
                            </div>
                        @endif

                        {{-- JSON de calibration --}}
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Tests de calibration (JSON)
                                </label>
                                <button
                                    type="button"
                                    wire:click="resetCalibrationJson"
                                    class="inline-flex items-center gap-1 px-3 py-1 text-xs font-medium text-gray-600 bg-gray-100 dark:bg-gray-700 dark:text-gray-400 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition"
                                >
                                    Réinitialiser
                                </button>
                            </div>
                            <textarea
                                wire:model.lazy="calibrationJson"
                                rows="10"
                                class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 font-mono text-xs shadow-sm focus:border-primary-500 focus:ring-primary-500"
                            ></textarea>
                        </div>

                        {{-- Bouton de lancement --}}
                        <div class="flex justify-center gap-4">
                            @if($isCalibrating)
                                <button
                                    type="button"
                                    wire:click="cancelCalibration"
                                    class="inline-flex items-center gap-2 px-6 py-3 text-lg font-semibold text-white bg-danger-600 rounded-xl hover:bg-danger-700 transition"
                                >
                                    <x-heroicon-o-stop class="w-5 h-5" />
                                    Annuler
                                </button>
                            @else
                                <button
                                    type="button"
                                    wire:click="runCalibration"
                                    class="inline-flex items-center gap-2 px-6 py-3 text-lg font-semibold text-white bg-primary-600 rounded-xl hover:bg-primary-700 transition"
                                >
                                    <x-heroicon-o-play class="w-5 h-5" />
                                    Lancer la calibration
                                </button>
                            @endif
                        </div>

                        @if($isCalibrating)
                            <div class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg" wire:poll.2s="checkCalibrationStatus">
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <x-heroicon-o-arrow-path class="w-5 h-5 text-blue-600 animate-spin" />
                                            <span class="text-blue-700 dark:text-blue-400">Calibration en cours...</span>
                                        </div>
                                        <span class="text-sm font-medium text-blue-600">
                                            {{ $calibrationProgress }}/{{ $calibrationTotal }}
                                        </span>
                                    </div>
                                    @if($calibrationTotal > 0)
                                        <div class="w-full bg-blue-200 dark:bg-blue-800 rounded-full h-2.5">
                                            <div class="bg-blue-600 h-2.5 rounded-full transition-all duration-300" style="width: {{ ($calibrationProgress / $calibrationTotal) * 100 }}%"></div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        {{-- Résultats de calibration --}}
                        @if(!empty($calibrationResults))
                            <div class="space-y-4">
                                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">Résultats</h4>
                                @php
                                    $successCount = count(array_filter($calibrationResults, fn($r) => $r['success'] ?? false));
                                    $totalCount = count($calibrationResults);
                                @endphp
                                <div class="flex items-center gap-4 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                                    <span class="text-lg font-bold {{ $successCount === $totalCount ? 'text-success-600' : 'text-warning-600' }}">
                                        {{ $successCount }}/{{ $totalCount }}
                                    </span>
                                    <span class="text-sm text-gray-600 dark:text-gray-400">tests réussis</span>
                                </div>

                                <div class="grid gap-3">
                                    @foreach($calibrationResults as $result)
                                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                                            <div class="px-4 py-2 {{ $result['success'] ? 'bg-success-50 dark:bg-success-900/20' : 'bg-danger-50 dark:bg-danger-900/20' }} flex items-center justify-between">
                                                <div class="flex items-center gap-3">
                                                    @if($result['success'])
                                                        <span class="text-success-600">✓</span>
                                                    @else
                                                        <span class="text-danger-600">✗</span>
                                                    @endif
                                                    <span class="font-medium">{{ $result['id'] }}</span>
                                                    <span class="ml-2 px-2 py-0.5 text-xs bg-gray-200 dark:bg-gray-600 rounded">{{ $result['category'] }}</span>
                                                </div>
                                                <span class="text-xs text-gray-500">{{ $result['processing_time'] }}s</span>
                                            </div>
                                            @if($result['success'] && $result['markdown'])
                                                <details class="border-t border-gray-200 dark:border-gray-700">
                                                    <summary class="px-4 py-2 text-xs font-medium text-gray-600 dark:text-gray-400 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800">
                                                        Voir le résultat
                                                    </summary>
                                                    <pre class="p-3 bg-gray-50 dark:bg-gray-900 text-xs font-mono whitespace-pre-wrap overflow-x-auto max-h-48 overflow-y-auto">{{ $result['markdown'] }}</pre>
                                                </details>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Rapport de calibration --}}
                        @if($calibrationReport)
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">Rapport</h4>
                                    <button
                                        type="button"
                                        onclick="navigator.clipboard.writeText(document.getElementById('calibration-report').innerText).then(() => alert('Rapport copié !'))"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium text-white bg-primary-600 rounded-lg hover:bg-primary-700 transition"
                                    >
                                        Copier le rapport
                                    </button>
                                </div>
                                <pre id="calibration-report" class="p-4 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg text-xs font-mono whitespace-pre-wrap overflow-x-auto max-h-64 overflow-y-auto">{{ $calibrationReport }}</pre>
                            </div>
                        @endif
                    </div>
                </x-filament::section>
            </div>

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

<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Diagnostic du système --}}
        <x-filament::section collapsible>
            <x-slot name="heading">
                Diagnostic du système
            </x-slot>
            <x-slot name="description">
                Vérifiez que tous les prérequis sont installés
            </x-slot>

            @if(!empty($diagnostics))
                <div class="space-y-4">
                    {{-- Statut Ollama --}}
                    <div class="flex items-center gap-3 p-3 rounded-lg {{ ($diagnostics['ollama']['connected'] ?? false) ? 'bg-success-50 dark:bg-success-900/20' : 'bg-danger-50 dark:bg-danger-900/20' }}">
                        @if($diagnostics['ollama']['connected'] ?? false)
                            <x-heroicon-o-check-circle class="w-6 h-6 text-success-600" />
                            <div>
                                <div class="font-medium text-success-700 dark:text-success-400">Ollama connecté</div>
                                @if($diagnostics['ollama']['configured_model_installed'] ?? false)
                                    <div class="text-sm text-success-600">Modèle {{ $diagnostics['model'] ?? 'inconnu' }} installé</div>
                                @else
                                    <div class="text-sm text-warning-600">⚠️ Modèle {{ $diagnostics['model'] ?? 'inconnu' }} non installé</div>
                                    <code class="text-xs bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded">ollama pull {{ $diagnostics['model'] ?? '' }}</code>
                                @endif
                            </div>
                        @else
                            <x-heroicon-o-x-circle class="w-6 h-6 text-danger-600" />
                            <div>
                                <div class="font-medium text-danger-700 dark:text-danger-400">Ollama non connecté</div>
                                <div class="text-sm text-danger-600">{{ $diagnostics['ollama']['error'] ?? 'Erreur inconnue' }}</div>
                            </div>
                        @endif
                    </div>

                    {{-- Convertisseurs PDF --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex items-center gap-2 p-3 rounded-lg {{ ($diagnostics['pdf_converter']['pdftoppm'] ?? false) ? 'bg-success-50 dark:bg-success-900/20' : 'bg-gray-50 dark:bg-gray-800' }}">
                            @if($diagnostics['pdf_converter']['pdftoppm'] ?? false)
                                <x-heroicon-o-check-circle class="w-5 h-5 text-success-600" />
                                <span class="text-success-700 dark:text-success-400">pdftoppm installé</span>
                            @else
                                <x-heroicon-o-x-circle class="w-5 h-5 text-gray-400" />
                                <span class="text-gray-500">pdftoppm non trouvé</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-2 p-3 rounded-lg {{ ($diagnostics['pdf_converter']['imagemagick'] ?? false) ? 'bg-success-50 dark:bg-success-900/20' : 'bg-gray-50 dark:bg-gray-800' }}">
                            @if($diagnostics['pdf_converter']['imagemagick'] ?? false)
                                <x-heroicon-o-check-circle class="w-5 h-5 text-success-600" />
                                <span class="text-success-700 dark:text-success-400">ImageMagick installé</span>
                            @else
                                <x-heroicon-o-x-circle class="w-5 h-5 text-gray-400" />
                                <span class="text-gray-500">ImageMagick non trouvé</span>
                            @endif
                        </div>
                    </div>

                    {{-- Info modèle --}}
                    @if($diagnostics['model_info'] ?? null)
                        <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <div class="font-medium mb-2">{{ $diagnostics['model_info']['name'] ?? 'Modèle' }}</div>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
                                <div>
                                    <span class="text-gray-500">Taille:</span>
                                    <span class="font-medium">{{ $diagnostics['model_info']['size'] ?? '?' }}</span>
                                </div>
                                <div>
                                    <span class="text-gray-500">VRAM:</span>
                                    <span class="font-medium">{{ $diagnostics['model_info']['vram'] ?? '?' }}</span>
                                </div>
                                <div>
                                    <span class="text-gray-500">CPU:</span>
                                    <span class="font-medium {{ ($diagnostics['cpu_compatible'] ?? false) ? 'text-success-600' : 'text-warning-600' }}">
                                        {{ ($diagnostics['cpu_compatible'] ?? false) ? '✅ Compatible' : '⚠️ Lent' }}
                                    </span>
                                </div>
                                <div>
                                    <span class="text-gray-500">Qualité:</span>
                                    <span class="font-medium">{{ $diagnostics['model_info']['quality'] ?? '?' }}</span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @else
                <div class="text-gray-500">Cliquez sur "Tester la connexion" pour rafraîchir</div>
            @endif
        </x-filament::section>

        {{-- Instructions d'installation --}}
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">
                Guide d'installation
            </x-slot>
            <x-slot name="description">
                Comment installer les dépendances nécessaires
            </x-slot>

            <div class="space-y-4 prose dark:prose-invert max-w-none">
                <h4>1. Installer les outils de conversion PDF</h4>
                <pre class="bg-gray-100 dark:bg-gray-800 p-3 rounded-lg text-sm overflow-x-auto"><code>apt install poppler-utils imagemagick</code></pre>

                <h4>2. Installer un modèle vision</h4>
                <div class="space-y-2">
                    <div class="p-3 bg-success-50 dark:bg-success-900/20 rounded-lg">
                        <strong class="text-success-700 dark:text-success-400">Pour développement (CPU) :</strong>
                        <pre class="mt-1 text-sm"><code>ollama pull moondream</code></pre>
                        <div class="text-sm text-gray-600 dark:text-gray-400">1.7 GB - Fonctionne sur CPU, 10-30s/page</div>
                    </div>

                    <div class="p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                        <strong class="text-blue-700 dark:text-blue-400">Pour production (GPU 6GB) :</strong>
                        <pre class="mt-1 text-sm"><code>ollama pull llava:7b</code></pre>
                        <div class="text-sm text-gray-600 dark:text-gray-400">4.7 GB - Bonne qualité, 5-10s/page</div>
                    </div>

                    <div class="p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                        <strong class="text-purple-700 dark:text-purple-400">Pour production (GPU 8GB+) :</strong>
                        <pre class="mt-1 text-sm"><code>ollama pull llama3.2-vision</code></pre>
                        <div class="text-sm text-gray-600 dark:text-gray-400">7.9 GB - Meilleure qualité tableaux, 10-20s/page</div>
                    </div>
                </div>

                <h4>3. Configurer le modèle</h4>
                <p>Sélectionnez le modèle installé dans le formulaire ci-dessous, puis testez la connexion.</p>
            </div>
        </x-filament::section>

        {{-- Formulaire de configuration --}}
        <form wire:submit="save">
            {{ $this->form }}
        </form>

        {{-- Zone de calibration --}}
        <x-filament::section>
            <x-slot name="heading">
                Zone de calibration
            </x-slot>
            <x-slot name="description">
                Testez différents prompts sur une image pour calibrer l'extraction
            </x-slot>

            <div class="space-y-6">
                {{-- Sélection de l'image --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {{-- Upload de fichier --}}
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
                        <p class="mt-1 text-xs text-gray-500">PNG, JPG, WEBP jusqu'à 10MB</p>
                    </div>

                    {{-- URL de l'image --}}
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
                        <p class="mt-1 text-xs text-gray-500">L'upload a la priorité sur l'URL</p>
                    </div>
                </div>

                {{-- Aperçu de l'image --}}
                @if($calibrationImageUpload)
                    <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">Aperçu de l'image uploadée :</p>
                        <img src="{{ $calibrationImageUpload->temporaryUrl() }}" alt="Image de calibration" class="max-h-64 rounded-lg shadow" />
                    </div>
                @elseif($calibrationImageUrl)
                    <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">Aperçu de l'image URL :</p>
                        <img src="{{ $calibrationImageUrl }}" alt="Image de calibration" class="max-h-64 rounded-lg shadow" />
                    </div>
                @endif

                {{-- Prompts de calibration --}}
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">Prompts à tester</h4>
                        <button
                            type="button"
                            wire:click="addCalibrationPrompt"
                            class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium text-primary-600 bg-primary-50 dark:bg-primary-900/30 dark:text-primary-400 rounded-lg hover:bg-primary-100 dark:hover:bg-primary-900/50 transition"
                        >
                            <x-heroicon-o-plus class="w-4 h-4" />
                            Ajouter un prompt
                        </button>
                    </div>

                    @foreach($calibrationPrompts as $index => $promptData)
                        <div class="relative border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                            <div class="px-4 py-2 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                                <span class="font-medium text-gray-700 dark:text-gray-300">Prompt #{{ $index + 1 }}</span>
                                <div class="flex items-center gap-2">
                                    @if(isset($calibrationResults[$index]))
                                        @if($calibrationResults[$index]['success'])
                                            <span class="text-xs text-success-600 dark:text-success-400">
                                                ✓ {{ $calibrationResults[$index]['processing_time'] }}s
                                            </span>
                                        @else
                                            <span class="text-xs text-danger-600 dark:text-danger-400">
                                                ✗ Erreur
                                            </span>
                                        @endif
                                    @endif
                                    @if(count($calibrationPrompts) > 1)
                                        <button
                                            type="button"
                                            wire:click="removeCalibrationPrompt({{ $index }})"
                                            class="text-danger-500 hover:text-danger-700"
                                        >
                                            <x-heroicon-o-trash class="w-4 h-4" />
                                        </button>
                                    @endif
                                </div>
                            </div>
                            <textarea
                                wire:model.lazy="calibrationPrompts.{{ $index }}.prompt"
                                rows="8"
                                class="block w-full border-0 bg-transparent focus:ring-0 text-sm font-mono resize-y dark:text-gray-100"
                                placeholder="Entrez votre prompt ici..."
                            ></textarea>

                            {{-- Résultat pour ce prompt --}}
                            @if(isset($calibrationResults[$index]))
                                <div class="border-t border-gray-200 dark:border-gray-700">
                                    <div class="px-4 py-2 bg-{{ $calibrationResults[$index]['success'] ? 'success' : 'danger' }}-50 dark:bg-{{ $calibrationResults[$index]['success'] ? 'success' : 'danger' }}-900/20 flex items-center justify-between">
                                        <span class="text-sm font-medium text-{{ $calibrationResults[$index]['success'] ? 'success' : 'danger' }}-700 dark:text-{{ $calibrationResults[$index]['success'] ? 'success' : 'danger' }}-400">
                                            @if($calibrationResults[$index]['success'])
                                                Résultat ({{ strlen($calibrationResults[$index]['markdown']) }} chars)
                                            @else
                                                Erreur
                                            @endif
                                        </span>
                                        @if($calibrationResults[$index]['success'])
                                            <div class="flex items-center gap-2">
                                                <button
                                                    type="button"
                                                    onclick="navigator.clipboard.writeText(document.getElementById('result-{{ $index }}').innerText)"
                                                    class="text-xs text-gray-500 hover:text-gray-700 dark:hover:text-gray-300"
                                                >
                                                    Copier
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="usePromptAsDefault({{ $index }})"
                                                    class="text-xs text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300"
                                                >
                                                    Utiliser comme défaut
                                                </button>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="p-4 max-h-96 overflow-y-auto bg-white dark:bg-gray-900">
                                        @if($calibrationResults[$index]['success'])
                                            <pre id="result-{{ $index }}" class="text-sm font-mono whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ $calibrationResults[$index]['markdown'] }}</pre>
                                        @else
                                            <p class="text-sm text-danger-600 dark:text-danger-400">{{ $calibrationResults[$index]['error'] }}</p>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Bouton de lancement --}}
                <div class="flex justify-center">
                    <button
                        type="button"
                        wire:click="runCalibration"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 px-6 py-3 text-lg font-semibold text-white bg-primary-600 rounded-xl hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed transition"
                    >
                        <span wire:loading.remove wire:target="runCalibration">
                            <x-heroicon-o-play class="w-5 h-5" />
                        </span>
                        <span wire:loading wire:target="runCalibration">
                            <x-heroicon-o-arrow-path class="w-5 h-5 animate-spin" />
                        </span>
                        <span wire:loading.remove wire:target="runCalibration">Lancer la calibration</span>
                        <span wire:loading wire:target="runCalibration">Traitement en cours...</span>
                    </button>
                </div>

                @if($isCalibrating)
                    <div class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                        <div class="flex items-center gap-3">
                            <x-heroicon-o-arrow-path class="w-5 h-5 text-blue-600 animate-spin" />
                            <span class="text-blue-700 dark:text-blue-400">Calibration en cours... Le modèle traite l'image avec chaque prompt.</span>
                        </div>
                    </div>
                @endif
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

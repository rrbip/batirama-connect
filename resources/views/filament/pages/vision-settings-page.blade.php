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
                Testez différents prompts sur une image pour calibrer l'extraction. Le rapport généré peut être utilisé par une IA pour améliorer les prompts.
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
                            <x-heroicon-o-arrow-path class="w-3 h-3" />
                            Réinitialiser
                        </button>
                    </div>
                    <textarea
                        wire:model.lazy="calibrationJson"
                        rows="15"
                        class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 font-mono text-xs shadow-sm focus:border-primary-500 focus:ring-primary-500"
                        placeholder='[{"id": "test_1", "category": "OCR", "description": "Test basique", "prompt": "Votre prompt..."}]'
                    ></textarea>
                    <p class="text-xs text-gray-500">
                        Format : tableau JSON avec objets contenant <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">id</code>,
                        <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">category</code>,
                        <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">description</code> et
                        <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">prompt</code>
                    </p>
                </div>

                {{-- Bouton de lancement --}}
                <div class="flex justify-center gap-4">
                    @if($isCalibrating)
                        <button
                            type="button"
                            wire:click="cancelCalibration"
                            class="inline-flex items-center gap-2 px-6 py-3 text-lg font-semibold text-white bg-danger-600 rounded-xl hover:bg-danger-700 focus:outline-none focus:ring-2 focus:ring-danger-500 focus:ring-offset-2 transition"
                        >
                            <x-heroicon-o-stop class="w-5 h-5" />
                            Annuler
                        </button>
                    @else
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
                            <span wire:loading wire:target="runCalibration">Démarrage...</span>
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
                                @if($calibrationProgress > 0 && isset($calibrationResults[$calibrationProgress - 1]))
                                    @php $lastResult = $calibrationResults[$calibrationProgress - 1]; @endphp
                                    <p class="text-xs text-blue-600 dark:text-blue-400">
                                        Dernier test : {{ $lastResult['id'] ?? 'N/A' }}
                                        @if($lastResult['success'] ?? false)
                                            <span class="text-success-600">✓</span>
                                        @else
                                            <span class="text-danger-600">✗</span>
                                        @endif
                                    </p>
                                @endif
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Résultats de calibration --}}
                @if(!empty($calibrationResults))
                    <div class="space-y-4">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">Résultats des tests</h4>

                        {{-- Résumé rapide --}}
                        @php
                            $successCount = count(array_filter($calibrationResults, fn($r) => $r['success'] ?? false));
                            $totalCount = count($calibrationResults);
                        @endphp
                        <div class="flex items-center gap-4 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <div class="flex items-center gap-2">
                                <span class="text-lg font-bold {{ $successCount === $totalCount ? 'text-success-600' : 'text-warning-600' }}">
                                    {{ $successCount }}/{{ $totalCount }}
                                </span>
                                <span class="text-sm text-gray-600 dark:text-gray-400">tests réussis</span>
                            </div>
                        </div>

                        {{-- Liste des résultats --}}
                        <div class="grid gap-3">
                            @foreach($calibrationResults as $index => $result)
                                <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                                    <div class="px-4 py-2 {{ $result['success'] ? 'bg-success-50 dark:bg-success-900/20' : 'bg-danger-50 dark:bg-danger-900/20' }} flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            @if($result['success'])
                                                <span class="text-success-600">✓</span>
                                            @else
                                                <span class="text-danger-600">✗</span>
                                            @endif
                                            <div>
                                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $result['id'] }}</span>
                                                <span class="ml-2 px-2 py-0.5 text-xs bg-gray-200 dark:bg-gray-600 rounded">{{ $result['category'] }}</span>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2 text-xs">
                                            <span class="text-gray-500">{{ $result['processing_time'] }}s</span>
                                            @if($result['success'])
                                                <button
                                                    type="button"
                                                    wire:click="usePromptAsDefault('{{ $result['id'] }}')"
                                                    class="text-primary-600 hover:text-primary-700 dark:text-primary-400"
                                                >
                                                    Utiliser ce prompt
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="p-3 text-xs text-gray-600 dark:text-gray-400">
                                        {{ $result['description'] }}
                                    </div>
                                    @if($result['success'] && $result['markdown'])
                                        <details class="border-t border-gray-200 dark:border-gray-700">
                                            <summary class="px-4 py-2 text-xs font-medium text-gray-600 dark:text-gray-400 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800">
                                                Voir le résultat ({{ strlen($result['markdown']) }} chars)
                                            </summary>
                                            <pre class="p-3 bg-gray-50 dark:bg-gray-900 text-xs font-mono whitespace-pre-wrap overflow-x-auto max-h-48 overflow-y-auto">{{ $result['markdown'] }}</pre>
                                        </details>
                                    @elseif(!$result['success'])
                                        <div class="px-4 py-2 text-xs text-danger-600 dark:text-danger-400 border-t border-gray-200 dark:border-gray-700">
                                            Erreur : {{ $result['error'] }}
                                        </div>
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
                            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">Rapport de calibration</h4>
                            <button
                                type="button"
                                onclick="navigator.clipboard.writeText(document.getElementById('calibration-report').innerText).then(() => alert('Rapport copié !'))"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium text-white bg-primary-600 rounded-lg hover:bg-primary-700 transition"
                            >
                                <x-heroicon-o-clipboard-document class="w-4 h-4" />
                                Copier le rapport
                            </button>
                        </div>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                            <pre id="calibration-report" class="p-4 bg-gray-50 dark:bg-gray-900 text-xs font-mono whitespace-pre-wrap overflow-x-auto max-h-96 overflow-y-auto text-gray-700 dark:text-gray-300">{{ $calibrationReport }}</pre>
                        </div>
                        <p class="text-xs text-gray-500">
                            💡 Copiez ce rapport et partagez-le avec une IA pour obtenir des suggestions d'amélioration des prompts.
                        </p>
                    </div>
                @endif
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

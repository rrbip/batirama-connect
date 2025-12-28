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
    </div>
</x-filament-panels::page>

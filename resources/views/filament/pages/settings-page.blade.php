<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Info box --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-information-circle class="w-5 h-5 text-info-500" />
                    Paramètres système
                </div>
            </x-slot>

            <p class="text-sm text-gray-600 dark:text-gray-400">
                Cette page centralise les paramètres globaux de l'application.
                Les listes configurables permettent de gérer des données de référence
                (modèles IA, modes de paiement, statuts, etc.) sans modifier le code.
            </p>
        </x-filament::section>

        {{-- Formulaire --}}
        {{ $this->form }}

        {{-- Boutons d'action --}}
        @if($this->selectedList)
            <div class="flex items-center gap-3 mt-4">
                <x-filament::button
                    wire:click="saveList"
                    color="success"
                    icon="heroicon-o-check"
                >
                    Sauvegarder
                </x-filament::button>

                <x-filament::button
                    wire:click="resetToDefault"
                    wire:confirm="Restaurer les valeurs par défaut ? Les modifications non sauvegardées seront perdues."
                    color="warning"
                    icon="heroicon-o-arrow-path"
                >
                    Valeurs par défaut
                </x-filament::button>

                @php
                    $currentList = \App\Models\ConfigurableList::where('key', $this->selectedList)->first();
                @endphp
                @if($currentList && !$currentList->is_system)
                    <x-filament::button
                        wire:click="deleteList"
                        wire:confirm="Supprimer cette liste ? Cette action est irréversible."
                        color="danger"
                        icon="heroicon-o-trash"
                    >
                        Supprimer
                    </x-filament::button>
                @endif
            </div>
        @endif

        {{-- Aide contextuelle --}}
        @if($this->selectedList)
            <x-filament::section collapsed>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-question-mark-circle class="w-5 h-5" />
                        Aide
                    </div>
                </x-slot>

                <div class="prose dark:prose-invert prose-sm max-w-none">
                    <p>Les listes configurables sont utilisées dans toute l'application :</p>
                    <ul>
                        <li><strong>Modèles Gemini/OpenAI</strong> : Options disponibles dans la configuration des agents IA</li>
                        <li><strong>Raisons de skip</strong> : Options pour le mode apprentissage accéléré</li>
                        <li><strong>Modes de paiement/livraison</strong> : Seront utilisés dans la marketplace</li>
                    </ul>
                    <p class="text-warning-600 dark:text-warning-400">
                        <strong>Note :</strong> Les listes système (marquées) ne peuvent pas être supprimées.
                    </p>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>

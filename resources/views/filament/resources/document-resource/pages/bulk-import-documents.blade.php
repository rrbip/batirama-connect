<x-filament-panels::page>
    <form wire:submit="import">
        {{ $this->form }}

        <div class="mt-6 flex justify-end gap-3">
            <x-filament::button type="submit" size="lg">
                <x-slot name="icon">
                    <x-heroicon-o-arrow-up-tray class="w-5 h-5" />
                </x-slot>
                Lancer l'import
            </x-filament::button>
        </div>
    </form>

    <x-filament::section class="mt-8">
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-information-circle class="w-5 h-5 text-primary-500" />
                Guide d'utilisation
            </div>
        </x-slot>

        <div class="prose dark:prose-invert max-w-none">
            <h4>Import de fichiers multiples</h4>
            <ul>
                <li>Glissez-déposez jusqu'à 100 fichiers simultanément</li>
                <li>Formats acceptés : PDF, DOCX, TXT, MD, images (JPG, PNG, etc.)</li>
                <li>Le nom du fichier sera utilisé comme titre du document</li>
                <li>Tous les fichiers auront la même catégorie (préfixe optionnel)</li>
            </ul>

            <h4>Import d'archive ZIP</h4>
            <ul>
                <li>La structure des dossiers définit les catégories</li>
                <li>Exemple : <code>Fiches/Plomberie/guide.pdf</code> <span class="text-gray-500">Catégorie: "Fiches > Plomberie"</span></li>
                <li>Limitez la profondeur pour éviter des catégories trop longues</li>
                <li>Les fichiers non supportés seront ignorés</li>
            </ul>

            <h4>Traitement</h4>
            <ul>
                <li>Les fichiers sont traités en arrière-plan via une file d'attente</li>
                <li>Chaque document sera automatiquement extrait et indexé</li>
                <li>Consultez la liste des documents pour suivre la progression</li>
            </ul>
        </div>
    </x-filament::section>
</x-filament-panels::page>

<div class="p-8 text-center">
    <div class="flex justify-center mb-4">
        <x-heroicon-o-exclamation-triangle class="w-16 h-16 text-yellow-500" />
    </div>

    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">
        Contenu non disponible en cache
    </h3>

    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
        Le fichier n'existe pas dans le cache local. Cela peut arriver si :
    </p>

    <ul class="text-sm text-gray-600 dark:text-gray-400 text-left max-w-md mx-auto mb-6 list-disc list-inside">
        <li>Le contenu n'a jamais été téléchargé (304 Not Modified dès le premier crawl)</li>
        <li>Le cache a été nettoyé</li>
        <li>Une erreur s'est produite lors du téléchargement</li>
    </ul>

    <div class="p-3 bg-gray-100 dark:bg-gray-800 rounded-lg text-xs text-gray-500 dark:text-gray-400 mb-4">
        <p class="mb-1"><strong>URL:</strong> {{ $url }}</p>
        <p><strong>Chemin cache:</strong> {{ $storagePath }}</p>
    </div>

    <p class="text-sm text-gray-600 dark:text-gray-400">
        Utilisez le bouton <strong>"Mettre à jour"</strong> pour télécharger le contenu.
    </p>
</div>

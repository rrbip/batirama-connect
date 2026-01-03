<div
    x-data="{
        report: '',
        showReport: false,
        copied: false,
        copyReport() {
            navigator.clipboard.writeText(this.report).then(() => {
                this.copied = true;
                setTimeout(() => this.copied = false, 2000);
            });
        }
    }"
    x-on:email-test-completed.window="report = $event.detail.report; showReport = true"
    class="mt-4"
>
    {{-- Zone du rapport --}}
    <div x-show="showReport" x-cloak class="space-y-3">
        {{-- Header avec boutons --}}
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                <x-heroicon-o-document-text class="w-5 h-5" />
                Rapport de test
            </h3>
            <div class="flex gap-2">
                {{-- Bouton copier --}}
                <button
                    type="button"
                    @click="copyReport()"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg transition-colors"
                    :class="copied
                        ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300'
                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'"
                >
                    <template x-if="!copied">
                        <x-heroicon-o-clipboard-document class="w-4 h-4" />
                    </template>
                    <template x-if="copied">
                        <x-heroicon-o-check class="w-4 h-4" />
                    </template>
                    <span x-text="copied ? 'Copié !' : 'Copier le rapport'"></span>
                </button>

                {{-- Bouton fermer --}}
                <button
                    type="button"
                    @click="showReport = false; report = ''"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                >
                    <x-heroicon-o-x-mark class="w-4 h-4" />
                    Fermer
                </button>
            </div>
        </div>

        {{-- Contenu du rapport --}}
        <div class="relative">
            <pre
                x-text="report"
                class="w-full p-4 text-xs font-mono bg-gray-900 text-green-400 rounded-lg overflow-x-auto max-h-[500px] overflow-y-auto whitespace-pre"
            ></pre>
        </div>

        {{-- Instructions --}}
        <p class="text-xs text-gray-500 dark:text-gray-400">
            <x-heroicon-o-information-circle class="w-4 h-4 inline-block mr-1" />
            Vous pouvez copier ce rapport et le partager pour obtenir de l'aide.
        </p>
    </div>
</div>

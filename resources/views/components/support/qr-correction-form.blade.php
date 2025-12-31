@props([
    'messageId',
    'originalQuestion',
    'originalAnswer',
    'wireMethod' => 'saveAsLearnedResponse',
    'buttonLabel' => 'Sauver Q/R',
    'buttonIcon' => 'heroicon-o-bookmark',
    'buttonSize' => 'xs',
])

<div
    x-data="{
        showForm: false,
        question: @js($originalQuestion),
        answer: @js($originalAnswer),
        saving: false,
        async save() {
            this.saving = true;
            try {
                await $wire.{{ $wireMethod }}({{ $messageId }}, this.question, this.answer);
                this.showForm = false;
            } catch (e) {
                console.error('Error saving Q/R:', e);
            } finally {
                this.saving = false;
            }
        }
    }"
    class="inline-block"
>
    {{-- Bouton pour ouvrir le formulaire --}}
    <x-filament::button
        size="{{ $buttonSize }}"
        color="primary"
        icon="{{ $buttonIcon }}"
        x-on:click="showForm = !showForm"
        :disabled="false"
    >
        {{ $buttonLabel }}
    </x-filament::button>

    {{-- Formulaire de correction Q/R --}}
    <div
        x-show="showForm"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 transform scale-95"
        x-transition:enter-end="opacity-100 transform scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 transform scale-100"
        x-transition:leave-end="opacity-0 transform scale-95"
        class="mt-3 space-y-3 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700"
    >
        {{-- Question --}}
        <div>
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide mb-1">
                Question (modifiable)
            </label>
            <textarea
                x-model="question"
                rows="2"
                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500"
                placeholder="La question de l'utilisateur..."
            ></textarea>
        </div>

        {{-- Reponse --}}
        <div>
            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide mb-1">
                Reponse (modifiable)
            </label>
            <textarea
                x-model="answer"
                rows="4"
                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500"
                placeholder="La reponse validee..."
            ></textarea>
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-2">
            <x-filament::button
                size="xs"
                color="success"
                icon="heroicon-o-check"
                x-on:click="save()"
                x-bind:disabled="saving || !question.trim() || !answer.trim()"
            >
                <span x-show="!saving">Enregistrer dans la base</span>
                <span x-show="saving" x-cloak>Enregistrement...</span>
            </x-filament::button>

            <x-filament::button
                size="xs"
                color="gray"
                x-on:click="showForm = false; question = @js($originalQuestion); answer = @js($originalAnswer)"
            >
                Annuler
            </x-filament::button>
        </div>

        {{-- Info --}}
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
            Cette Q/R sera indexee et utilisee par l'IA pour repondre aux questions similaires.
        </p>
    </div>
</div>

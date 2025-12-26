<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Stats de la queue --}}
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
                <code class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded">php artisan queue:work --queue=llm-chunking</code>
            </div>
        </x-filament::section>

        {{-- Formulaire de configuration --}}
        <form wire:submit="save">
            {{ $this->form }}
        </form>
    </div>
</x-filament-panels::page>

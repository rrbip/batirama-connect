<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Infolist avec les statistiques --}}
        {{ $this->infolist }}

        {{-- Table des URLs --}}
        <x-filament::section>
            <x-slot name="heading">
                URLs crawlées ({{ $this->record->pages_discovered }})
            </x-slot>

            {{ $this->table }}
        </x-filament::section>
    </div>
</x-filament-panels::page>

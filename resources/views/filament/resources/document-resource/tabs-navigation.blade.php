@php
    $document = null;
    if (isset($record)) {
        $document = $record;
    } elseif (isset($getRecord) && is_callable($getRecord)) {
        $document = $getRecord();
    }

    $editUrl = $document ? \App\Filament\Resources\DocumentResource::getUrl('edit', ['record' => $document]) : '#';
    $chunksUrl = $document ? \App\Filament\Resources\DocumentResource::getUrl('chunks', ['record' => $document]) : '#';
    $chunkCount = $document?->chunk_count ?? 0;
    $currentPage = $currentPage ?? 'edit';
    $activeTab = $activeTab ?? request()->query('activeTab', 'informations');
@endphp

@if($currentPage === 'edit')
<style>
    /* Hide the Filament tabs header since we use custom navigation */
    .hidden-tabs-header .fi-tabs-header,
    .hidden-tabs-header > div > .fi-tabs-header,
    .fi-fo-tabs.hidden-tabs-header .fi-tabs-header,
    [class*="hidden-tabs-header"] .fi-tabs-header {
        display: none !important;
    }
</style>
@endif

<div class="fi-tabs flex max-w-full gap-x-1 overflow-x-auto rounded-xl bg-white p-2 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-6">
    {{-- Informations --}}
    @if($currentPage === 'edit')
        <button
            type="button"
            onclick="document.querySelector('[data-id=informations]')?.click()"
            @class([
                'fi-tabs-item group flex items-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75',
                'bg-gray-50 dark:bg-white/5' => $activeTab === 'informations',
                'hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5' => $activeTab !== 'informations',
            ])
        >
            <x-heroicon-o-information-circle @class([
                'h-5 w-5 shrink-0',
                'text-primary-600 dark:text-primary-400' => $activeTab === 'informations',
                'text-gray-400 dark:text-gray-500' => $activeTab !== 'informations',
            ]) />
            <span @class([
                'text-primary-600 dark:text-primary-400' => $activeTab === 'informations',
                'text-gray-500 dark:text-gray-400' => $activeTab !== 'informations',
            ])>Informations</span>
        </button>
    @else
        <a
            href="{{ $editUrl }}?activeTab=informations"
            class="fi-tabs-item group flex items-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75 hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
        >
            <x-heroicon-o-information-circle class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500" />
            <span class="text-gray-500 dark:text-gray-400">Informations</span>
        </a>
    @endif

    {{-- Pipeline --}}
    @if($currentPage === 'edit')
        <button
            type="button"
            onclick="document.querySelector('[data-id=pipeline]')?.click()"
            @class([
                'fi-tabs-item group flex items-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75',
                'bg-gray-50 dark:bg-white/5' => $activeTab === 'pipeline',
                'hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5' => $activeTab !== 'pipeline',
            ])
        >
            <x-heroicon-o-adjustments-horizontal @class([
                'h-5 w-5 shrink-0',
                'text-primary-600 dark:text-primary-400' => $activeTab === 'pipeline',
                'text-gray-400 dark:text-gray-500' => $activeTab !== 'pipeline',
            ]) />
            <span @class([
                'text-primary-600 dark:text-primary-400' => $activeTab === 'pipeline',
                'text-gray-500 dark:text-gray-400' => $activeTab !== 'pipeline',
            ])>Pipeline</span>
        </button>
    @else
        <a
            href="{{ $editUrl }}?activeTab=pipeline"
            class="fi-tabs-item group flex items-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75 hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
        >
            <x-heroicon-o-adjustments-horizontal class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500" />
            <span class="text-gray-500 dark:text-gray-400">Pipeline</span>
        </a>
    @endif

    {{-- Indexation --}}
    @if($currentPage === 'edit')
        <button
            type="button"
            onclick="document.querySelector('[data-id=indexation]')?.click()"
            @class([
                'fi-tabs-item group flex items-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75',
                'bg-gray-50 dark:bg-white/5' => $activeTab === 'indexation',
                'hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5' => $activeTab !== 'indexation',
            ])
        >
            <x-heroicon-o-magnifying-glass @class([
                'h-5 w-5 shrink-0',
                'text-primary-600 dark:text-primary-400' => $activeTab === 'indexation',
                'text-gray-400 dark:text-gray-500' => $activeTab !== 'indexation',
            ]) />
            <span @class([
                'text-primary-600 dark:text-primary-400' => $activeTab === 'indexation',
                'text-gray-500 dark:text-gray-400' => $activeTab !== 'indexation',
            ])>Indexation</span>
        </button>
    @else
        <a
            href="{{ $editUrl }}?activeTab=indexation"
            class="fi-tabs-item group flex items-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75 hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
        >
            <x-heroicon-o-magnifying-glass class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500" />
            <span class="text-gray-500 dark:text-gray-400">Indexation</span>
        </a>
    @endif

    {{-- Chunks (toujours un lien direct) --}}
    @if($chunkCount > 0)
        <a
            href="{{ $chunksUrl }}"
            @class([
                'fi-tabs-item group flex items-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75',
                'bg-gray-50 dark:bg-white/5' => $currentPage === 'chunks',
                'hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5' => $currentPage !== 'chunks',
            ])
        >
            <x-heroicon-o-squares-2x2 @class([
                'h-5 w-5 shrink-0',
                'text-primary-600 dark:text-primary-400' => $currentPage === 'chunks',
                'text-gray-400 dark:text-gray-500' => $currentPage !== 'chunks',
            ]) />
            <span @class([
                'text-primary-600 dark:text-primary-400' => $currentPage === 'chunks',
                'text-gray-500 dark:text-gray-400' => $currentPage !== 'chunks',
            ])>Chunks ({{ $chunkCount }})</span>
        </a>
    @endif
</div>

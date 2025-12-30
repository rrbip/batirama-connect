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
    $activeTab = request()->query('activeTab', '-informations-tab');
@endphp

@if($currentPage === 'edit')
<style>
    /* Hide the Filament tabs header since we use custom navigation */
    .fi-fo-tabs .fi-tabs,
    .fi-fo-tabs > nav,
    .fi-fo-tabs > div > nav,
    .hidden-tabs-header .fi-tabs,
    .hidden-tabs-header > nav,
    [class*="fi-fo-tabs"] > div:first-child:has(button) {
        display: none !important;
    }
</style>
@endif

<div wire:ignore class="fi-tabs flex max-w-full gap-x-1 overflow-x-auto rounded-xl bg-white p-2 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-6">
    {{-- Informations --}}
    <a
        href="{{ $editUrl }}?activeTab=-informations-tab"
        @class([
            'fi-tabs-item group flex items-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75',
            'bg-gray-50 dark:bg-white/5' => $currentPage === 'edit' && $activeTab === '-informations-tab',
            'hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5' => !($currentPage === 'edit' && $activeTab === '-informations-tab'),
        ])
    >
        <x-heroicon-o-information-circle @class([
            'h-5 w-5 shrink-0',
            'text-primary-600 dark:text-primary-400' => $currentPage === 'edit' && $activeTab === '-informations-tab',
            'text-gray-400 dark:text-gray-500' => !($currentPage === 'edit' && $activeTab === '-informations-tab'),
        ]) />
        <span @class([
            'text-primary-600 dark:text-primary-400' => $currentPage === 'edit' && $activeTab === '-informations-tab',
            'text-gray-500 dark:text-gray-400' => !($currentPage === 'edit' && $activeTab === '-informations-tab'),
        ])>Informations</span>
    </a>

    {{-- Pipeline --}}
    <a
        href="{{ $editUrl }}?activeTab=-pipeline-tab"
        @class([
            'fi-tabs-item group flex items-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75',
            'bg-gray-50 dark:bg-white/5' => $currentPage === 'edit' && $activeTab === '-pipeline-tab',
            'hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5' => !($currentPage === 'edit' && $activeTab === '-pipeline-tab'),
        ])
    >
        <x-heroicon-o-adjustments-horizontal @class([
            'h-5 w-5 shrink-0',
            'text-primary-600 dark:text-primary-400' => $currentPage === 'edit' && $activeTab === '-pipeline-tab',
            'text-gray-400 dark:text-gray-500' => !($currentPage === 'edit' && $activeTab === '-pipeline-tab'),
        ]) />
        <span @class([
            'text-primary-600 dark:text-primary-400' => $currentPage === 'edit' && $activeTab === '-pipeline-tab',
            'text-gray-500 dark:text-gray-400' => !($currentPage === 'edit' && $activeTab === '-pipeline-tab'),
        ])>Pipeline</span>
    </a>

    {{-- Indexation --}}
    <a
        href="{{ $editUrl }}?activeTab=-indexation-tab"
        @class([
            'fi-tabs-item group flex items-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75',
            'bg-gray-50 dark:bg-white/5' => $currentPage === 'edit' && $activeTab === '-indexation-tab',
            'hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5' => !($currentPage === 'edit' && $activeTab === '-indexation-tab'),
        ])
    >
        <x-heroicon-o-magnifying-glass @class([
            'h-5 w-5 shrink-0',
            'text-primary-600 dark:text-primary-400' => $currentPage === 'edit' && $activeTab === '-indexation-tab',
            'text-gray-400 dark:text-gray-500' => !($currentPage === 'edit' && $activeTab === '-indexation-tab'),
        ]) />
        <span @class([
            'text-primary-600 dark:text-primary-400' => $currentPage === 'edit' && $activeTab === '-indexation-tab',
            'text-gray-500 dark:text-gray-400' => !($currentPage === 'edit' && $activeTab === '-indexation-tab'),
        ])>Indexation</span>
    </a>

    {{-- Chunks --}}
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

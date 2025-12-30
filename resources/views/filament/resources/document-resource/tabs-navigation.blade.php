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
    $activeTab = $activeTab ?? request()->query('activeTab', 'informations');
    $currentPage = $currentPage ?? 'edit'; // 'edit' or 'chunks'
@endphp

<div class="fi-tabs flex max-w-full gap-x-1 overflow-x-auto rounded-xl bg-white p-2 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-6">
    <a
        href="{{ $editUrl }}?activeTab=informations"
        @class([
            'fi-tabs-item group flex items-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75',
            'bg-gray-50 dark:bg-white/5' => $currentPage === 'edit' && $activeTab === 'informations',
            'hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5' => !($currentPage === 'edit' && $activeTab === 'informations'),
        ])
    >
        <x-heroicon-o-information-circle @class([
            'h-5 w-5 shrink-0',
            'text-primary-600 dark:text-primary-400' => $currentPage === 'edit' && $activeTab === 'informations',
            'text-gray-400 dark:text-gray-500' => !($currentPage === 'edit' && $activeTab === 'informations'),
        ]) />
        <span @class([
            'text-primary-600 dark:text-primary-400' => $currentPage === 'edit' && $activeTab === 'informations',
            'text-gray-500 dark:text-gray-400' => !($currentPage === 'edit' && $activeTab === 'informations'),
        ])>Informations</span>
    </a>

    <a
        href="{{ $editUrl }}?activeTab=pipeline"
        @class([
            'fi-tabs-item group flex items-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75',
            'bg-gray-50 dark:bg-white/5' => $currentPage === 'edit' && $activeTab === 'pipeline',
            'hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5' => !($currentPage === 'edit' && $activeTab === 'pipeline'),
        ])
    >
        <x-heroicon-o-adjustments-horizontal @class([
            'h-5 w-5 shrink-0',
            'text-primary-600 dark:text-primary-400' => $currentPage === 'edit' && $activeTab === 'pipeline',
            'text-gray-400 dark:text-gray-500' => !($currentPage === 'edit' && $activeTab === 'pipeline'),
        ]) />
        <span @class([
            'text-primary-600 dark:text-primary-400' => $currentPage === 'edit' && $activeTab === 'pipeline',
            'text-gray-500 dark:text-gray-400' => !($currentPage === 'edit' && $activeTab === 'pipeline'),
        ])>Pipeline</span>
    </a>

    <a
        href="{{ $editUrl }}?activeTab=indexation"
        @class([
            'fi-tabs-item group flex items-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium outline-none transition duration-75',
            'bg-gray-50 dark:bg-white/5' => $currentPage === 'edit' && $activeTab === 'indexation',
            'hover:bg-gray-50 focus-visible:bg-gray-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5' => !($currentPage === 'edit' && $activeTab === 'indexation'),
        ])
    >
        <x-heroicon-o-magnifying-glass @class([
            'h-5 w-5 shrink-0',
            'text-primary-600 dark:text-primary-400' => $currentPage === 'edit' && $activeTab === 'indexation',
            'text-gray-400 dark:text-gray-500' => !($currentPage === 'edit' && $activeTab === 'indexation'),
        ]) />
        <span @class([
            'text-primary-600 dark:text-primary-400' => $currentPage === 'edit' && $activeTab === 'indexation',
            'text-gray-500 dark:text-gray-400' => !($currentPage === 'edit' && $activeTab === 'indexation'),
        ])>Indexation</span>
    </a>

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

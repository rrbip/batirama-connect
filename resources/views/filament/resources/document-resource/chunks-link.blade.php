@php
    $document = null;
    if (isset($getRecord) && is_callable($getRecord)) {
        $document = $getRecord();
    }
    $chunksUrl = $document ? route('filament.admin.resources.documents.chunks', ['record' => $document->id]) : null;
    $chunkCount = $document?->chunk_count ?? 0;
@endphp

@if($chunksUrl && $chunkCount > 0)
<div class="flex items-center justify-end mb-4">
    <a
        href="{{ $chunksUrl }}"
        class="fi-btn fi-btn-size-md inline-flex items-center justify-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold shadow-sm transition-colors duration-75 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 bg-primary-600 text-white hover:bg-primary-500"
    >
        <x-heroicon-o-squares-2x2 class="h-5 w-5" />
        <span>Gérer les chunks ({{ $chunkCount }})</span>
    </a>
</div>
@endif

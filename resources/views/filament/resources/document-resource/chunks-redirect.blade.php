@php
    $document = null;
    if (isset($getRecord) && is_callable($getRecord)) {
        $document = $getRecord();
    }
    $redirectUrl = $document ? route('filament.admin.resources.documents.chunks', ['record' => $document->id]) : null;
@endphp

@if($redirectUrl)
<div
    x-data="{ hasRedirected: false }"
    x-intersect.once="if (!hasRedirected) { hasRedirected = true; window.location.href = '{{ $redirectUrl }}'; }"
    class="flex items-center justify-center py-12"
>
    <div class="text-center">
        <x-filament::loading-indicator class="h-8 w-8 mx-auto mb-4" />
        <p class="text-sm text-gray-500 dark:text-gray-400">Chargement de la gestion des chunks...</p>
    </div>
</div>
@else
<div class="text-center py-8 text-gray-500">
    <p>Impossible de charger les chunks.</p>
</div>
@endif

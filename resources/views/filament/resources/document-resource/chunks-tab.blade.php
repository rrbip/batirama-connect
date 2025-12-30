@php
    $document = is_callable($record) ? $record(get_defined_vars()['__data']['record'] ?? null) : $record;

    if (!$document || !$document->id) {
        $chunks = collect();
    } else {
        $chunks = $document->chunks()->with('category')->orderBy('chunk_index')->get();
    }
@endphp

<x-chunks-list :chunks="$chunks" :showActions="false" :showFilters="false" :showSelection="false" />

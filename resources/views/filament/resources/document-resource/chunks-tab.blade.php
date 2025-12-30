@php
    // Get the record from Filament's form context
    $document = null;

    // Try different methods to get the record
    if (isset($getRecord) && is_callable($getRecord)) {
        $document = $getRecord();
    } elseif (isset($record)) {
        // If $record is a closure, try to call it
        if (is_callable($record)) {
            try {
                $document = $record();
            } catch (\Throwable $e) {
                $document = null;
            }
        } else {
            $document = $record;
        }
    }

    if (!$document || !($document instanceof \App\Models\Document)) {
        $chunks = collect();
    } else {
        $chunks = $document->chunks()->with('category')->orderBy('chunk_index')->get();
    }
@endphp

<x-chunks-list :chunks="$chunks" :showActions="false" :showSelection="false" />

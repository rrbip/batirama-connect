@php
    // Get record from Filament ViewField context
    $record = $getRecord();
    $pipelineData = $record?->pipeline_steps ?? [];
    $status = $pipelineData['status'] ?? 'not_started';
    $steps = $pipelineData['steps'] ?? [];

    // Enable polling when pipeline is running
    $isRunning = $status === 'running';

    $statusConfig = match ($status) {
        'not_started' => ['label' => 'Non démarré', 'color' => 'gray', 'icon' => 'clock'],
        'running' => ['label' => 'En cours', 'color' => 'warning', 'icon' => 'arrow-path'],
        'completed' => ['label' => 'Terminé', 'color' => 'success', 'icon' => 'check-circle'],
        'failed' => ['label' => 'Échoué', 'color' => 'danger', 'icon' => 'x-circle'],
        'error' => ['label' => 'Échoué', 'color' => 'danger', 'icon' => 'x-circle'],
        default => ['label' => $status, 'color' => 'gray', 'icon' => 'question-mark-circle'],
    };

    // Mapping des noms d'étapes techniques vers des noms lisibles
    $stepLabels = [
        'pdf_to_images' => 'PDF → Images',
        'images_to_markdown' => 'Images → Markdown',
        'image_to_markdown' => 'Image → Markdown',
        'html_to_markdown' => 'HTML → Markdown',
        'markdown_to_qr' => 'Markdown → Q/R + Indexation',
    ];

    // Mapping des outils
    $toolLabels = [
        'pdftoppm' => 'Pdftoppm',
        'imagemagick' => 'ImageMagick',
        'vision_llm' => 'Vision LLM',
        'html_converter' => 'Convertisseur HTML',
        'qr_atomique' => 'Q/R Atomique',
    ];

    // Outils disponibles par étape
    $availableTools = [
        'pdf_to_images' => ['pdftoppm', 'imagemagick'],
        'images_to_markdown' => ['vision_llm'],
        'image_to_markdown' => ['vision_llm'],
        'html_to_markdown' => ['html_converter'],
        'markdown_to_qr' => ['qr_atomique'],
    ];
@endphp

<div class="space-y-6" @if($isRunning) wire:poll.5s @endif>
    {{-- Status global --}}
    <div class="flex items-center gap-4 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium
            {{ $statusConfig['color'] === 'success' ? 'bg-success-100 text-success-800 dark:bg-success-900/30 dark:text-success-300' : '' }}
            {{ $statusConfig['color'] === 'warning' ? 'bg-warning-100 text-warning-800 dark:bg-warning-900/30 dark:text-warning-300' : '' }}
            {{ $statusConfig['color'] === 'danger' ? 'bg-danger-100 text-danger-800 dark:bg-danger-900/30 dark:text-danger-300' : '' }}
            {{ $statusConfig['color'] === 'gray' ? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' : '' }}
        ">
            @if($statusConfig['color'] === 'success')
                <x-heroicon-s-check-circle class="w-4 h-4" />
            @elseif($statusConfig['color'] === 'warning')
                <x-heroicon-s-arrow-path class="w-4 h-4 animate-spin" />
            @elseif($statusConfig['color'] === 'danger')
                <x-heroicon-s-x-circle class="w-4 h-4" />
            @else
                <x-heroicon-s-clock class="w-4 h-4" />
            @endif
            {{ $statusConfig['label'] }}
        </span>

        @if(isset($pipelineData['started_at']))
            <span class="text-sm text-gray-500 dark:text-gray-400">
                Démarré: {{ \Carbon\Carbon::parse($pipelineData['started_at'])->format('d/m/Y H:i') }}
            </span>
        @endif

        @if(isset($pipelineData['completed_at']))
            <span class="text-sm text-gray-500 dark:text-gray-400">
                Terminé: {{ \Carbon\Carbon::parse($pipelineData['completed_at'])->format('d/m/Y H:i') }}
            </span>
        @endif

        {{-- Auto-refresh indicator --}}
        @if($isRunning)
            <span class="inline-flex items-center gap-1 text-xs text-warning-600 dark:text-warning-400">
                <x-heroicon-s-arrow-path class="w-3 h-3 animate-spin" />
                Auto-refresh actif
            </span>
        @endif

        {{-- Manual refresh button --}}
        <button
            type="button"
            onclick="window.location.reload()"
            class="ml-auto inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition"
        >
            <x-heroicon-o-arrow-path class="w-3 h-3" />
            Actualiser
        </button>
    </div>

    {{-- Debug info (document type) --}}
    <div class="text-xs text-gray-400 dark:text-gray-500 flex items-center gap-4">
        <span>Type MIME: {{ $record?->mime_type ?? 'N/A' }}</span>
        <span>Extraction: {{ $record?->extraction_status ?? 'N/A' }}</span>
        @if($record?->storage_path)
            <span>Fichier: ✓</span>
        @endif
        @if($record?->extracted_text)
            <span>Texte: ✓ ({{ number_format(strlen($record->extracted_text)) }} chars)</span>
        @endif
    </div>

    {{-- Étapes du pipeline --}}
    @if(empty($steps))
        <div class="p-6 text-center text-gray-500 dark:text-gray-400 italic bg-gray-50 dark:bg-gray-800 rounded-lg">
            <x-heroicon-o-cog-6-tooth class="w-12 h-12 mx-auto mb-3 text-gray-400" />
            <p>Pipeline non configuré ou pas encore démarré</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach($steps as $index => $step)
                @php
                    // Utiliser les bonnes clés du PipelineOrchestratorService
                    $stepStatus = $step['status'] ?? 'pending';
                    $stepName = $step['step_name'] ?? "step_{$index}";
                    $stepLabel = $stepLabels[$stepName] ?? ucfirst(str_replace('_', ' ', $stepName));
                    $tool = $step['tool_used'] ?? '-';
                    $toolLabel = $toolLabels[$tool] ?? ucfirst(str_replace('_', ' ', $tool));
                    $config = $step['tool_config'] ?? [];
                    $durationMs = $step['duration_ms'] ?? null;
                    $duration = $durationMs ? round($durationMs / 1000, 1) . 's' : '-';
                    $inputSummary = $step['input_summary'] ?? '-';
                    $outputSummary = $step['output_summary'] ?? '-';
                    $error = $step['error_message'] ?? null;
                    $outputPath = $step['output_path'] ?? null;
                    $tools = $availableTools[$stepName] ?? [];

                    // Mapping status: service uses 'success'/'error', UI uses 'completed'/'failed'
                    $displayStatus = match($stepStatus) {
                        'success' => 'completed',
                        'error' => 'failed',
                        default => $stepStatus,
                    };

                    $stepStatusConfig = match ($displayStatus) {
                        'pending' => ['label' => 'En attente', 'bg' => 'bg-gray-100 dark:bg-gray-800', 'badge' => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'],
                        'running' => ['label' => 'En cours', 'bg' => 'bg-warning-50 dark:bg-warning-900/20', 'badge' => 'bg-warning-100 text-warning-800 dark:bg-warning-900/50 dark:text-warning-300'],
                        'completed' => ['label' => 'Terminé', 'bg' => 'bg-success-50 dark:bg-success-900/20', 'badge' => 'bg-success-100 text-success-800 dark:bg-success-900/50 dark:text-success-300'],
                        'failed' => ['label' => 'Échoué', 'bg' => 'bg-danger-50 dark:bg-danger-900/20', 'badge' => 'bg-danger-100 text-danger-800 dark:bg-danger-900/50 dark:text-danger-300'],
                        default => ['label' => $stepStatus, 'bg' => 'bg-gray-100 dark:bg-gray-800', 'badge' => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'],
                    };
                @endphp

                <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                    {{-- Header de l'étape --}}
                    <div class="px-4 py-3 {{ $stepStatusConfig['bg'] }} flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <span class="font-semibold text-gray-900 dark:text-white">
                                ÉTAPE {{ $index + 1 }} : {{ $stepLabel }}
                            </span>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium {{ $stepStatusConfig['badge'] }}">
                                @if($displayStatus === 'completed')
                                    <x-heroicon-s-check class="w-3 h-3" />
                                @elseif($displayStatus === 'running')
                                    <x-heroicon-s-arrow-path class="w-3 h-3 animate-spin" />
                                @elseif($displayStatus === 'failed')
                                    <x-heroicon-s-x-mark class="w-3 h-3" />
                                @endif
                                {{ $stepStatusConfig['label'] }}
                            </span>
                        </div>
                        <span class="text-sm text-gray-600 dark:text-gray-400">Durée: {{ $duration }}</span>
                    </div>

                    {{-- Contenu de l'étape --}}
                    <div class="px-4 py-4 border-t border-gray-200 dark:border-gray-700 space-y-4">
                        {{-- Outil utilisé et config --}}
                        <div class="flex flex-wrap items-center gap-4 text-sm">
                            <div class="flex items-center gap-2">
                                <span class="text-gray-500 dark:text-gray-400">Outil :</span>
                                <span class="font-medium text-gray-900 dark:text-white">{{ $toolLabel }}</span>
                            </div>
                            @if(!empty($config))
                                <div class="flex items-center gap-2">
                                    <span class="text-gray-500 dark:text-gray-400">Config :</span>
                                    <code class="px-2 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded text-xs">
                                        @foreach($config as $key => $value)
                                            {{ $key }}: {{ is_bool($value) ? ($value ? 'oui' : 'non') : $value }}@if(!$loop->last), @endif
                                        @endforeach
                                    </code>
                                </div>
                            @endif
                        </div>

                        {{-- Entrée/Sortie --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded">
                                <span class="text-gray-500 dark:text-gray-400 block mb-1">Entrée :</span>
                                <span class="text-gray-900 dark:text-white">{{ $inputSummary }}</span>
                            </div>
                            <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded">
                                <span class="text-gray-500 dark:text-gray-400 block mb-1">Sortie :</span>
                                <span class="text-gray-900 dark:text-white">{{ $outputSummary }}</span>
                            </div>
                        </div>

                        {{-- Erreur si présente --}}
                        @if($error)
                            <div class="p-3 bg-danger-50 dark:bg-danger-900/20 border border-danger-200 dark:border-danger-700 rounded text-sm">
                                <span class="font-medium text-danger-700 dark:text-danger-300">Erreur :</span>
                                <p class="text-danger-600 dark:text-danger-400 mt-1">{{ $error }}</p>
                            </div>
                        @endif

                        {{-- Sélection d'outil (si plusieurs disponibles) --}}
                        @if(count($tools) > 1)
                            <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 block mb-2">Changer l'outil :</span>
                                <div class="flex flex-wrap gap-3">
                                    @foreach($tools as $availableTool)
                                        <label class="inline-flex items-center gap-2 cursor-pointer">
                                            <input
                                                type="radio"
                                                name="tool_step_{{ $index }}"
                                                value="{{ $availableTool }}"
                                                {{ $tool === $availableTool ? 'checked' : '' }}
                                                class="text-primary-600 focus:ring-primary-500"
                                                data-step-index="{{ $index }}"
                                            >
                                            <span class="text-sm text-gray-700 dark:text-gray-300">
                                                {{ $toolLabels[$availableTool] ?? $availableTool }}
                                                @if($tool === $availableTool)
                                                    <span class="text-xs text-gray-500">(actuel)</span>
                                                @endif
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Boutons d'action --}}
                        @if($displayStatus === 'completed' || $displayStatus === 'failed')
                            <div class="flex flex-wrap items-center gap-3 pt-2 border-t border-gray-200 dark:border-gray-700">
                                {{-- Bouton Voir le résultat --}}
                                @if($displayStatus === 'completed')
                                    @if($stepName === 'pdf_to_images' && $outputPath)
                                        <a
                                            href="{{ route('filament.admin.resources.documents.view-pipeline-images', ['record' => $record->id, 'step' => $index]) }}"
                                            target="_blank"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-900/20 rounded-lg hover:bg-primary-100 dark:hover:bg-primary-900/40 transition"
                                        >
                                            <x-heroicon-o-eye class="w-4 h-4" />
                                            Voir les images
                                        </a>
                                    @elseif(in_array($stepName, ['images_to_markdown', 'image_to_markdown', 'html_to_markdown']))
                                        <button
                                            type="button"
                                            onclick="document.getElementById('markdown-modal-{{ $index }}').showModal()"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-900/20 rounded-lg hover:bg-primary-100 dark:hover:bg-primary-900/40 transition"
                                        >
                                            <x-heroicon-o-eye class="w-4 h-4" />
                                            Voir le Markdown
                                        </button>
                                    @elseif($stepName === 'markdown_to_qr')
                                        <a
                                            href="{{ route('filament.admin.resources.documents.edit', ['record' => $record->id]) }}?activeRelationManager=chunks"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-900/20 rounded-lg hover:bg-primary-100 dark:hover:bg-primary-900/40 transition"
                                        >
                                            <x-heroicon-o-eye class="w-4 h-4" />
                                            Voir les Q/R (onglet Chunks)
                                        </a>
                                    @endif
                                @endif

                                {{-- Note: Les actions de relance sont gérées par les boutons Filament Actions en dessous --}}
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Modal pour voir le Markdown --}}
                @if(in_array($stepName, ['images_to_markdown', 'image_to_markdown', 'html_to_markdown']) && $displayStatus === 'completed')
                    <dialog id="markdown-modal-{{ $index }}" class="rounded-lg shadow-xl max-w-4xl w-full p-0 backdrop:bg-gray-900/50">
                        <div class="bg-white dark:bg-gray-900">
                            <div class="flex items-center justify-between px-4 py-3 border-b dark:border-gray-700">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Markdown extrait - Étape {{ $index + 1 }}</h3>
                                <button onclick="document.getElementById('markdown-modal-{{ $index }}').close()" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                                    <x-heroicon-o-x-mark class="w-5 h-5" />
                                </button>
                            </div>
                            <div class="p-4 max-h-[70vh] overflow-auto">
                                <pre class="text-sm text-gray-800 dark:text-gray-200 whitespace-pre-wrap font-mono bg-gray-50 dark:bg-gray-800 p-4 rounded">{{ $record->extracted_text ?? 'Aucun texte extrait' }}</pre>
                            </div>
                        </div>
                    </dialog>
                @endif

                {{-- Flèche entre les étapes --}}
                @if(!$loop->last)
                    <div class="flex justify-center">
                        <x-heroicon-o-arrow-down class="w-6 h-6 text-gray-400" />
                    </div>
                @endif
            @endforeach
        </div>
    @endif
</div>

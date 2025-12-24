<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /**
     * Telecharge le document original
     */
    public function download(Document $document): StreamedResponse
    {
        // Route protegee par middleware auth - pas de policy necessaire

        if (!Storage::disk('local')->exists($document->storage_path)) {
            abort(404, 'Fichier non trouve');
        }

        $filename = $document->original_name ?? basename($document->storage_path);

        return Storage::disk('local')->download(
            $document->storage_path,
            $filename
        );
    }

    /**
     * Affiche le document (pour les PDFs)
     */
    public function view(Document $document): StreamedResponse
    {
        // Route protegee par middleware auth - pas de policy necessaire

        if (!Storage::disk('local')->exists($document->storage_path)) {
            abort(404, 'Fichier non trouve');
        }

        $mimeType = match ($document->document_type) {
            'pdf' => 'application/pdf',
            'txt', 'md' => 'text/plain',
            default => 'application/octet-stream',
        };

        return Storage::disk('local')->response(
            $document->storage_path,
            $document->original_name ?? basename($document->storage_path),
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline',
            ]
        );
    }
}

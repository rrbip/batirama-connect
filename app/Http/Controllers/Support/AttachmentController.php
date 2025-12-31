<?php

declare(strict_types=1);

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\SupportAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Télécharge une pièce jointe via URL signée.
     */
    public function download(Request $request, string $uuid): StreamedResponse
    {
        // Vérifier la signature de l'URL
        if (!$request->hasValidSignature()) {
            abort(403, 'Lien expiré ou invalide');
        }

        $attachment = SupportAttachment::where('uuid', $uuid)->firstOrFail();

        // Vérifier que le fichier existe
        if (!$attachment->fileExists()) {
            abort(404, 'Fichier non trouvé');
        }

        // Vérifier le statut du scan
        if ($attachment->isInfected()) {
            abort(403, 'Fichier bloqué pour raison de sécurité');
        }

        // Avertissement si scan ignoré
        $headers = [];
        if ($attachment->wasScanSkipped()) {
            $headers['X-Warning'] = 'File was not scanned for viruses';
        }

        // Retourner le fichier
        return Storage::disk($attachment->disk)->download(
            $attachment->getFilePath(),
            $attachment->original_name,
            $headers
        );
    }

    /**
     * Affiche une image en ligne (pour preview).
     */
    public function inline(Request $request, string $uuid): StreamedResponse
    {
        if (!$request->hasValidSignature()) {
            abort(403, 'Lien expiré ou invalide');
        }

        $attachment = SupportAttachment::where('uuid', $uuid)->firstOrFail();

        if (!$attachment->fileExists()) {
            abort(404, 'Fichier non trouvé');
        }

        if ($attachment->isInfected()) {
            abort(403, 'Fichier bloqué pour raison de sécurité');
        }

        // Seulement pour les images
        if (!$attachment->isImage()) {
            abort(400, 'Ce fichier ne peut pas être affiché en ligne');
        }

        return Storage::disk($attachment->disk)->response(
            $attachment->getFilePath(),
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => 'inline',
            ]
        );
    }
}

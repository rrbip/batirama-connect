<?php

declare(strict_types=1);

namespace App\OpenApi;

/**
 * ═══════════════════════════════════════════════════════════════
 * UPLOAD ENDPOINTS (Phase 3)
 * ═══════════════════════════════════════════════════════════════
 *
 * @OA\Tag(
 *     name="File Upload",
 *     description="Upload et gestion des fichiers dans les sessions"
 * )
 *
 * ─────────────────────────────────────────────────────────────────
 * SCHEMAS
 * ─────────────────────────────────────────────────────────────────
 *
 * @OA\Schema(
 *     schema="SessionFileResponse",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="original_name", type="string", example="photo_salle_bain.jpg"),
 *     @OA\Property(property="mime_type", type="string", example="image/jpeg"),
 *     @OA\Property(property="size_bytes", type="integer", example=1024567),
 *     @OA\Property(property="file_type", type="string", enum={"image", "document", "pdf", "other"}),
 *     @OA\Property(property="url", type="string", format="uri", description="URL signée pour accéder au fichier"),
 *     @OA\Property(property="thumbnail_url", type="string", format="uri", nullable=true, description="URL du thumbnail (images uniquement)"),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 *
 * ─────────────────────────────────────────────────────────────────
 * UPLOAD ENDPOINTS
 * ─────────────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/whitelabel/sessions/{session_id}/upload",
 *     summary="Uploader un fichier",
 *     description="Upload un fichier (image, PDF, document) dans une session. Limite: 10MB par fichier, 10 fichiers par session.",
 *     operationId="uploadSessionFile",
 *     tags={"File Upload"},
 *     security={{"deploymentKey": {}}},
 *     @OA\Parameter(
 *         name="session_id",
 *         in="path",
 *         required=true,
 *         @OA\Schema(type="string", format="uuid")
 *     ),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\MediaType(
 *             mediaType="multipart/form-data",
 *             @OA\Schema(
 *                 required={"file"},
 *                 @OA\Property(property="file", type="string", format="binary", description="Fichier à uploader"),
 *                 @OA\Property(property="description", type="string", description="Description optionnelle")
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=201,
 *         description="Fichier uploadé avec succès",
 *         @OA\JsonContent(ref="#/components/schemas/SessionFileResponse")
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Type de fichier non autorisé ou taille dépassée",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Session non trouvée",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     ),
 *     @OA\Response(
 *         response=429,
 *         description="Limite de fichiers par session atteinte",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     )
 * )
 *
 * @OA\Get(
 *     path="/whitelabel/sessions/{session_id}/files",
 *     summary="Lister les fichiers d'une session",
 *     description="Retourne la liste des fichiers uploadés dans une session.",
 *     operationId="listSessionFiles",
 *     tags={"File Upload"},
 *     security={{"deploymentKey": {}}},
 *     @OA\Parameter(
 *         name="session_id",
 *         in="path",
 *         required=true,
 *         @OA\Schema(type="string", format="uuid")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Liste des fichiers",
 *         @OA\JsonContent(
 *             @OA\Property(property="files", type="array",
 *                 @OA\Items(ref="#/components/schemas/SessionFileResponse")
 *             ),
 *             @OA\Property(property="total", type="integer"),
 *             @OA\Property(property="total_size_bytes", type="integer")
 *         )
 *     )
 * )
 */
class UploadWebhookEndpoints
{
    // Cette classe sert uniquement de conteneur pour les annotations OpenAPI
}

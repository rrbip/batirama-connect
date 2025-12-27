<?php

declare(strict_types=1);

namespace App\OpenApi;

/**
 * ═══════════════════════════════════════════════════════════════
 * UPLOAD & WEBHOOK ENDPOINTS (Phase 3)
 * ═══════════════════════════════════════════════════════════════
 *
 * @OA\Tag(
 *     name="File Upload",
 *     description="Upload et gestion des fichiers dans les sessions"
 * )
 *
 * @OA\Tag(
 *     name="Webhooks",
 *     description="Configuration et logs des webhooks"
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
 * @OA\Schema(
 *     schema="WebhookConfigRequest",
 *     type="object",
 *     required={"url", "events"},
 *     @OA\Property(property="url", type="string", format="uri", example="https://api.example.com/webhooks/batirama"),
 *     @OA\Property(property="secret", type="string", description="Secret pour la signature HMAC (optionnel, auto-généré si absent)"),
 *     @OA\Property(property="events", type="array", description="Liste des événements à écouter",
 *         @OA\Items(type="string", enum={"session.started", "session.completed", "message.received", "file.uploaded", "project.created", "lead.generated"})
 *     ),
 *     @OA\Property(property="is_active", type="boolean", default=true),
 *     @OA\Property(property="max_retries", type="integer", default=3, minimum=0, maximum=10),
 *     @OA\Property(property="timeout_seconds", type="integer", default=30, minimum=5, maximum=60)
 * )
 *
 * @OA\Schema(
 *     schema="WebhookResponse",
 *     type="object",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="url", type="string"),
 *     @OA\Property(property="events", type="array", @OA\Items(type="string")),
 *     @OA\Property(property="is_active", type="boolean"),
 *     @OA\Property(property="last_triggered_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="success_count", type="integer"),
 *     @OA\Property(property="failure_count", type="integer"),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="WebhookLogResponse",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="event", type="string"),
 *     @OA\Property(property="http_status", type="integer", nullable=true),
 *     @OA\Property(property="response_time_ms", type="integer", nullable=true),
 *     @OA\Property(property="status", type="string", enum={"pending", "success", "failed", "retry"}),
 *     @OA\Property(property="attempt", type="integer"),
 *     @OA\Property(property="error_message", type="string", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="WebhookPayload",
 *     type="object",
 *     description="Structure du payload envoyé aux webhooks",
 *     @OA\Property(property="event", type="string", example="session.started"),
 *     @OA\Property(property="timestamp", type="string", format="date-time"),
 *     @OA\Property(property="session", type="object",
 *         @OA\Property(property="id", type="string", format="uuid"),
 *         @OA\Property(property="deployment_id", type="string", format="uuid"),
 *         @OA\Property(property="external_id", type="string"),
 *         @OA\Property(property="started_at", type="string", format="date-time"),
 *         @OA\Property(property="message_count", type="integer")
 *     ),
 *     @OA\Property(property="data", type="object", description="Données spécifiques à l'événement")
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
 *
 * ─────────────────────────────────────────────────────────────────
 * WEBHOOK MANAGEMENT ENDPOINTS (Editor API)
 * ─────────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/editor/webhooks",
 *     summary="Lister les webhooks",
 *     description="Retourne la liste des webhooks configurés pour l'éditeur.",
 *     operationId="listEditorWebhooks",
 *     tags={"Webhooks"},
 *     security={{"editorApiKey": {}}},
 *     @OA\Response(
 *         response=200,
 *         description="Liste des webhooks",
 *         @OA\JsonContent(
 *             @OA\Property(property="webhooks", type="array",
 *                 @OA\Items(ref="#/components/schemas/WebhookResponse")
 *             )
 *         )
 *     )
 * )
 *
 * @OA\Post(
 *     path="/editor/webhooks",
 *     summary="Créer un webhook",
 *     description="Configure un nouveau webhook pour recevoir les événements.",
 *     operationId="createEditorWebhook",
 *     tags={"Webhooks"},
 *     security={{"editorApiKey": {}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(ref="#/components/schemas/WebhookConfigRequest")
 *     ),
 *     @OA\Response(
 *         response=201,
 *         description="Webhook créé",
 *         @OA\JsonContent(
 *             allOf={
 *                 @OA\Schema(ref="#/components/schemas/WebhookResponse"),
 *                 @OA\Schema(
 *                     @OA\Property(property="secret", type="string", description="Secret pour vérifier les signatures (affiché uniquement à la création)")
 *                 )
 *             }
 *         )
 *     ),
 *     @OA\Response(
 *         response=422,
 *         description="URL invalide ou événements non reconnus",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     )
 * )
 *
 * @OA\Put(
 *     path="/editor/webhooks/{webhook_id}",
 *     summary="Modifier un webhook",
 *     description="Met à jour la configuration d'un webhook.",
 *     operationId="updateEditorWebhook",
 *     tags={"Webhooks"},
 *     security={{"editorApiKey": {}}},
 *     @OA\Parameter(
 *         name="webhook_id",
 *         in="path",
 *         required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(ref="#/components/schemas/WebhookConfigRequest")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Webhook mis à jour",
 *         @OA\JsonContent(ref="#/components/schemas/WebhookResponse")
 *     )
 * )
 *
 * @OA\Delete(
 *     path="/editor/webhooks/{webhook_id}",
 *     summary="Supprimer un webhook",
 *     description="Supprime un webhook et arrête la réception d'événements.",
 *     operationId="deleteEditorWebhook",
 *     tags={"Webhooks"},
 *     security={{"editorApiKey": {}}},
 *     @OA\Parameter(
 *         name="webhook_id",
 *         in="path",
 *         required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Webhook supprimé",
 *         @OA\JsonContent(
 *             @OA\Property(property="deleted", type="boolean", example=true)
 *         )
 *     )
 * )
 *
 * @OA\Get(
 *     path="/editor/webhooks/{webhook_id}/logs",
 *     summary="Historique des envois",
 *     description="Retourne l'historique des envois pour un webhook.",
 *     operationId="getWebhookLogs",
 *     tags={"Webhooks"},
 *     security={{"editorApiKey": {}}},
 *     @OA\Parameter(
 *         name="webhook_id",
 *         in="path",
 *         required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Parameter(
 *         name="status",
 *         in="query",
 *         required=false,
 *         description="Filtrer par statut",
 *         @OA\Schema(type="string", enum={"success", "failed", "retry"})
 *     ),
 *     @OA\Parameter(
 *         name="limit",
 *         in="query",
 *         required=false,
 *         @OA\Schema(type="integer", default=50, maximum=100)
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Historique des envois",
 *         @OA\JsonContent(
 *             @OA\Property(property="logs", type="array",
 *                 @OA\Items(ref="#/components/schemas/WebhookLogResponse")
 *             )
 *         )
 *     )
 * )
 *
 * @OA\Post(
 *     path="/editor/webhooks/{webhook_id}/test",
 *     summary="Tester un webhook",
 *     description="Envoie un événement de test au webhook pour vérifier la configuration.",
 *     operationId="testEditorWebhook",
 *     tags={"Webhooks"},
 *     security={{"editorApiKey": {}}},
 *     @OA\Parameter(
 *         name="webhook_id",
 *         in="path",
 *         required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Résultat du test",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="boolean"),
 *             @OA\Property(property="http_status", type="integer"),
 *             @OA\Property(property="response_time_ms", type="integer"),
 *             @OA\Property(property="error", type="string", nullable=true)
 *         )
 *     )
 * )
 */
class UploadWebhookEndpoints
{
    // Cette classe sert uniquement de conteneur pour les annotations OpenAPI
}

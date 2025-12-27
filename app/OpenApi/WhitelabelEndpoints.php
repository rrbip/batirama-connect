<?php

declare(strict_types=1);

namespace App\OpenApi;

/**
 * ═══════════════════════════════════════════════════════════════
 * WHITELABEL SESSION ENDPOINTS
 * ═══════════════════════════════════════════════════════════════
 *
 * @OA\Post(
 *     path="/whitelabel/sessions",
 *     summary="Créer une nouvelle session de chat",
 *     description="Crée une session pour un artisan identifié par son external_id. Retourne le session_id et la configuration de branding.",
 *     operationId="createWhitelabelSession",
 *     tags={"Whitelabel Sessions"},
 *     security={{"deploymentKey": {}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(ref="#/components/schemas/CreateSessionRequest")
 *     ),
 *     @OA\Response(
 *         response=201,
 *         description="Session créée avec succès",
 *         @OA\JsonContent(ref="#/components/schemas/SessionResponse")
 *     ),
 *     @OA\Response(
 *         response=401,
 *         description="Clé de déploiement invalide",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     ),
 *     @OA\Response(
 *         response=403,
 *         description="Domaine non autorisé ou déploiement désactivé",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Artisan non trouvé (external_id invalide)",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     ),
 *     @OA\Response(
 *         response=429,
 *         description="Quota ou rate limit atteint",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     )
 * )
 *
 * @OA\Get(
 *     path="/whitelabel/sessions/{session_id}",
 *     summary="Récupérer une session",
 *     description="Retourne les détails d'une session existante avec son historique de messages.",
 *     operationId="getWhitelabelSession",
 *     tags={"Whitelabel Sessions"},
 *     security={{"deploymentKey": {}}},
 *     @OA\Parameter(
 *         name="session_id",
 *         in="path",
 *         required=true,
 *         description="UUID de la session",
 *         @OA\Schema(type="string", format="uuid")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Session trouvée",
 *         @OA\JsonContent(
 *             allOf={
 *                 @OA\Schema(ref="#/components/schemas/SessionResponse"),
 *                 @OA\Schema(
 *                     @OA\Property(property="messages", type="array",
 *                         @OA\Items(ref="#/components/schemas/MessageResponse")
 *                     )
 *                 )
 *             }
 *         )
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Session non trouvée",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     )
 * )
 *
 * @OA\Delete(
 *     path="/whitelabel/sessions/{session_id}",
 *     summary="Fermer une session",
 *     description="Archive la session et empêche l'envoi de nouveaux messages.",
 *     operationId="closeWhitelabelSession",
 *     tags={"Whitelabel Sessions"},
 *     security={{"deploymentKey": {}}},
 *     @OA\Parameter(
 *         name="session_id",
 *         in="path",
 *         required=true,
 *         description="UUID de la session",
 *         @OA\Schema(type="string", format="uuid")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Session fermée",
 *         @OA\JsonContent(
 *             @OA\Property(property="status", type="string", example="archived"),
 *             @OA\Property(property="closed_at", type="string", format="datetime")
 *         )
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Session non trouvée",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     )
 * )
 *
 * ═══════════════════════════════════════════════════════════════
 * WHITELABEL MESSAGE ENDPOINTS
 * ═══════════════════════════════════════════════════════════════
 *
 * @OA\Post(
 *     path="/whitelabel/sessions/{session_id}/messages",
 *     summary="Envoyer un message",
 *     description="Envoie un message utilisateur et reçoit la réponse de l'assistant IA.",
 *     operationId="sendWhitelabelMessage",
 *     tags={"Whitelabel Messages"},
 *     security={{"deploymentKey": {}}},
 *     @OA\Parameter(
 *         name="session_id",
 *         in="path",
 *         required=true,
 *         description="UUID de la session",
 *         @OA\Schema(type="string", format="uuid")
 *     ),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(ref="#/components/schemas/SendMessageRequest")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Message envoyé et réponse générée",
 *         @OA\JsonContent(ref="#/components/schemas/MessageResponse")
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Message invalide",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Session non trouvée ou fermée",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     ),
 *     @OA\Response(
 *         response=429,
 *         description="Quota de messages atteint",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     )
 * )
 *
 * @OA\Post(
 *     path="/whitelabel/sessions/{session_id}/messages/stream",
 *     summary="Envoyer un message (streaming)",
 *     description="Envoie un message et reçoit la réponse en streaming SSE.",
 *     operationId="streamWhitelabelMessage",
 *     tags={"Whitelabel Messages"},
 *     security={{"deploymentKey": {}}},
 *     @OA\Parameter(
 *         name="session_id",
 *         in="path",
 *         required=true,
 *         description="UUID de la session",
 *         @OA\Schema(type="string", format="uuid")
 *     ),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(ref="#/components/schemas/SendMessageRequest")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Stream SSE de la réponse",
 *         @OA\MediaType(
 *             mediaType="text/event-stream",
 *             @OA\Schema(type="string")
 *         )
 *     )
 * )
 *
 * ═══════════════════════════════════════════════════════════════
 * WHITELABEL CONFIG ENDPOINTS
 * ═══════════════════════════════════════════════════════════════
 *
 * @OA\Get(
 *     path="/whitelabel/config",
 *     summary="Récupérer la configuration du déploiement",
 *     description="Retourne la configuration de branding et les capacités du déploiement.",
 *     operationId="getWhitelabelConfig",
 *     tags={"Whitelabel Config"},
 *     security={{"deploymentKey": {}}},
 *     @OA\Response(
 *         response=200,
 *         description="Configuration du déploiement",
 *         @OA\JsonContent(ref="#/components/schemas/DeploymentConfigResponse")
 *     ),
 *     @OA\Response(
 *         response=401,
 *         description="Clé de déploiement invalide",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     )
 * )
 *
 * ═══════════════════════════════════════════════════════════════
 * EDITOR MANAGEMENT ENDPOINTS
 * ═══════════════════════════════════════════════════════════════
 *
 * @OA\Get(
 *     path="/editor/deployments",
 *     summary="Lister les déploiements de l'éditeur",
 *     description="Retourne la liste des déploiements de l'éditeur authentifié.",
 *     operationId="listEditorDeployments",
 *     tags={"Editor Management"},
 *     security={{"editorApiKey": {}}},
 *     @OA\Response(
 *         response=200,
 *         description="Liste des déploiements",
 *         @OA\JsonContent(
 *             type="array",
 *             @OA\Items(
 *                 @OA\Property(property="id", type="integer"),
 *                 @OA\Property(property="uuid", type="string", format="uuid"),
 *                 @OA\Property(property="name", type="string"),
 *                 @OA\Property(property="agent_name", type="string"),
 *                 @OA\Property(property="deployment_key", type="string"),
 *                 @OA\Property(property="is_active", type="boolean"),
 *                 @OA\Property(property="sessions_count", type="integer"),
 *                 @OA\Property(property="created_at", type="string", format="datetime")
 *             )
 *         )
 *     )
 * )
 *
 * @OA\Post(
 *     path="/editor/deployments",
 *     summary="Créer un nouveau déploiement",
 *     description="Crée un nouveau déploiement d'agent pour l'éditeur.",
 *     operationId="createEditorDeployment",
 *     tags={"Editor Management"},
 *     security={{"editorApiKey": {}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(ref="#/components/schemas/CreateDeploymentRequest")
 *     ),
 *     @OA\Response(
 *         response=201,
 *         description="Déploiement créé",
 *         @OA\JsonContent(
 *             @OA\Property(property="id", type="integer"),
 *             @OA\Property(property="uuid", type="string", format="uuid"),
 *             @OA\Property(property="deployment_key", type="string", description="Clé à utiliser pour l'intégration"),
 *             @OA\Property(property="created_at", type="string", format="datetime")
 *         )
 *     ),
 *     @OA\Response(
 *         response=422,
 *         description="Données invalides",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     ),
 *     @OA\Response(
 *         response=429,
 *         description="Quota de déploiements atteint",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     )
 * )
 *
 * @OA\Post(
 *     path="/editor/artisans",
 *     summary="Lier un artisan",
 *     description="Lie un artisan existant à l'éditeur avec un external_id.",
 *     operationId="linkArtisan",
 *     tags={"Editor Management"},
 *     security={{"editorApiKey": {}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(ref="#/components/schemas/LinkArtisanRequest")
 *     ),
 *     @OA\Response(
 *         response=201,
 *         description="Artisan lié",
 *         @OA\JsonContent(
 *             @OA\Property(property="link_id", type="integer"),
 *             @OA\Property(property="artisan_id", type="integer"),
 *             @OA\Property(property="external_id", type="string"),
 *             @OA\Property(property="linked_at", type="string", format="datetime")
 *         )
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Artisan non trouvé (email inexistant)",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     ),
 *     @OA\Response(
 *         response=409,
 *         description="Artisan déjà lié ou external_id en doublon",
 *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 *     )
 * )
 *
 * @OA\Get(
 *     path="/editor/artisans",
 *     summary="Lister les artisans liés",
 *     description="Retourne la liste des artisans liés à l'éditeur.",
 *     operationId="listLinkedArtisans",
 *     tags={"Editor Management"},
 *     security={{"editorApiKey": {}}},
 *     @OA\Response(
 *         response=200,
 *         description="Liste des artisans",
 *         @OA\JsonContent(
 *             type="array",
 *             @OA\Items(
 *                 @OA\Property(property="link_id", type="integer"),
 *                 @OA\Property(property="external_id", type="string"),
 *                 @OA\Property(property="artisan_name", type="string"),
 *                 @OA\Property(property="artisan_email", type="string"),
 *                 @OA\Property(property="company_name", type="string"),
 *                 @OA\Property(property="is_active", type="boolean"),
 *                 @OA\Property(property="sessions_count", type="integer"),
 *                 @OA\Property(property="linked_at", type="string", format="datetime")
 *             )
 *         )
 *     )
 * )
 *
 * @OA\Get(
 *     path="/editor/stats",
 *     summary="Statistiques de l'éditeur",
 *     description="Retourne les statistiques d'utilisation de l'éditeur.",
 *     operationId="getEditorStats",
 *     tags={"Editor Management"},
 *     security={{"editorApiKey": {}}},
 *     @OA\Parameter(
 *         name="period",
 *         in="query",
 *         required=false,
 *         description="Période (day, week, month)",
 *         @OA\Schema(type="string", default="month")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Statistiques",
 *         @OA\JsonContent(
 *             @OA\Property(property="period", type="string"),
 *             @OA\Property(property="sessions_count", type="integer"),
 *             @OA\Property(property="messages_count", type="integer"),
 *             @OA\Property(property="unique_artisans", type="integer"),
 *             @OA\Property(property="quotas", type="object",
 *                 @OA\Property(property="sessions_used", type="integer"),
 *                 @OA\Property(property="sessions_max", type="integer"),
 *                 @OA\Property(property="messages_used", type="integer"),
 *                 @OA\Property(property="messages_max", type="integer")
 *             )
 *         )
 *     )
 * )
 */
class WhitelabelEndpoints
{
    // Cette classe sert uniquement de conteneur pour les annotations OpenAPI
}

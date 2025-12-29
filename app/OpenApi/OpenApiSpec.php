<?php

declare(strict_types=1);

namespace App\OpenApi;

/**
 * @OA\Info(
 *     version="1.0.0",
 *     title="Batirama Connect API",
 *     description="API pour l'intégration whitelabel des agents IA Batirama dans les applications tierces.",
 *     @OA\Contact(
 *         email="support@batirama.fr",
 *         name="Batirama Support"
 *     ),
 *     @OA\License(
 *         name="Proprietary",
 *         url="https://batirama.fr/terms"
 *     )
 * )
 *
 * @OA\Server(
 *     url="/api",
 *     description="API Server"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="deploymentKey",
 *     type="apiKey",
 *     in="header",
 *     name="X-Deployment-Key",
 *     description="Clé de déploiement fournie lors de la création du déploiement"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="editorApiKey",
 *     type="http",
 *     scheme="bearer",
 *     description="API Key de l'éditeur (Bearer token)"
 * )
 *
 * @OA\Tag(
 *     name="Whitelabel Sessions",
 *     description="Gestion des sessions de chat whitelabel"
 * )
 *
 * @OA\Tag(
 *     name="Whitelabel Messages",
 *     description="Envoi et réception de messages"
 * )
 *
 * @OA\Tag(
 *     name="Whitelabel Config",
 *     description="Configuration du déploiement"
 * )
 *
 * @OA\Tag(
 *     name="Editor Management",
 *     description="Gestion des déploiements et artisans (pour éditeurs)"
 * )
 *
 * ═══════════════════════════════════════════════════════════════
 * SCHEMAS - Request/Response Objects
 * ═══════════════════════════════════════════════════════════════
 *
 * @OA\Schema(
 *     schema="CreateSessionRequest",
 *     type="object",
 *     required={"external_id"},
 *     @OA\Property(property="external_id", type="string", example="DUR-001", description="ID de l'artisan chez l'éditeur"),
 *     @OA\Property(property="particulier_email", type="string", format="email", example="martin@email.com", description="Email du client final (optionnel)"),
 *     @OA\Property(property="particulier_name", type="string", example="M. Martin", description="Nom du client final (optionnel)"),
 *     @OA\Property(property="context", type="object", description="Contexte métier pour la session",
 *         @OA\Property(property="project_type", type="string", example="renovation_salle_bain"),
 *         @OA\Property(property="surface_m2", type="number", example=12)
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="SessionResponse",
 *     type="object",
 *     @OA\Property(property="session_id", type="string", format="uuid", example="550e8400-e29b-41d4-a716-446655440000"),
 *     @OA\Property(property="status", type="string", enum={"active", "archived"}, example="active"),
 *     @OA\Property(property="branding", type="object",
 *         @OA\Property(property="chat_title", type="string", example="Assistant Durant Peinture"),
 *         @OA\Property(property="welcome_message", type="string", example="Bonjour, comment puis-je vous aider ?"),
 *         @OA\Property(property="primary_color", type="string", example="#1E88E5"),
 *         @OA\Property(property="logo_url", type="string", example="https://example.com/logo.png")
 *     ),
 *     @OA\Property(property="created_at", type="string", format="datetime")
 * )
 *
 * @OA\Schema(
 *     schema="SendMessageRequest",
 *     type="object",
 *     required={"message"},
 *     @OA\Property(property="message", type="string", example="Je voudrais un devis pour une rénovation de salle de bain"),
 *     @OA\Property(property="attachments", type="array",
 *         @OA\Items(type="object",
 *             @OA\Property(property="type", type="string", example="image"),
 *             @OA\Property(property="url", type="string", example="https://example.com/photo.jpg")
 *         )
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="MessageResponse",
 *     type="object",
 *     @OA\Property(property="message_id", type="string", format="uuid"),
 *     @OA\Property(property="role", type="string", enum={"user", "assistant"}, example="assistant"),
 *     @OA\Property(property="content", type="string", example="Je peux vous aider à estimer votre projet..."),
 *     @OA\Property(property="sources", type="array",
 *         @OA\Items(type="object",
 *             @OA\Property(property="title", type="string"),
 *             @OA\Property(property="score", type="number")
 *         )
 *     ),
 *     @OA\Property(property="created_at", type="string", format="datetime")
 * )
 *
 * @OA\Schema(
 *     schema="DeploymentConfigResponse",
 *     type="object",
 *     @OA\Property(property="deployment_id", type="string", format="uuid"),
 *     @OA\Property(property="agent_name", type="string", example="Assistant Devis BTP"),
 *     @OA\Property(property="branding", type="object",
 *         @OA\Property(property="chat_title", type="string"),
 *         @OA\Property(property="welcome_message", type="string"),
 *         @OA\Property(property="primary_color", type="string"),
 *         @OA\Property(property="logo_url", type="string"),
 *         @OA\Property(property="signature", type="string")
 *     ),
 *     @OA\Property(property="features", type="object",
 *         @OA\Property(property="attachments_enabled", type="boolean"),
 *         @OA\Property(property="max_message_length", type="integer")
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="ErrorResponse",
 *     type="object",
 *     @OA\Property(property="error", type="string", example="invalid_request"),
 *     @OA\Property(property="message", type="string", example="Description de l'erreur")
 * )
 *
 * @OA\Schema(
 *     schema="CreateDeploymentRequest",
 *     type="object",
 *     required={"agent_id", "name"},
 *     @OA\Property(property="agent_id", type="integer", example=1, description="ID de l'agent à déployer"),
 *     @OA\Property(property="name", type="string", example="Déploiement Production", description="Nom du déploiement"),
 *     @OA\Property(property="deployment_mode", type="string", enum={"shared", "dedicated"}, example="shared"),
 *     @OA\Property(property="allowed_domains", type="array", @OA\Items(type="string"), example={"app.ebp.com", "*.ebp.com"}),
 *     @OA\Property(property="branding", type="object",
 *         @OA\Property(property="chat_title", type="string"),
 *         @OA\Property(property="welcome_message", type="string"),
 *         @OA\Property(property="primary_color", type="string")
 *     ),
 *     @OA\Property(property="max_sessions_day", type="integer", nullable=true),
 *     @OA\Property(property="rate_limit_per_ip", type="integer", default=60)
 * )
 *
 * @OA\Schema(
 *     schema="LinkArtisanRequest",
 *     type="object",
 *     required={"artisan_email", "external_id"},
 *     @OA\Property(property="artisan_email", type="string", format="email", example="durant@peinture.fr"),
 *     @OA\Property(property="external_id", type="string", example="DUR-001", description="ID de l'artisan dans le système de l'éditeur"),
 *     @OA\Property(property="branding", type="object", nullable=true,
 *         @OA\Property(property="welcome_message", type="string"),
 *         @OA\Property(property="primary_color", type="string")
 *     ),
 *     @OA\Property(property="permissions", type="object", nullable=true,
 *         @OA\Property(property="max_sessions_month", type="integer")
 *     )
 * )
 */
class OpenApiSpec
{
    // Cette classe sert uniquement de conteneur pour les annotations OpenAPI
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateSessionRequest;
use App\Http\Requests\Api\ConversionRequest;
use App\Models\Agent;
use App\Models\AiSession;
use App\Models\Partner;
use App\Models\PublicAccessToken;
use App\Services\AI\DispatcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PartnerApiController extends Controller
{
    public function __construct(
        private DispatcherService $dispatcherService
    ) {}

    /**
     * POST /api/partners/sessions
     * Créer une session IA et envoyer un lien au client
     */
    public function createSession(CreateSessionRequest $request): JsonResponse
    {
        /** @var Partner $partner */
        $partner = $request->attributes->get('partner');

        // Récupérer l'agent
        $agentSlug = $request->input('agent_slug', $partner->default_agent_slug);
        $agent = Agent::where('slug', $agentSlug)
            ->where('is_active', true)
            ->first();

        if (!$agent) {
            return $this->error('agent_not_found', "Agent '{$agentSlug}' non trouvé", 404);
        }

        // Créer la session
        $session = AiSession::create([
            'uuid' => Str::uuid()->toString(),
            'agent_id' => $agent->id,
            'partner_id' => $partner->id,
            'external_ref' => $request->input('external_ref'),
            'client_data' => $request->input('client', []),
            'metadata' => $request->input('metadata', []),
            'is_marketplace_lead' => $request->input('options.is_marketplace_lead', false),
            'started_at' => now(),
        ]);

        // Créer le token d'accès public
        $expiresInHours = $request->input('options.expires_in_hours', 168);
        $maxUses = $request->input('options.max_uses', 1);

        $token = PublicAccessToken::create([
            'token' => Str::random(32),
            'session_id' => $session->id,
            'partner_id' => $partner->id,
            'expires_at' => now()->addHours($expiresInHours),
            'max_uses' => $maxUses,
            'uses_count' => 0,
        ]);

        $publicUrl = config('app.url') . '/c/' . $token->token;

        // Envoyer le lien via SMS/Email si demandé
        $notification = ['sent' => false];
        $sendVia = $request->input('send_via', 'none');

        if ($sendVia !== 'none') {
            $notification = $this->sendNotification(
                $sendVia,
                $request->input('client'),
                $publicUrl,
                $request->input('message_template', 'default')
            );
        }

        Log::info('Partner session created', [
            'partner' => $partner->slug,
            'session_id' => $session->uuid,
            'external_ref' => $session->external_ref,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'session_id' => 'sess_' . $session->uuid,
                'public_url' => $publicUrl,
                'token' => $token->token,
                'expires_at' => $token->expires_at->toIso8601String(),
                'max_uses' => $token->max_uses,
                'notification' => $notification,
            ],
        ], 201);
    }

    /**
     * GET /api/partners/sessions/{session_id}
     * Récupérer le résultat d'une session
     */
    public function getSession(Request $request, string $sessionId): JsonResponse
    {
        /** @var Partner $partner */
        $partner = $request->attributes->get('partner');

        // Nettoyer le préfixe sess_
        $uuid = str_replace('sess_', '', $sessionId);

        $session = AiSession::where('uuid', $uuid)->first();

        if (!$session) {
            return $this->error('session_not_found', "Session '{$sessionId}' non trouvée", 404);
        }

        // Vérifier que la session appartient au partenaire
        if ($session->partner_id !== $partner->id) {
            return $this->error('access_denied', "Cette session appartient à un autre partenaire", 403);
        }

        // Formater la réponse selon le niveau d'accès
        $data = $this->formatSessionResponse($session, $partner->data_access_level);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * POST /api/partners/conversions
     * Notifier une conversion (devis signé)
     */
    public function notifyConversion(ConversionRequest $request): JsonResponse
    {
        /** @var Partner $partner */
        $partner = $request->attributes->get('partner');

        $sessionId = $request->input('session_id');
        $uuid = str_replace('sess_', '', $sessionId);

        $session = AiSession::where('uuid', $uuid)->first();

        if (!$session) {
            return $this->error('session_not_found', "Session '{$sessionId}' non trouvée", 404);
        }

        if ($session->partner_id !== $partner->id) {
            return $this->error('access_denied', "Cette session appartient à un autre partenaire", 403);
        }

        // Mettre à jour le statut de conversion
        $session->update([
            'conversion_status' => $request->input('status'),
            'final_amount' => $request->input('final_amount'),
            'quote_ref' => $request->input('quote_ref'),
            'signed_at' => $request->input('signed_at'),
            'conversion_notes' => $request->input('notes'),
        ]);

        // Calculer la commission si applicable
        $commission = $this->calculateCommission($session, $partner);

        Log::info('Conversion notified', [
            'partner' => $partner->slug,
            'session_id' => $session->uuid,
            'status' => $request->input('status'),
            'amount' => $request->input('final_amount'),
            'commission' => $commission,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'session_id' => $sessionId,
                'conversion_status' => $request->input('status'),
                'final_amount' => $request->input('final_amount'),
                'commission' => $commission,
            ],
        ]);
    }

    /**
     * Formate la réponse session selon le niveau d'accès
     */
    private function formatSessionResponse(AiSession $session, string $accessLevel): array
    {
        $base = [
            'session_id' => 'sess_' . $session->uuid,
            'external_ref' => $session->external_ref,
            'status' => $this->getSessionStatus($session),
            'created_at' => $session->created_at->toIso8601String(),
            'completed_at' => $session->ended_at?->toIso8601String(),
        ];

        // Niveau summary : résultat basique
        if ($session->status === 'completed' || $session->ended_at) {
            $base['result'] = $this->formatSessionResult($session);
        }

        // Niveau full : inclure la conversation
        if ($accessLevel === 'full') {
            $base['conversation'] = $this->getConversation($session);
            $base['metadata'] = $this->getSessionMetadata($session);
        }

        return $base;
    }

    /**
     * Détermine le statut de la session
     */
    private function getSessionStatus(AiSession $session): string
    {
        if ($session->ended_at) {
            return 'completed';
        }

        if ($session->message_count > 0) {
            return 'in_progress';
        }

        // Vérifier si le token a expiré
        $token = PublicAccessToken::where('session_id', $session->id)->first();
        if ($token && $token->isExpired()) {
            return 'expired';
        }

        return 'pending';
    }

    /**
     * Formate le résultat de la session
     */
    private function formatSessionResult(AiSession $session): array
    {
        $lastMessage = $session->messages()
            ->where('role', 'assistant')
            ->orderBy('created_at', 'desc')
            ->first();

        return [
            'project_name' => $session->metadata['project_name'] ?? 'Projet sans nom',
            'summary' => $session->metadata['summary'] ?? $lastMessage?->content ?? '',
            'quote' => $this->extractQuote($session),
            'attachments' => $this->getAttachments($session),
            'client' => $session->client_data,
        ];
    }

    /**
     * Extrait le devis de la session
     */
    private function extractQuote(AiSession $session): ?array
    {
        // Le devis peut être stocké dans les metadata ou extrait du dernier message
        if (isset($session->metadata['quote'])) {
            return $session->metadata['quote'];
        }

        return null;
    }

    /**
     * Récupère les pièces jointes
     */
    private function getAttachments(AiSession $session): array
    {
        $messages = $session->messages()->whereNotNull('attachments')->get();

        $attachments = [];
        foreach ($messages as $message) {
            if (is_array($message->attachments)) {
                foreach ($message->attachments as $attachment) {
                    $attachments[] = $attachment;
                }
            }
        }

        return $attachments;
    }

    /**
     * Récupère la conversation complète
     */
    private function getConversation(AiSession $session): array
    {
        return $session->messages()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn($msg) => [
                'role' => $msg->role,
                'content' => $msg->content,
                'timestamp' => $msg->created_at->toIso8601String(),
                'attachments' => $msg->attachments ? collect($msg->attachments)->pluck('id')->toArray() : null,
            ])
            ->toArray();
    }

    /**
     * Récupère les métadonnées de la session
     */
    private function getSessionMetadata(AiSession $session): array
    {
        $messages = $session->messages;
        $lastAssistant = $messages->where('role', 'assistant')->last();

        return [
            'duration_seconds' => $session->ended_at
                ? $session->started_at->diffInSeconds($session->ended_at)
                : null,
            'messages_count' => $messages->count(),
            'model_used' => $lastAssistant?->model_used,
            'tokens_total' => $messages->sum('tokens_prompt') + $messages->sum('tokens_completion'),
        ];
    }

    /**
     * Calcule la commission
     */
    private function calculateCommission(AiSession $session, Partner $partner): array
    {
        // Pas de commission si ce n'est pas un lead marketplace
        if (!$session->is_marketplace_lead) {
            return [
                'applicable' => false,
                'reason' => 'Session créée par artisan (non marketplace)',
            ];
        }

        // Commission seulement si accepté ou complété
        if (!in_array($session->conversion_status, ['accepted', 'completed'])) {
            return [
                'applicable' => false,
                'reason' => 'Conversion non finalisée',
            ];
        }

        $rate = $partner->commission_rate ?? 5.0;
        $amount = ($session->final_amount * $rate) / 100;

        return [
            'applicable' => true,
            'rate' => $rate,
            'amount' => round($amount, 2),
            'status' => 'pending',
        ];
    }

    /**
     * Envoie une notification (SMS/Email)
     */
    private function sendNotification(string $via, array $client, string $url, string $template): array
    {
        // TODO: Intégration Brevo pour SMS/Email
        // Pour l'instant, on simule l'envoi

        $channel = match ($via) {
            'sms' => 'sms',
            'email' => 'email',
            'both' => 'both',
            default => null,
        };

        if (!$channel) {
            return ['sent' => false, 'reason' => 'Canal non supporté'];
        }

        // Simulation d'envoi
        Log::info('Notification sent', [
            'channel' => $channel,
            'client' => $client,
            'url' => $url,
        ]);

        return [
            'sent' => true,
            'channel' => $channel,
            'recipient' => $via === 'email' ? ($client['email'] ?? null) : ($client['phone'] ?? null),
            'sent_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Retourne une erreur JSON
     */
    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => $code,
            'message' => $message,
        ], $status);
    }
}

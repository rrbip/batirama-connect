<?php

declare(strict_types=1);

namespace App\Http\Controllers\Whitelabel;

use App\Http\Controllers\Controller;
use App\Models\AiSession;
use App\Models\AgentDeployment;
use App\Models\PublicAccessToken;
use App\Models\UserEditorLink;
use App\Services\Whitelabel\BrandingResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StandaloneChatController extends Controller
{
    public function __construct(
        private BrandingResolver $brandingResolver
    ) {}

    /**
     * GET /s/{token}
     * Page de chat standalone whitelabel
     */
    public function show(Request $request, string $token): View
    {
        // Check if token looks like a whitelabel session link token
        // Whitelabel tokens have format: wl_{deploymentId}_{random}
        if (str_starts_with($token, 'wl_')) {
            return $this->showWhitelabelSession($request, $token);
        }

        // Fallback to standard public access token
        return $this->showPublicSession($request, $token);
    }

    /**
     * Show whitelabel session page
     */
    private function showWhitelabelSession(Request $request, string $token): View
    {
        // Parse token: wl_{deploymentId}_{random}
        $parts = explode('_', $token);
        if (count($parts) < 3) {
            return $this->errorView('Lien invalide', 'Le lien de session est mal formé.');
        }

        $deploymentId = (int) $parts[1];
        $sessionToken = implode('_', array_slice($parts, 2));

        // Find deployment
        $deployment = AgentDeployment::with(['agent', 'editor'])->find($deploymentId);

        if (!$deployment) {
            return $this->errorView('Déploiement non trouvé', 'Ce lien n\'est plus valide.');
        }

        if (!$deployment->is_active) {
            return $this->errorView('Service désactivé', 'Ce service n\'est plus disponible.');
        }

        // Find or create session
        $session = AiSession::where('whitelabel_token', $token)->first();

        // Determine editor link for branding
        $editorLink = null;
        if ($session && $session->user_id) {
            $editorLink = UserEditorLink::where('editor_id', $deployment->editor_id)
                ->where('user_id', $session->user_id)
                ->first();
        }

        // Resolve branding
        $branding = $this->brandingResolver->resolveForDeployment($deployment, $editorLink);

        // Get agent
        $agent = $deployment->agent;

        return view('whitelabel.standalone', [
            'token' => $token,
            'deployment' => $deployment,
            'agent' => $agent,
            'branding' => $branding,
            'session' => $session,
            'config' => [
                'api_base' => url('/api'),
                'deployment_key' => $deployment->deployment_key,
                'max_message_length' => $deployment->getConfigValue('max_message_length', 2000),
                'attachments_enabled' => $deployment->getConfigValue('attachments_enabled', false),
            ],
        ]);
    }

    /**
     * Show public session page (fallback for /c/{token} style tokens)
     */
    private function showPublicSession(Request $request, string $token): View
    {
        $accessToken = PublicAccessToken::with(['agent', 'session'])->where('token', $token)->first();

        if (!$accessToken) {
            return $this->errorView('Lien invalide', 'Ce lien de session n\'existe pas.');
        }

        if ($accessToken->isExpired()) {
            return $this->errorView('Lien expiré', 'Ce lien a expiré. Veuillez en demander un nouveau.');
        }

        if ($accessToken->isExhausted()) {
            return $this->errorView('Lien épuisé', 'Ce lien a déjà été utilisé le nombre maximum de fois.');
        }

        $agent = $accessToken->agent;
        $session = $accessToken->session;

        // Basic branding from agent
        $branding = [
            'chat_title' => $agent->name ?? 'Assistant',
            'welcome_message' => $agent->welcome_message ?? 'Bonjour ! Comment puis-je vous aider ?',
            'primary_color' => '#1E88E5',
            'logo_url' => $agent->avatar_url ?? null,
            'powered_by' => true,
            'signature' => 'Propulsé par Batirama',
        ];

        return view('whitelabel.standalone', [
            'token' => $token,
            'deployment' => null,
            'agent' => $agent,
            'branding' => $branding,
            'session' => $session,
            'isLegacy' => true,
            'config' => [
                'api_base' => url('/api'),
                'token_mode' => true,
                'max_message_length' => 2000,
                'attachments_enabled' => true,
            ],
        ]);
    }

    /**
     * GET /widget?key=...
     * Widget embed route - loads standalone view with deployment key
     */
    public function widget(Request $request): View
    {
        $deploymentKey = $request->query('key');

        if (!$deploymentKey) {
            return $this->errorView('Configuration manquante', 'Clé de déploiement requise.');
        }

        // Find deployment by key
        $deployment = AgentDeployment::with(['agent', 'editor'])
            ->where('deployment_key', $deploymentKey)
            ->where('is_active', true)
            ->first();

        if (!$deployment) {
            return $this->errorView('Service non trouvé', 'Ce service n\'est pas disponible.');
        }

        // Get optional params for session context
        $externalId = $request->query('external_id');
        $particulierEmail = $request->query('particulier_email');
        $particulierName = $request->query('particulier_name');
        $context = $request->query('context');
        $origin = $request->query('origin');

        // Parse context if JSON string
        if ($context && is_string($context)) {
            try {
                $context = json_decode($context, true);
            } catch (\Exception $e) {
                $context = [];
            }
        }

        // Resolve branding
        $branding = $this->brandingResolver->resolveForDeployment($deployment, null);

        // Get agent
        $agent = $deployment->agent;

        return view('whitelabel.standalone', [
            'token' => null,
            'deployment' => $deployment,
            'agent' => $agent,
            'branding' => $branding,
            'session' => null,
            'isWidget' => true,
            'widgetParams' => [
                'deployment_key' => $deploymentKey,
                'external_id' => $externalId,
                'particulier_email' => $particulierEmail,
                'particulier_name' => $particulierName,
                'context' => $context,
                'origin' => $origin,
            ],
            'config' => [
                'api_base' => url('/api'),
                'deployment_key' => $deployment->deployment_key,
                'max_message_length' => $deployment->getConfigValue('max_message_length', 2000),
                'attachments_enabled' => $deployment->getConfigValue('attachments_enabled', false),
            ],
        ]);
    }

    /**
     * Create error view
     */
    private function errorView(string $title, string $message): View
    {
        return view('whitelabel.error', [
            'title' => $title,
            'message' => $message,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Whitelabel;

use App\Models\AgentDeployment;
use App\Models\AiSession;
use App\Models\UserEditorLink;
use Illuminate\Support\Facades\Cache;

/**
 * Service pour résoudre le branding avec cascade de priorités.
 *
 * Ordre de priorité (du plus spécifique au plus général):
 * 1. UserEditorLink.branding (branding artisan chez cet éditeur)
 * 2. User.branding (branding artisan par défaut)
 * 3. AgentDeployment.branding (branding du déploiement)
 * 4. Agent.whitelabel_config.default_branding (branding par défaut de l'agent)
 */
class BrandingResolver
{
    /**
     * Les champs de branding supportés.
     */
    private const BRANDING_FIELDS = [
        'chat_title',
        'welcome_message',
        'primary_color',
        'logo_url',
        'signature',
        'powered_by',
        'header_text',
        'placeholder_text',
    ];

    /**
     * Résout le branding complet pour une session.
     */
    public function resolve(AiSession $session): array
    {
        $cacheKey = "branding:session:{$session->id}";

        return Cache::remember($cacheKey, 300, function () use ($session) {
            return $this->buildBranding($session);
        });
    }

    /**
     * Résout le branding pour un déploiement (sans session).
     */
    public function resolveForDeployment(AgentDeployment $deployment, ?UserEditorLink $editorLink = null): array
    {
        $cacheKey = "branding:deployment:{$deployment->id}";

        if ($editorLink) {
            $cacheKey .= ":link:{$editorLink->id}";
        }

        return Cache::remember($cacheKey, 300, function () use ($deployment, $editorLink) {
            return $this->buildBrandingFromDeployment($deployment, $editorLink);
        });
    }

    /**
     * Construit le branding à partir d'une session.
     */
    private function buildBranding(AiSession $session): array
    {
        $deployment = $session->deployment;
        $editorLink = $session->editorLink;

        if (!$deployment) {
            // Session non-whitelabel, retourner branding agent par défaut
            return $session->agent ? $session->agent->getDefaultBranding() : $this->getDefaultBranding();
        }

        return $this->buildBrandingFromDeployment($deployment, $editorLink);
    }

    /**
     * Construit le branding à partir d'un déploiement.
     */
    private function buildBrandingFromDeployment(AgentDeployment $deployment, ?UserEditorLink $editorLink = null): array
    {
        $agent = $deployment->agent;

        // Couches de branding (du plus général au plus spécifique)
        $layers = [
            // 1. Branding par défaut
            $this->getDefaultBranding(),

            // 2. Branding agent
            $agent ? $agent->getDefaultBranding() : [],

            // 3. Branding déploiement
            $deployment->branding ?? [],
        ];

        // 4. Si on a un lien artisan, ajouter les couches artisan
        if ($editorLink) {
            // Branding artisan par défaut
            $artisan = $editorLink->artisan;
            if ($artisan && $artisan->branding) {
                $layers[] = $artisan->branding;
            }

            // Branding artisan chez cet éditeur (plus spécifique)
            if ($editorLink->branding) {
                $layers[] = $editorLink->branding;
            }
        }

        // Fusionner les couches (les dernières écrasent les premières)
        $branding = [];
        foreach ($layers as $layer) {
            $branding = array_merge($branding, array_filter($layer));
        }

        // Filtrer les champs autorisés
        $branding = array_intersect_key($branding, array_flip(self::BRANDING_FIELDS));

        // Ajouter le "Powered by" si requis par l'agent
        if ($agent && $agent->requiresPoweredByBranding()) {
            $branding['powered_by'] = $branding['powered_by'] ?? 'Powered by Batirama Connect';
            $branding['show_powered_by'] = true;
        }

        // Interpoler les variables
        return $this->interpolate($branding, $this->buildVariables($deployment, $editorLink));
    }

    /**
     * Retourne le branding par défaut du système.
     */
    private function getDefaultBranding(): array
    {
        return [
            'chat_title' => 'Assistant IA',
            'welcome_message' => 'Bonjour ! Comment puis-je vous aider ?',
            'primary_color' => '#3B82F6',
            'placeholder_text' => 'Tapez votre message...',
        ];
    }

    /**
     * Construit les variables disponibles pour l'interpolation.
     */
    private function buildVariables(AgentDeployment $deployment, ?UserEditorLink $editorLink = null): array
    {
        $vars = [
            'agent' => [
                'name' => $deployment->agent?->name ?? 'Assistant',
            ],
            'deployment' => [
                'name' => $deployment->name,
            ],
            'editor' => [
                'name' => $deployment->editor?->company_name ?? $deployment->editor?->name ?? 'Éditeur',
            ],
        ];

        if ($editorLink) {
            $artisan = $editorLink->artisan;
            $vars['artisan'] = [
                'name' => $artisan?->name ?? 'Artisan',
                'company' => $artisan?->company_name ?? '',
            ];
        }

        return $vars;
    }

    /**
     * Interpole les variables dans les chaînes de branding.
     *
     * Variables supportées:
     * - {agent.name}
     * - {deployment.name}
     * - {editor.name}
     * - {artisan.name}
     * - {artisan.company}
     */
    public function interpolate(array $branding, array $vars): array
    {
        $result = [];

        foreach ($branding as $key => $value) {
            if (!is_string($value)) {
                $result[$key] = $value;
                continue;
            }

            $result[$key] = preg_replace_callback(
                '/\{([a-z_]+)\.([a-z_]+)\}/i',
                function ($matches) use ($vars) {
                    $group = $matches[1];
                    $field = $matches[2];

                    return $vars[$group][$field] ?? '';
                },
                $value
            );

            // Nettoyer les doubles espaces et trim
            $result[$key] = trim(preg_replace('/\s+/', ' ', $result[$key]));
        }

        return $result;
    }

    /**
     * Invalide le cache de branding pour une session.
     */
    public function invalidateSession(AiSession $session): void
    {
        Cache::forget("branding:session:{$session->id}");
    }

    /**
     * Invalide le cache de branding pour un déploiement.
     */
    public function invalidateDeployment(AgentDeployment $deployment): void
    {
        Cache::forget("branding:deployment:{$deployment->id}");

        // Invalider aussi les caches avec les liens
        foreach ($deployment->sessions as $session) {
            $this->invalidateSession($session);
        }
    }
}

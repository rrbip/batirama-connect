<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class MarketplaceRolesSeeder extends Seeder
{
    public function run(): void
    {
        // ═══════════════════════════════════════════════════════════════════
        // NOUVELLES PERMISSIONS MARKETPLACE
        // ═══════════════════════════════════════════════════════════════════

        $permissions = [
            // Marketplace
            ['name' => 'Accès marketplace', 'slug' => 'marketplace.access', 'group_name' => 'marketplace'],
            ['name' => 'Gérer catalogue', 'slug' => 'catalog.manage', 'group_name' => 'marketplace'],

            // Commandes
            ['name' => 'Voir commandes', 'slug' => 'orders.view', 'group_name' => 'orders'],
            ['name' => 'Voir ses commandes', 'slug' => 'orders.view_own', 'group_name' => 'orders'],
            ['name' => 'Créer commande', 'slug' => 'orders.create', 'group_name' => 'orders'],
            ['name' => 'Traiter commandes', 'slug' => 'orders.process', 'group_name' => 'orders'],
            ['name' => 'Gérer livraisons', 'slug' => 'deliveries.manage', 'group_name' => 'orders'],

            // Devis
            ['name' => 'Créer devis', 'slug' => 'quotes.create', 'group_name' => 'quotes'],
            ['name' => 'Voir ses devis', 'slug' => 'quotes.view_own', 'group_name' => 'quotes'],

            // Déploiements whitelabel
            ['name' => 'Gérer déploiements', 'slug' => 'deployments.manage', 'group_name' => 'whitelabel'],
            ['name' => 'Gérer domaines', 'slug' => 'domains.manage', 'group_name' => 'whitelabel'],
            ['name' => 'Lier artisans', 'slug' => 'artisans.link', 'group_name' => 'whitelabel'],
            ['name' => 'Voir artisans liés', 'slug' => 'artisans.view', 'group_name' => 'whitelabel'],
            ['name' => 'Créer liens session', 'slug' => 'sessions.create_link', 'group_name' => 'whitelabel'],
            ['name' => 'Gérer branding', 'slug' => 'branding.manage', 'group_name' => 'whitelabel'],

            // Sessions IA (compléments)
            ['name' => 'Créer session', 'slug' => 'ai-sessions.create', 'group_name' => 'ai'],
            ['name' => 'Voir ses sessions', 'slug' => 'ai-sessions.view_own', 'group_name' => 'ai'],
            ['name' => 'Participer session', 'slug' => 'ai-sessions.participate', 'group_name' => 'ai'],

            // Fichiers
            ['name' => 'Uploader fichiers', 'slug' => 'files.upload', 'group_name' => 'files'],

            // Stats
            ['name' => 'Voir statistiques', 'slug' => 'stats.view', 'group_name' => 'stats'],
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['slug' => $perm['slug']], $perm);
        }

        $this->command->info('🔐 ' . count($permissions) . ' permissions marketplace créées');

        // ═══════════════════════════════════════════════════════════════════
        // NOUVEAUX RÔLES MARKETPLACE
        // ═══════════════════════════════════════════════════════════════════

        $roles = [
            [
                'name' => 'Fabricant',
                'slug' => 'fabricant',
                'description' => 'Fabricant de matériaux B2B sur la marketplace (ex: Weber, Porcelanosa)',
                'is_system' => true,
                'permissions' => [
                    'marketplace.access',
                    'catalog.manage',
                    'orders.view',
                    'orders.process',
                    'deliveries.manage',
                    'api.access',
                ],
            ],
            [
                'name' => 'Artisan',
                'slug' => 'artisan',
                'description' => 'Professionnel BTP - Agents IA, devis, commandes matériaux',
                'is_system' => true,
                'permissions' => [
                    'agents.view',
                    'ai-sessions.create',
                    'ai-sessions.view_own',
                    'files.upload',
                    'quotes.create',
                    'quotes.view_own',
                    'orders.create',
                    'orders.view_own',
                    'marketplace.access',
                ],
            ],
            [
                'name' => 'Éditeur',
                'slug' => 'editeur',
                'description' => 'Éditeur logiciel tiers - intégration whitelabel (ex: EBP, SAGE)',
                'is_system' => true,
                'permissions' => [
                    'deployments.manage',
                    'domains.manage',
                    'artisans.link',
                    'artisans.view',
                    'sessions.create_link',
                    'webhooks.manage',
                    'stats.view',
                    'api.access',
                    'branding.manage',
                ],
            ],
            [
                'name' => 'Particulier',
                'slug' => 'particulier',
                'description' => 'Client final demandeur de devis',
                'is_system' => true,
                'permissions' => [
                    'ai-sessions.participate',
                    'files.upload',
                    'quotes.view_own',
                ],
            ],
        ];

        foreach ($roles as $roleData) {
            $permissionSlugs = $roleData['permissions'];
            unset($roleData['permissions']);

            $role = Role::firstOrCreate(['slug' => $roleData['slug']], $roleData);

            // Attacher les permissions
            $permissionIds = Permission::whereIn('slug', $permissionSlugs)->pluck('id');
            $role->permissions()->syncWithoutDetaching($permissionIds);
        }

        $this->command->info('👥 ' . count($roles) . ' rôles marketplace créés');
    }
}

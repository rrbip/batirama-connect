<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Création des permissions
        $permissions = [
            // Agents
            ['name' => 'Voir les agents', 'slug' => 'agents.view', 'group_name' => 'agents'],
            ['name' => 'Créer un agent', 'slug' => 'agents.create', 'group_name' => 'agents'],
            ['name' => 'Modifier un agent', 'slug' => 'agents.update', 'group_name' => 'agents'],
            ['name' => 'Supprimer un agent', 'slug' => 'agents.delete', 'group_name' => 'agents'],

            // Sessions IA
            ['name' => 'Voir les sessions', 'slug' => 'ai-sessions.view', 'group_name' => 'ai'],
            ['name' => 'Valider les réponses', 'slug' => 'ai-sessions.validate', 'group_name' => 'ai'],
            ['name' => 'Déclencher l\'apprentissage', 'slug' => 'ai-sessions.learn', 'group_name' => 'ai'],

            // Ouvrages
            ['name' => 'Voir les ouvrages', 'slug' => 'ouvrages.view', 'group_name' => 'ouvrages'],
            ['name' => 'Créer un ouvrage', 'slug' => 'ouvrages.create', 'group_name' => 'ouvrages'],
            ['name' => 'Modifier un ouvrage', 'slug' => 'ouvrages.update', 'group_name' => 'ouvrages'],
            ['name' => 'Supprimer un ouvrage', 'slug' => 'ouvrages.delete', 'group_name' => 'ouvrages'],
            ['name' => 'Importer des ouvrages', 'slug' => 'ouvrages.import', 'group_name' => 'ouvrages'],
            ['name' => 'Indexer dans Qdrant', 'slug' => 'ouvrages.index', 'group_name' => 'ouvrages'],

            // Documents
            ['name' => 'Voir les documents', 'slug' => 'documents.view', 'group_name' => 'documents'],
            ['name' => 'Télécharger des documents', 'slug' => 'documents.upload', 'group_name' => 'documents'],
            ['name' => 'Supprimer des documents', 'slug' => 'documents.delete', 'group_name' => 'documents'],

            // Utilisateurs
            ['name' => 'Gérer les utilisateurs', 'slug' => 'users.manage', 'group_name' => 'users'],
            ['name' => 'Gérer les rôles', 'slug' => 'roles.manage', 'group_name' => 'users'],

            // Partenaires
            ['name' => 'Gérer les partenaires', 'slug' => 'partners.manage', 'group_name' => 'partners'],

            // API
            ['name' => 'Accès API', 'slug' => 'api.access', 'group_name' => 'api'],
            ['name' => 'Gérer les webhooks', 'slug' => 'webhooks.manage', 'group_name' => 'api'],

            // Support Humain
            ['name' => 'Gérer le support', 'slug' => 'support.manage', 'group_name' => 'support'],
            ['name' => 'Répondre au support', 'slug' => 'support.respond', 'group_name' => 'support'],
            ['name' => 'Voir les sessions support', 'slug' => 'support.view', 'group_name' => 'support'],
            ['name' => 'Former l\'IA', 'slug' => 'support.train', 'group_name' => 'support'],
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['slug' => $perm['slug']], $perm);
        }

        $this->command->info('🔐 ' . count($permissions) . ' permissions créées');

        // Création des rôles
        $roles = [
            [
                'name' => 'Super Admin',
                'slug' => 'super-admin',
                'description' => 'Accès complet au système',
                'is_system' => true,
                'permissions' => ['*'],
            ],
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'Administration des agents et utilisateurs',
                'is_system' => true,
                'permissions' => ['agents.*', 'ai-sessions.*', 'ouvrages.*', 'documents.*', 'users.manage'],
            ],
            [
                'name' => 'Métreur',
                'slug' => 'metreur',
                'description' => 'Validation et correction des devis IA, gestion des ouvrages',
                'is_system' => true,
                'permissions' => ['ouvrages.*', 'ai-sessions.view', 'ai-sessions.validate'],
            ],
            [
                'name' => 'Validateur',
                'slug' => 'validator',
                'description' => 'Validation des réponses IA',
                'is_system' => true,
                'permissions' => ['agents.view', 'ai-sessions.view', 'ai-sessions.validate', 'ai-sessions.learn'],
            ],
            [
                'name' => 'Utilisateur',
                'slug' => 'user',
                'description' => 'Utilisation des agents IA',
                'is_system' => true,
                'permissions' => ['agents.view', 'ai-sessions.view'],
            ],
            [
                'name' => 'API Client',
                'slug' => 'api-client',
                'description' => 'Accès API uniquement (marque blanche)',
                'is_system' => true,
                'permissions' => ['api.access'],
            ],
            [
                'name' => 'Agent de Support',
                'slug' => 'support-agent',
                'description' => 'Gestion du support humain pour les agents IA',
                'is_system' => true,
                'permissions' => ['support.*', 'ai-sessions.view', 'ai-sessions.validate', 'ai-sessions.learn', 'agents.view'],
            ],
        ];

        foreach ($roles as $roleData) {
            $permissionSlugs = $roleData['permissions'];
            unset($roleData['permissions']);

            $role = Role::firstOrCreate(['slug' => $roleData['slug']], $roleData);

            // Attacher les permissions
            if ($permissionSlugs === ['*']) {
                $role->permissions()->sync(Permission::pluck('id'));
            } else {
                $permissionIds = Permission::where(function ($query) use ($permissionSlugs) {
                    foreach ($permissionSlugs as $perm) {
                        if (str_ends_with($perm, '.*')) {
                            $group = str_replace('.*', '', $perm);
                            $query->orWhere('group_name', $group);
                        } else {
                            $query->orWhere('slug', $perm);
                        }
                    }
                })->pluck('id');

                $role->permissions()->sync($permissionIds);
            }
        }

        $this->command->info('👥 ' . count($roles) . ' rôles créés');
    }
}

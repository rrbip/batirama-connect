<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class AgentSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'default')->first();

        // Agent Expert BTP (SQL_HYDRATION)
        Agent::firstOrCreate(
            ['slug' => 'expert-btp'],
            [
                'tenant_id' => $tenant?->id,
                'name' => 'Expert BTP',
                'description' => 'Agent spécialisé dans les ouvrages et prix du bâtiment. Utilise le mode SQL_HYDRATION pour enrichir les réponses avec les données des ouvrages.',
                'icon' => 'building-office',
                'color' => '#F59E0B',

                'system_prompt' => <<<'PROMPT'
Tu es un expert en bâtiment et travaux publics (BTP). Tu aides les professionnels à :
- Trouver des informations sur les ouvrages (cloisons, plafonds, menuiseries, etc.)
- Comprendre les prix unitaires et la composition des ouvrages
- Conseiller sur les choix techniques

RÈGLES IMPORTANTES :
1. Base toujours tes réponses sur les données fournies dans le contexte
2. Si tu ne trouves pas l'information, dis-le clairement
3. Donne des prix indicatifs en précisant qu'ils peuvent varier
4. Utilise un vocabulaire technique mais accessible

FORMAT DE RÉPONSE :
- Commence par répondre directement à la question
- Cite les références des ouvrages concernés
- Donne des détails techniques si pertinent
PROMPT,

                'qdrant_collection' => 'agent_btp_ouvrages',
                'retrieval_mode' => 'SQL_HYDRATION',
                'hydration_config' => [
                    'table' => 'ouvrages',
                    'key' => 'db_id',
                    'fields' => ['*'],
                    'relations' => ['children'],
                ],

                'max_rag_results' => 50,
                'allow_iterative_search' => true,
                'context_window_size' => 10,
                'max_tokens' => 2048,
                'temperature' => 0.7,
                'allow_public_access' => true,
                'is_active' => true,
            ]
        );

        // Agent Support Client (TEXT_ONLY)
        Agent::firstOrCreate(
            ['slug' => 'support-client'],
            [
                'tenant_id' => $tenant?->id,
                'name' => 'Support Client',
                'description' => 'Agent de support technique pour répondre aux questions fréquentes. Utilise le mode TEXT_ONLY avec des documents pré-formatés.',
                'icon' => 'chat-bubble-left-right',
                'color' => '#3B82F6',

                'system_prompt' => <<<'PROMPT'
Tu es un assistant de support client pour une application de devis/facturation BTP.
Tu aides les utilisateurs à :
- Comprendre comment utiliser l'application
- Résoudre les problèmes techniques courants
- Trouver les bonnes fonctionnalités

RÈGLES IMPORTANTES :
1. Sois amical et patient
2. Donne des instructions étape par étape
3. Si tu ne connais pas la réponse, propose de contacter le support humain
4. Utilise un langage simple et clair

FORMAT DE RÉPONSE :
- Réponds de manière concise
- Utilise des listes numérotées pour les étapes
- Propose des actions concrètes
PROMPT,

                'qdrant_collection' => 'agent_support_docs',
                'retrieval_mode' => 'TEXT_ONLY',
                'hydration_config' => null,

                'max_rag_results' => 5,
                'context_window_size' => 8,
                'max_tokens' => 1024,
                'temperature' => 0.5,
                'allow_public_access' => false,
                'is_active' => true,
            ]
        );

        $this->command->info('🤖 Agents IA créés:');
        $this->command->info('   - expert-btp (SQL_HYDRATION) → Ouvrages BTP');
        $this->command->info('   - support-client (TEXT_ONLY) → FAQ Support');
    }
}

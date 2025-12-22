<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Partner;
use Illuminate\Database\Seeder;

class PartnerSeeder extends Seeder
{
    public function run(): void
    {
        // ZOOMBAT - Logiciel interne avec accès complet
        Partner::firstOrCreate(
            ['slug' => 'zoombat'],
            [
                'name' => 'ZOOMBAT',
                'description' => 'Logiciel de devis/facturation BTP interne',
                'api_key' => Partner::generateApiKey('zb_'),
                'api_key_prefix' => 'zb_',
                'webhook_url' => null,
                'default_agent' => 'expert-btp',
                'data_access' => 'full',
                'data_fields' => null,
                'commission_rate' => 5.00,
                'notify_on_session_complete' => true,
                'notify_on_conversion' => true,
                'status' => 'active',
                'contact_email' => 'tech@zoombat.fr',
                'contact_name' => 'Équipe Technique',
            ]
        );

        // EBP - Partenaire externe avec accès résumé
        Partner::firstOrCreate(
            ['slug' => 'ebp'],
            [
                'name' => 'EBP Bâtiment',
                'description' => 'Logiciel de gestion BTP',
                'api_key' => Partner::generateApiKey('ebp_'),
                'api_key_prefix' => 'ebp_',
                'webhook_url' => null,
                'default_agent' => 'expert-btp',
                'data_access' => 'summary',
                'data_fields' => null,
                'commission_rate' => 5.00,
                'notify_on_session_complete' => true,
                'notify_on_conversion' => true,
                'status' => 'active',
                'contact_email' => 'partenaires@ebp.com',
                'contact_name' => 'Service Partenaires',
            ]
        );

        $this->command->info('🤝 Partenaires créés:');
        $this->command->info('   - ZOOMBAT (accès: full)');
        $this->command->info('   - EBP Bâtiment (accès: summary)');
    }
}

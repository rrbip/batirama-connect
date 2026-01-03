<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ConfigurableList;
use Illuminate\Database\Seeder;

class ConfigurableListSeeder extends Seeder
{
    public function run(): void
    {
        $lists = [
            // Modèles Gemini
            [
                'key' => ConfigurableList::KEY_GEMINI_MODELS,
                'name' => 'Modèles Gemini',
                'description' => 'Liste des modèles Google Gemini disponibles pour les agents IA',
                'category' => ConfigurableList::CATEGORY_AI,
                'is_system' => true,
                'data' => ConfigurableList::getDefaultData(ConfigurableList::KEY_GEMINI_MODELS),
            ],

            // Modèles OpenAI
            [
                'key' => ConfigurableList::KEY_OPENAI_MODELS,
                'name' => 'Modèles OpenAI',
                'description' => 'Liste des modèles OpenAI disponibles pour les agents IA',
                'category' => ConfigurableList::CATEGORY_AI,
                'is_system' => true,
                'data' => ConfigurableList::getDefaultData(ConfigurableList::KEY_OPENAI_MODELS),
            ],

            // Raisons de skip (apprentissage accéléré)
            [
                'key' => ConfigurableList::KEY_SKIP_REASONS,
                'name' => 'Raisons de skip',
                'description' => 'Raisons disponibles pour passer une question en mode apprentissage accéléré',
                'category' => ConfigurableList::CATEGORY_AI,
                'is_system' => true,
                'data' => ConfigurableList::getDefaultData(ConfigurableList::KEY_SKIP_REASONS),
            ],
        ];

        foreach ($lists as $listData) {
            ConfigurableList::updateOrCreate(
                ['key' => $listData['key']],
                $listData
            );
        }

        $this->command->info('Configurable lists seeded successfully.');
    }
}

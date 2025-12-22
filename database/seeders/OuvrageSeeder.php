<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Ouvrage;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class OuvrageSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'default')->first();

        $ouvrages = [
            // CLOISONS
            [
                'code' => 'CLO-BA13-001',
                'name' => 'Cloison BA13 simple peau sur ossature 48mm',
                'description' => 'Cloison en plaques de plâtre BA13 simple peau. Ossature métallique 48mm avec montants espacés de 60cm. Épaisseur totale 61mm.',
                'type' => 'simple',
                'category' => 'Cloisons',
                'subcategory' => 'Plaques de plâtre',
                'unit' => 'm²',
                'unit_price' => 28.50,
                'technical_specs' => [
                    'epaisseur_totale' => '61mm',
                    'ossature' => 'M48',
                    'entraxe' => '60cm',
                    'nb_plaques' => 1,
                    'affaiblissement_acoustique' => '34dB',
                ],
            ],
            [
                'code' => 'CLO-BA13-002',
                'name' => 'Cloison BA13 double peau sur ossature 48mm',
                'description' => 'Cloison en plaques de plâtre BA13 double peau. Ossature métallique 48mm. 2 plaques de chaque côté. Épaisseur totale 98mm. Excellent affaiblissement acoustique.',
                'type' => 'simple',
                'category' => 'Cloisons',
                'subcategory' => 'Plaques de plâtre',
                'unit' => 'm²',
                'unit_price' => 45.00,
                'technical_specs' => [
                    'epaisseur_totale' => '98mm',
                    'ossature' => 'M48',
                    'entraxe' => '60cm',
                    'nb_plaques' => 2,
                    'affaiblissement_acoustique' => '42dB',
                ],
            ],
            [
                'code' => 'CLO-BA13-003',
                'name' => 'Cloison BA13 hydrofuge pour pièces humides',
                'description' => 'Cloison en plaques de plâtre hydrofuges (vertes) pour salles de bains et cuisines. Ossature 48mm. Simple peau.',
                'type' => 'simple',
                'category' => 'Cloisons',
                'subcategory' => 'Plaques de plâtre',
                'unit' => 'm²',
                'unit_price' => 35.00,
                'technical_specs' => [
                    'epaisseur_totale' => '61mm',
                    'ossature' => 'M48',
                    'type_plaque' => 'Hydrofuge H1',
                    'usage' => 'Pièces humides',
                ],
            ],

            // PLAFONDS
            [
                'code' => 'PLF-SUSP-001',
                'name' => 'Plafond suspendu BA13 sur ossature primaire/secondaire',
                'description' => 'Plafond suspendu en plaques BA13. Ossature métallique avec fourrures et suspentes. Plénum standard 20cm.',
                'type' => 'simple',
                'category' => 'Plafonds',
                'subcategory' => 'Suspendus',
                'unit' => 'm²',
                'unit_price' => 42.00,
                'technical_specs' => [
                    'plenum' => '20cm',
                    'ossature' => 'F530 + suspentes',
                    'entraxe_fourrures' => '50cm',
                    'entraxe_suspentes' => '120cm',
                ],
            ],
            [
                'code' => 'PLF-SUSP-002',
                'name' => 'Plafond suspendu acoustique avec laine minérale',
                'description' => 'Plafond suspendu BA13 avec isolation acoustique en laine de roche 60mm. Performances acoustiques renforcées.',
                'type' => 'compose',
                'category' => 'Plafonds',
                'subcategory' => 'Suspendus',
                'unit' => 'm²',
                'unit_price' => 58.00,
                'technical_specs' => [
                    'plenum' => '25cm',
                    'isolation' => 'Laine de roche 60mm',
                    'affaiblissement_acoustique' => '45dB',
                ],
            ],

            // MENUISERIES
            [
                'code' => 'MEN-PORTE-001',
                'name' => 'Bloc-porte âme alvéolaire 83x204cm',
                'description' => 'Bloc-porte intérieur standard. Huisserie métallique, porte âme alvéolaire. Serrure bec-de-cane.',
                'type' => 'simple',
                'category' => 'Menuiseries',
                'subcategory' => 'Portes intérieures',
                'unit' => 'U',
                'unit_price' => 185.00,
                'technical_specs' => [
                    'dimensions' => '83x204cm',
                    'huisserie' => 'Métallique',
                    'ame' => 'Alvéolaire',
                    'serrure' => 'Bec-de-cane',
                ],
            ],
            [
                'code' => 'MEN-PORTE-002',
                'name' => 'Bloc-porte acoustique 38dB',
                'description' => 'Bloc-porte acoustique haute performance. Huisserie bois, joint périphérique, seuil automatique.',
                'type' => 'simple',
                'category' => 'Menuiseries',
                'subcategory' => 'Portes intérieures',
                'unit' => 'U',
                'unit_price' => 450.00,
                'technical_specs' => [
                    'dimensions' => '83x204cm',
                    'affaiblissement_acoustique' => '38dB',
                    'huisserie' => 'Bois',
                    'seuil' => 'Automatique',
                ],
            ],

            // ISOLATION
            [
                'code' => 'ISO-LDV-001',
                'name' => 'Isolation laine de verre 100mm R=2.50',
                'description' => 'Panneau de laine de verre semi-rigide pour isolation des murs et cloisons. Résistance thermique R=2.50.',
                'type' => 'simple',
                'category' => 'Isolation',
                'subcategory' => 'Thermique',
                'unit' => 'm²',
                'unit_price' => 12.50,
                'technical_specs' => [
                    'epaisseur' => '100mm',
                    'resistance_thermique' => 'R=2.50',
                    'lambda' => '0.040',
                    'conditionnement' => 'Rouleau',
                ],
            ],
            [
                'code' => 'ISO-LDR-001',
                'name' => 'Isolation laine de roche 60mm acoustique',
                'description' => 'Panneau de laine de roche pour isolation acoustique. Idéal pour cloisons et plafonds.',
                'type' => 'simple',
                'category' => 'Isolation',
                'subcategory' => 'Acoustique',
                'unit' => 'm²',
                'unit_price' => 15.00,
                'technical_specs' => [
                    'epaisseur' => '60mm',
                    'densite' => '40kg/m³',
                    'usage' => 'Acoustique',
                ],
            ],

            // PEINTURE
            [
                'code' => 'PEI-MAT-001',
                'name' => 'Peinture acrylique mate blanche - 2 couches',
                'description' => 'Application de peinture acrylique mate blanche en 2 couches sur murs et plafonds. Impression comprise.',
                'type' => 'simple',
                'category' => 'Peinture',
                'subcategory' => 'Murs et plafonds',
                'unit' => 'm²',
                'unit_price' => 14.00,
                'technical_specs' => [
                    'type' => 'Acrylique mat',
                    'nb_couches' => 2,
                    'impression' => 'Incluse',
                    'rendement' => '10m²/L',
                ],
            ],
        ];

        foreach ($ouvrages as $data) {
            Ouvrage::firstOrCreate(
                ['code' => $data['code']],
                array_merge($data, [
                    'tenant_id' => $tenant?->id,
                    'is_indexed' => false,
                ])
            );
        }

        $this->command->info('🏗️ ' . count($ouvrages) . ' ouvrages BTP créés');
    }
}

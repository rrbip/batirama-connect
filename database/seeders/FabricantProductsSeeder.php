<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FabricantCatalog;
use App\Models\FabricantProduct;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeder pour créer des produits fabricant de test.
 *
 * Crée un fabricant Weber fictif avec un catalogue de produits
 * pour tester le SKU matching et le marketplace.
 */
class FabricantProductsSeeder extends Seeder
{
    public function run(): void
    {
        // Créer ou récupérer le rôle fabricant
        $fabricantRole = Role::where('slug', 'fabricant')->first();
        if (!$fabricantRole) {
            $this->command->warn('Role fabricant non trouvé. Exécutez MarketplaceRolesSeeder d\'abord.');
            return;
        }

        // Créer un fabricant test
        $fabricant = User::firstOrCreate(
            ['email' => 'contact@weber-test.fr'],
            [
                'name' => 'Weber France (Test)',
                'password' => bcrypt('password'),
                'company_name' => 'Weber France',
                'company_info' => [
                    'siret' => '12345678901234',
                    'address' => '1 rue des Mortiers, 75001 Paris',
                    'website' => 'https://www.weber.fr',
                ],
                'marketplace_enabled' => true,
            ]
        );
        $fabricant->roles()->syncWithoutDetaching([$fabricantRole->id]);

        $this->command->info("✅ Fabricant créé: {$fabricant->name}");

        // Créer le catalogue
        $catalog = FabricantCatalog::firstOrCreate(
            ['fabricant_id' => $fabricant->id, 'name' => 'Catalogue Weber 2024'],
            [
                'description' => 'Catalogue complet des produits Weber - mortiers, colles, enduits',
                'website_url' => 'https://www.weber.fr',
                'status' => FabricantCatalog::STATUS_COMPLETED,
                'refresh_frequency' => FabricantCatalog::REFRESH_MONTHLY,
                'extraction_config' => FabricantCatalog::getDefaultExtractionConfig(),
            ]
        );

        $this->command->info("✅ Catalogue créé: {$catalog->name}");

        // Produits Weber réalistes
        $products = [
            // Colles carrelage
            [
                'sku' => 'WEBER-COL-FLEX-25',
                'ean' => '3250971200015',
                'name' => 'weber.col flex - Mortier-colle flexible C2S1EG',
                'short_description' => 'Mortier-colle amélioré déformable pour carrelage sol et mur',
                'description' => 'weber.col flex est un mortier-colle amélioré déformable de classe C2S1EG, idéal pour le collage de tous types de carrelages en intérieur et extérieur. Convient pour les supports soumis à des sollicitations mécaniques ou thermiques.',
                'brand' => 'Weber',
                'category' => 'Colles carrelage',
                'price_ht' => 15.90,
                'price_unit' => 'sac 25kg',
                'availability' => FabricantProduct::AVAILABILITY_IN_STOCK,
                'specifications' => [
                    'Classe' => 'C2S1EG',
                    'Temps ouvert' => '30 min',
                    'Temps de durcissement' => '24h',
                    'Consommation' => '3 à 5 kg/m²',
                    'Couleur' => 'Gris',
                    'Conditionnement' => 'Sac 25 kg',
                ],
            ],
            [
                'sku' => 'WEBER-COL-PLUS-25',
                'ean' => '3250971200022',
                'name' => 'weber.col plus - Mortier-colle amélioré C2T',
                'short_description' => 'Mortier-colle amélioré à temps ouvert allongé',
                'description' => 'weber.col plus est un mortier-colle amélioré de classe C2T avec un temps ouvert allongé, idéal pour les grands formats et les travaux en extérieur.',
                'brand' => 'Weber',
                'category' => 'Colles carrelage',
                'price_ht' => 12.50,
                'price_unit' => 'sac 25kg',
                'availability' => FabricantProduct::AVAILABILITY_IN_STOCK,
                'specifications' => [
                    'Classe' => 'C2T',
                    'Temps ouvert' => '45 min',
                    'Consommation' => '3 à 5 kg/m²',
                    'Couleur' => 'Gris ou Blanc',
                ],
            ],
            // Enduits
            [
                'sku' => 'WEBER-REP-FIN-25',
                'ean' => '3250971300012',
                'name' => 'weber.rep fin - Enduit de réparation fin',
                'short_description' => 'Enduit de réparation à grain fin pour finitions',
                'description' => 'weber.rep fin est un enduit de réparation à grain fin (0-1mm) pour rebouchage et finition des supports béton, mortier et enduit.',
                'brand' => 'Weber',
                'category' => 'Enduits de réparation',
                'price_ht' => 18.90,
                'price_unit' => 'sac 25kg',
                'availability' => FabricantProduct::AVAILABILITY_IN_STOCK,
                'specifications' => [
                    'Granulométrie' => '0-1 mm',
                    'Épaisseur max' => '10 mm',
                    'Consommation' => '1.5 kg/m²/mm',
                ],
            ],
            [
                'sku' => 'WEBER-PRAL-F-30',
                'ean' => '3250971400019',
                'name' => 'weber.pral F - Enduit monocouche d\'imperméabilisation',
                'short_description' => 'Enduit monocouche OC3 pour façade',
                'description' => 'weber.pral F est un enduit monocouche d\'imperméabilisation et de décoration OC3 pour façades. Application manuelle ou mécanique.',
                'brand' => 'Weber',
                'category' => 'Enduits façade',
                'price_ht' => 14.50,
                'price_unit' => 'sac 30kg',
                'availability' => FabricantProduct::AVAILABILITY_IN_STOCK,
                'specifications' => [
                    'Classe' => 'OC3',
                    'Épaisseur' => '10 à 25 mm',
                    'Consommation' => '15 à 20 kg/m²',
                    'Finitions' => 'Gratté, taloché, ribbé',
                ],
            ],
            // Primaires
            [
                'sku' => 'WEBER-PRIM-AD-5',
                'ean' => '3250971500016',
                'name' => 'weber.prim AD - Primaire d\'accrochage',
                'short_description' => 'Primaire d\'accrochage universel',
                'description' => 'weber.prim AD est un primaire d\'accrochage universel prêt à l\'emploi pour améliorer l\'adhérence des mortiers colles et enduits sur supports lisses ou peu absorbants.',
                'brand' => 'Weber',
                'category' => 'Primaires',
                'price_ht' => 29.90,
                'price_unit' => 'bidon 5L',
                'availability' => FabricantProduct::AVAILABILITY_IN_STOCK,
                'specifications' => [
                    'Consommation' => '150 à 200 g/m²',
                    'Séchage' => '2 à 4h',
                    'Dilution' => 'Prêt à l\'emploi',
                ],
            ],
            // Joints
            [
                'sku' => 'WEBER-JOINT-FIN-5',
                'ean' => '3250971600013',
                'name' => 'weber.joint fin - Mortier pour joints fins',
                'short_description' => 'Mortier pour joints de 1 à 6 mm',
                'description' => 'weber.joint fin est un mortier pour joints fins de carrelage de 1 à 6 mm, intérieur et extérieur. Disponible en plusieurs coloris.',
                'brand' => 'Weber',
                'category' => 'Joints carrelage',
                'price_ht' => 8.90,
                'price_unit' => 'sac 5kg',
                'availability' => FabricantProduct::AVAILABILITY_IN_STOCK,
                'specifications' => [
                    'Largeur joints' => '1 à 6 mm',
                    'Classe' => 'CG2 WA',
                    'Coloris' => 'Blanc, Gris, Anthracite, Beige',
                ],
            ],
            // Ragréage
            [
                'sku' => 'WEBER-NIV-DUR-25',
                'ean' => '3250971700010',
                'name' => 'weber.niv dur - Ragréage autolissant P3',
                'short_description' => 'Ragréage autolissant fibré haute performance',
                'description' => 'weber.niv dur est un ragréage autolissant fibré P3 pour sols intérieurs. Permet de rattraper des différences de niveau de 3 à 30 mm.',
                'brand' => 'Weber',
                'category' => 'Ragréages',
                'price_ht' => 22.90,
                'price_unit' => 'sac 25kg',
                'availability' => FabricantProduct::AVAILABILITY_IN_STOCK,
                'specifications' => [
                    'Classe' => 'P3',
                    'Épaisseur' => '3 à 30 mm',
                    'Délai de recouvrement' => '24h',
                    'Consommation' => '1.5 kg/m²/mm',
                ],
            ],
            // Produit en rupture pour tester
            [
                'sku' => 'WEBER-SYS-PROTECT-20',
                'ean' => '3250971800017',
                'name' => 'weber.sys protect - Système d\'étanchéité liquide',
                'short_description' => 'Système d\'étanchéité sous carrelage (SPEC)',
                'description' => 'weber.sys protect est un système d\'étanchéité liquide sous carrelage pour pièces humides. Certifié SPEC.',
                'brand' => 'Weber',
                'category' => 'Étanchéité',
                'price_ht' => 89.90,
                'price_unit' => 'kit 20m²',
                'availability' => FabricantProduct::AVAILABILITY_OUT_OF_STOCK,
                'lead_time' => '2-3 semaines',
                'specifications' => [
                    'Certification' => 'SPEC',
                    'Surface' => '20 m²',
                    'Composition' => 'Primaire + membrane + bandes',
                ],
            ],
        ];

        $created = 0;
        foreach ($products as $productData) {
            $specs = $productData['specifications'] ?? [];
            unset($productData['specifications']);

            FabricantProduct::firstOrCreate(
                ['catalog_id' => $catalog->id, 'sku' => $productData['sku']],
                array_merge($productData, [
                    'specifications' => $specs,
                    'status' => FabricantProduct::STATUS_ACTIVE,
                    'is_verified' => true,
                    'verified_at' => now(),
                    'marketplace_visible' => true,
                    'extraction_method' => FabricantProduct::EXTRACTION_MANUAL,
                    'extraction_confidence' => 1.0,
                ])
            );
            $created++;
        }

        $catalog->update([
            'products_found' => $created,
            'last_extraction_at' => now(),
        ]);

        $this->command->info("✅ {$created} produits créés dans le catalogue");
        $this->command->newLine();
        $this->command->info('🎉 Seeding terminé ! Vous pouvez maintenant tester le SKU matching.');
    }
}

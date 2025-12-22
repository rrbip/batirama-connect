<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class SupportDocSeeder extends Seeder
{
    public function run(): void
    {
        $docs = $this->getSupportDocuments();

        // Stocker dans un fichier JSON pour la commande qdrant:init
        $path = storage_path('app/seed-data/support-docs.json');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode($docs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->command->info('📚 ' . count($docs) . ' documents support préparés pour indexation');
    }

    private function getSupportDocuments(): array
    {
        return [
            [
                'slug' => 'creer-devis',
                'title' => 'Comment créer un devis ?',
                'content' => "Pour créer un nouveau devis, suivez ces étapes :\n\n1. Cliquez sur le menu 'Devis' dans la barre latérale\n2. Cliquez sur le bouton 'Nouveau devis'\n3. Sélectionnez ou créez un client\n4. Ajoutez les ouvrages depuis la bibliothèque en utilisant la recherche\n5. Ajustez les quantités pour chaque ligne\n6. Vérifiez le total et les remises éventuelles\n7. Cliquez sur 'Enregistrer' ou 'Envoyer au client'\n\nLe devis sera automatiquement numéroté selon votre paramétrage.",
                'category' => 'devis',
            ],
            [
                'slug' => 'modifier-devis',
                'title' => 'Comment modifier un devis existant ?',
                'content' => "Pour modifier un devis existant :\n\n1. Allez dans 'Devis' > 'Liste des devis'\n2. Recherchez le devis par numéro ou client\n3. Cliquez sur le devis pour l'ouvrir\n4. Cliquez sur 'Modifier'\n5. Effectuez vos modifications\n6. Enregistrez\n\nNote : Un devis déjà accepté ne peut plus être modifié. Vous devez créer un avenant.",
                'category' => 'devis',
            ],
            [
                'slug' => 'transformer-devis-facture',
                'title' => 'Comment transformer un devis en facture ?',
                'content' => "Une fois le devis accepté par le client, vous pouvez le transformer en facture :\n\n1. Ouvrez le devis accepté\n2. Cliquez sur 'Actions' > 'Transformer en facture'\n3. Choisissez si vous facturez la totalité ou une partie (situation)\n4. Vérifiez les informations\n5. Validez la création de la facture\n\nLa facture sera liée au devis d'origine pour la traçabilité.",
                'category' => 'facturation',
            ],
            [
                'slug' => 'ajouter-ouvrage-bibliotheque',
                'title' => 'Comment ajouter un ouvrage à la bibliothèque ?',
                'content' => "Pour enrichir votre bibliothèque d'ouvrages :\n\n1. Allez dans 'Bibliothèque' > 'Ouvrages'\n2. Cliquez sur 'Nouvel ouvrage'\n3. Renseignez :\n   - Code de l'ouvrage\n   - Désignation\n   - Unité (m², ml, U, etc.)\n   - Prix unitaire HT\n   - Description technique (optionnel)\n4. Choisissez la catégorie\n5. Enregistrez\n\nL'ouvrage sera disponible dans tous vos devis.",
                'category' => 'bibliotheque',
            ],
            [
                'slug' => 'importer-ouvrages',
                'title' => 'Comment importer des ouvrages depuis un fichier ?',
                'content' => "Pour importer en masse des ouvrages :\n\n1. Préparez votre fichier Excel ou CSV avec les colonnes : Code, Nom, Unité, Prix\n2. Allez dans 'Bibliothèque' > 'Import'\n3. Téléchargez le modèle de fichier si besoin\n4. Sélectionnez votre fichier\n5. Mappez les colonnes si nécessaire\n6. Lancez l'import\n\nUn rapport d'import vous indiquera les succès et erreurs éventuelles.",
                'category' => 'bibliotheque',
            ],
            [
                'slug' => 'gerer-clients',
                'title' => 'Comment gérer les fiches clients ?',
                'content' => "Pour gérer vos clients :\n\n1. Menu 'Clients' > 'Liste des clients'\n2. Pour ajouter : cliquez sur 'Nouveau client'\n3. Renseignez les informations :\n   - Raison sociale ou nom\n   - Adresse complète\n   - Email et téléphone\n   - SIRET (si professionnel)\n4. Enregistrez\n\nVous pouvez voir l'historique des devis et factures depuis la fiche client.",
                'category' => 'clients',
            ],
            [
                'slug' => 'exporter-comptabilite',
                'title' => 'Comment exporter les données pour la comptabilité ?',
                'content' => "Pour exporter vos écritures comptables :\n\n1. Allez dans 'Paramètres' > 'Exports comptables'\n2. Sélectionnez la période (mois, trimestre, année)\n3. Choisissez le format d'export selon votre logiciel :\n   - FEC (Fichier des Écritures Comptables)\n   - CSV standard\n   - Format spécifique (Sage, EBP, etc.)\n4. Cliquez sur 'Exporter'\n\nLe fichier sera téléchargé automatiquement.",
                'category' => 'comptabilite',
            ],
            [
                'slug' => 'probleme-connexion',
                'title' => 'Je n\'arrive pas à me connecter',
                'content' => "Si vous rencontrez des difficultés de connexion :\n\n1. Vérifiez votre adresse email (attention aux fautes de frappe)\n2. Cliquez sur 'Mot de passe oublié' pour réinitialiser\n3. Vérifiez que les majuscules ne sont pas activées\n4. Videz le cache de votre navigateur\n5. Essayez un autre navigateur (Chrome, Firefox, Edge)\n\nSi le problème persiste, contactez le support avec :\n- Votre adresse email\n- Une capture d'écran de l'erreur\n- Le navigateur utilisé",
                'category' => 'technique',
            ],
            [
                'slug' => 'personnaliser-modele-pdf',
                'title' => 'Comment personnaliser les modèles PDF ?',
                'content' => "Pour personnaliser vos documents PDF (devis, factures) :\n\n1. Allez dans 'Paramètres' > 'Modèles de documents'\n2. Sélectionnez le type de document à personnaliser\n3. Vous pouvez modifier :\n   - Le logo (formats PNG, JPG)\n   - Les couleurs de l'entête\n   - Les mentions légales\n   - Le pied de page\n   - La mise en page des lignes\n4. Prévisualisez avant d'enregistrer\n\nLes modifications s'appliqueront aux nouveaux documents.",
                'category' => 'parametrage',
            ],
            [
                'slug' => 'situation-travaux',
                'title' => 'Comment faire une situation de travaux ?',
                'content' => "Pour créer une situation de travaux (facturation partielle) :\n\n1. Ouvrez le devis concerné\n2. Cliquez sur 'Actions' > 'Nouvelle situation'\n3. Pour chaque ligne, indiquez le pourcentage ou montant réalisé\n4. Le système calcule automatiquement :\n   - Le montant de la situation\n   - Le cumul des situations précédentes\n   - Le reste à facturer\n5. Validez pour créer la facture de situation\n\nVous pouvez faire autant de situations que nécessaire jusqu'à atteindre 100%.",
                'category' => 'facturation',
            ],
        ];
    }
}

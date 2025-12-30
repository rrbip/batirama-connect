<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Whitelabel\StandaloneChatController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'AI-Manager CMS',
        'version' => '1.0.0',
        'status' => 'running',
        'api' => [
            'health' => '/api/health',
            'documentation' => '/api/docs',
        ],
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Standalone chat pages (whitelabel session links)
Route::get('/s/{token}', [StandaloneChatController::class, 'show'])
    ->name('whitelabel.standalone');

// Public chat access (legacy token)
Route::get('/c/{token}', [StandaloneChatController::class, 'show'])
    ->name('public.chat');

// Test documents for pipeline testing (public access)
Route::get('/test-docs/{filename}', function (string $filename) {
    $path = storage_path("app/test-documents/{$filename}");

    // If file exists, serve it
    if (file_exists($path)) {
        $mimeType = mime_content_type($path) ?: 'text/html';
        return response()->file($path, ['Content-Type' => $mimeType]);
    }

    // Built-in test document for pipeline testing
    if ($filename === 'test-batiment.html') {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Guide des Travaux de Rénovation - Document Test</title>
</head>
<body>
    <h1>Guide Pratique des Travaux de Rénovation</h1>
    <p>Ce guide présente les principales étapes et techniques pour réussir vos travaux de rénovation.</p>

    <h2>1. Préparation du Chantier</h2>
    <h3>1.1 Diagnostic Initial</h3>
    <p>Avant de commencer tout travaux, il est essentiel de réaliser un diagnostic complet du bâtiment. Ce diagnostic doit inclure :</p>
    <ul>
        <li>L'état de la structure porteuse (murs, planchers, charpente)</li>
        <li>L'état des réseaux (électricité, plomberie, chauffage)</li>
        <li>La présence éventuelle d'amiante ou de plomb</li>
        <li>L'isolation thermique et acoustique existante</li>
    </ul>

    <h3>1.2 Autorisations Administratives</h3>
    <p>Selon l'ampleur des travaux, différentes autorisations peuvent être nécessaires :</p>
    <ul>
        <li><strong>Déclaration préalable</strong> : pour les modifications de façade, changement de fenêtres</li>
        <li><strong>Permis de construire</strong> : pour les extensions de plus de 20m²</li>
        <li><strong>Permis de démolir</strong> : si destruction partielle ou totale</li>
    </ul>

    <h2>2. Travaux de Gros Œuvre</h2>
    <h3>2.1 Maçonnerie</h3>
    <p>Les travaux de maçonnerie concernent la structure du bâtiment. Les techniques courantes incluent :</p>
    <ul>
        <li><strong>Ouverture de mur porteur</strong> : nécessite la pose d'un IPN ou poutre béton armé</li>
        <li><strong>Création de cloisons</strong> : en briques, parpaings, ou plaques de plâtre</li>
        <li><strong>Reprise en sous-œuvre</strong> : renforcement des fondations</li>
    </ul>

    <h3>2.2 Charpente et Toiture</h3>
    <p>La toiture est cruciale pour l'étanchéité. Les interventions courantes sont :</p>
    <ul>
        <li>Remplacement des tuiles ou ardoises endommagées</li>
        <li>Traitement charpente contre insectes xylophages</li>
        <li>Installation de fenêtres de toit (Velux)</li>
    </ul>

    <h2>3. Second Œuvre</h2>
    <h3>3.1 Électricité</h3>
    <p>La mise aux normes électriques est obligatoire pour les installations vétustes. Points clés :</p>
    <ul>
        <li><strong>Tableau électrique</strong> : disjoncteur général, disjoncteurs divisionnaires, différentiels 30mA</li>
        <li><strong>Prises de terre</strong> : obligatoires dans toutes les pièces</li>
        <li><strong>Circuits spécialisés</strong> : pour appareils forte puissance (four, plaque, lave-linge)</li>
    </ul>
    <p>La norme NF C 15-100 définit les règles d'installation électrique dans les logements.</p>

    <h3>3.2 Plomberie</h3>
    <p>Alimentation en eau et évacuation des eaux usées :</p>
    <ul>
        <li><strong>Alimentation</strong> : tuyaux cuivre, PER ou multicouche. 12mm pour points d'eau, 16mm général</li>
        <li><strong>Évacuation</strong> : PVC. 32mm lavabos, 40mm douches, 100mm WC</li>
        <li><strong>Chauffe-eau</strong> : électrique ou thermodynamique</li>
    </ul>

    <h3>3.3 Isolation Thermique</h3>
    <p>L'isolation est primordiale pour le confort et les économies d'énergie :</p>
    <ul>
        <li><strong>ITI (par l'intérieur)</strong> : laine de verre/roche, épaisseur 12-16 cm (R ≥ 3.7)</li>
        <li><strong>ITE (par l'extérieur)</strong> : plus efficace, épaisseur 14-20 cm</li>
        <li><strong>Combles</strong> : priorité absolue, 30% des déperditions. R ≥ 7 recommandé</li>
    </ul>

    <h2>4. Finitions</h2>
    <h3>4.1 Revêtements de Sol</h3>
    <ul>
        <li><strong>Carrelage</strong> : pièces humides. Classement UPEC : U3 P2 E2 C2 minimum</li>
        <li><strong>Parquet</strong> : bois massif ou contrecollé, épaisseur min 10mm</li>
        <li><strong>Sol souple</strong> : vinyle ou linoléum, économique</li>
    </ul>

    <h3>4.2 Peinture</h3>
    <ol>
        <li>Préparation : rebouchage fissures, ponçage</li>
        <li>Sous-couche d'accroche</li>
        <li>Deux couches de finition minimum</li>
    </ol>

    <h2>5. Budget et Aides</h2>
    <h3>5.1 Estimation des Coûts</h3>
    <ul>
        <li>Rénovation légère : 200 à 400 €/m²</li>
        <li>Rénovation moyenne : 400 à 800 €/m²</li>
        <li>Rénovation lourde : 800 à 1500 €/m²</li>
    </ul>

    <h3>5.2 Aides Disponibles</h3>
    <ul>
        <li><strong>MaPrimeRénov'</strong> : isolation, chauffage, ventilation</li>
        <li><strong>Éco-PTZ</strong> : prêt taux zéro jusqu'à 50 000€</li>
        <li><strong>CEE</strong> : Certificats d'Économie d'Énergie</li>
        <li><strong>TVA réduite</strong> : 5.5% travaux énergétiques, 10% autres rénovations</li>
    </ul>
</body>
</html>
HTML;
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    abort(404, 'Document de test non trouvé');
})->where('filename', '[a-zA-Z0-9_\-\.]+')->name('test-docs');

// Admin routes for document management
Route::middleware(['web', 'auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
        ->name('documents.download');
    Route::get('/documents/{document}/view', [DocumentController::class, 'view'])
        ->name('documents.view');
});

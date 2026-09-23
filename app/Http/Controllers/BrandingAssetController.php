<?php

namespace App\Http\Controllers;

use App\Services\Settings;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sert le logo et l'icône envoyés dans les réglages, stockés dans le dossier
 * privé. Ils ne sont pas confidentiels (ils apparaissent sur la page de
 * connexion et chez les clients), mais passer par ici évite d'exposer le
 * dossier de stockage.
 */
class BrandingAssetController extends Controller
{
    public const KINDS = ['logo' => 'logo', 'icone' => 'icon'];

    public function show(Settings $settings, string $kind): Response
    {
        $path = $settings->get('branding.'.self::KINDS[$kind].'_path');

        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}

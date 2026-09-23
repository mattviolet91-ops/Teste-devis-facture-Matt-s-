<?php

namespace App\Http\Controllers;

use App\Services\Settings;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sert le logo stocké dans le dossier privé. Le logo n'est pas confidentiel
 * (il apparaît sur la page de connexion et chez les clients), mais passer par
 * ici évite d'exposer le dossier de stockage.
 */
class BrandingAssetController extends Controller
{
    public function logo(Settings $settings): Response
    {
        $path = $settings->get('branding.logo_path');

        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}

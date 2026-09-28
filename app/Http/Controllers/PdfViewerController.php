<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Visionneuse PDF intégrée : le PDF s'affiche dans l'application avec une barre
 * « ✕ Fermer / Partager », même quand l'application est installée sur l'écran
 * d'accueil (où le navigateur n'affiche aucun bouton retour).
 */
class PdfViewerController extends Controller
{
    public function __invoke(Request $request): View
    {
        $src = $request->query('src');
        abort_unless(self::isInternal($src), 404);

        // Seulement un fichier de l'application que ce compte a le droit d'ouvrir.
        try {
            $route = app('router')->getRoutes()->match(Request::create((string) $src));
        } catch (Throwable) {
            abort(404);
        }
        abort_unless($request->user()->canOpen((string) $route->getName()), 403);

        $back = $request->query('retour');
        $title = Str::limit(trim((string) $request->query('titre', 'Document')) ?: 'Document', 80, '');

        return view('pdf.viewer', [
            'src' => $src,
            'title' => $title,
            'back' => self::isInternal($back) ? $back : route('dashboard', absolute: false),
            'filename' => Str::of($title)->ascii()->replaceMatches('/[^A-Za-z0-9 ._-]/', '')->squish()->value().'.pdf',
        ]);
    }

    /** Adresse interne à l'application (« /devis/12/pdf »), jamais un autre site. */
    public static function isInternal(mixed $url): bool
    {
        return is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//')
            && ! str_contains($url, '\\') && strlen($url) < 500;
    }

    /** Lien vers la visionneuse pour un PDF de l'application. */
    public static function link(string $pdfUrl, string $title): string
    {
        $path = parse_url($pdfUrl, PHP_URL_PATH) ?: '/';
        $query = parse_url($pdfUrl, PHP_URL_QUERY);
        $current = parse_url(url()->full(), PHP_URL_PATH).(($q = parse_url(url()->full(), PHP_URL_QUERY)) ? '?'.$q : '');

        return route('pdf.view', ['src' => $path.($query ? '?'.$query : ''), 'titre' => $title, 'retour' => $current]);
    }
}

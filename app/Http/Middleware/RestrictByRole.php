<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Droits selon le compte :
 * - gérant (admin) : tout ;
 * - commercial : prospects, clients, demandes, rendez-vous, devis, photos —
 *   jamais les factures, paiements, chiffre d'affaires ni réglages de l'entreprise ;
 * - compte désactivé (disabled_at) : déconnecté.
 */
class RestrictByRole
{
    /** Pages ouvertes au commercial (noms de routes). */
    public const COMMERCIAL_ALLOWED = [
        'dashboard', 'search', 'logout', 'documents', 'pdf.view', 'branding.image',
        'clients.*', 'worksites.*', 'quotes.*', 'planning.*', 'requests.*', 'photos.*', 'attachments.*',
        'maintenance.*', 'reports.*', 'emails.*', 'catalog.index', 'push.*', 'offline.*',
        'settings.account', 'settings.account.*',
    ];

    /** Exceptions à l'intérieur des pages ouvertes. */
    public const COMMERCIAL_DENIED = ['quotes.invoice', 'emails.index', 'emails.show', 'clients.import', 'clients.import.preview', 'clients.import.store'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if ($user->disabled_at !== null) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Ce compte a été désactivé.']);
        }

        if ($user->role === 'commercial' && ! self::allows($user, (string) $request->route()?->getName())) {
            abort(403, 'Cette page est réservée au gérant.');
        }

        return $next($request);
    }

    /** Le compte peut-il ouvrir cette page ? (sert aussi à masquer les menus) */
    public static function allows(?User $user, string $routeName): bool
    {
        if (! $user || $user->role !== 'commercial') {
            return true;
        }
        if ($routeName === '' || str_starts_with($routeName, 'portal.')) {
            return $routeName !== '';
        }

        return ! self::matches($routeName, self::COMMERCIAL_DENIED) && self::matches($routeName, self::COMMERCIAL_ALLOWED);
    }

    /** @param  list<string>  $patterns */
    private static function matches(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $name || (str_ends_with($pattern, '.*') && str_starts_with($name, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }
}

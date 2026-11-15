<?php

namespace App\Http\Middleware;

use App\Models\Attachment;
use App\Models\Intervention;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Droits selon le compte :
 * - gérant (admin) : tout ;
 * - commercial : prospects, clients, demandes, son agenda, ses devis et ses factures, photos —
 *   jamais les paiements, le chiffre d'affaires, les avoirs ni les réglages de l'entreprise ;
 * - compte désactivé (disabled_at) : déconnecté.
 */
class RestrictByRole
{
    /** Pages ouvertes au commercial (noms de routes). */
    public const COMMERCIAL_ALLOWED = [
        'dashboard', 'search', 'guide', 'logout', 'documents', 'pdf.view', 'branding.image',
        'clients.*', 'worksites.*', 'quotes.*', 'invoices.*', 'planning.*', 'requests.*', 'photos.*', 'attachments.*',
        'maintenance.*', 'reports.*', 'emails.*', 'catalog.index', 'push.*', 'offline.*',
        'settings.account', 'settings.account.*',
    ];

    /** Exceptions à l'intérieur des pages ouvertes. */
    public const COMMERCIAL_DENIED = ['invoices.cancel', 'invoices.correct', 'emails.index', 'emails.show', 'clients.import', 'clients.import.preview', 'clients.import.store'];

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

        // Commercial : seulement ses devis, ses factures et son agenda (adresse ouverte directement comprise).
        if ($user->role === 'commercial') {
            foreach ((array) $request->route()?->parameters() as $value) {
                $item = match (true) {
                    $value instanceof Quote, $value instanceof Invoice, $value instanceof Intervention => $value,
                    $value instanceof Attachment => $value->quote ?? $value->invoice,
                    default => null,
                };
                if ($item && ! $user->canSee($item)) {
                    abort(403, 'Ce document ou ce rendez-vous est réservé à la personne qui l\'a créé.');
                }
            }
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

<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Mail\ClientMessage;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\MailSettings;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** Comptes d'accès : le gérant crée les comptes commerciaux (invitation par email). */
class UserController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        return view('settings.users', [
            'users' => User::query()->orderByRaw("CASE role WHEN 'admin' THEN 0 WHEN 'commercial' THEN 1 ELSE 2 END")->orderBy('name')->get(),
            'temporaryPassword' => session('temporary_password'),
        ]);
    }

    public function store(Request $request, MailSettings $mail): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
        ], ['email.unique' => 'Un compte existe déjà avec cette adresse.'], ['name' => 'nom', 'role' => 'type de compte']);

        // Mot de passe provisoire aléatoire : le commercial choisit le sien avec le lien d'invitation.
        $temporary = Str::password(14, symbols: false);
        $user = User::query()->create($data + ['password' => $temporary]);
        ActivityLogger::log('user.created', "Compte créé : {$user->name} (".(User::ROLES[$user->role] ?? $user->role).')', $user);

        if ($this->invite($user, $mail)) {
            return redirect()->route('settings.users')->with('status', "Compte créé. {$user->name} a reçu un email pour choisir son mot de passe.");
        }

        // Sans email : le mot de passe provisoire est affiché une seule fois au gérant.
        return redirect()->route('settings.users')->with('status', "Compte créé. L'email d'invitation n'a pas pu partir : donnez ce mot de passe provisoire à {$user->name}.")
            ->with('temporary_password', ['name' => $user->name, 'email' => $user->email, 'password' => $temporary]);
    }

    public function invite(User $user, MailSettings $mail): bool
    {
        if (! $mail->isConfigured()) {
            return false;
        }

        $token = Password::broker()->createToken($user);
        $url = route('password.reset', ['token' => $token, 'email' => $user->email]);
        $company = app(Settings::class)->get('company.trade_name');

        try {
            $mail->apply();
            Mail::to($user->email)->send(new ClientMessage(
                "Votre accès à l'application $company",
                "Bonjour {$user->name},\n\nUn accès à l'application de gestion de $company vient d'être créé pour vous.\n\n"
                ."Choisissez votre mot de passe avec le bouton ci-dessous (lien valable 60 minutes), puis connectez-vous avec votre adresse email.\n\n"
                ."Astuce : ouvrez ensuite l'application sur votre téléphone et ajoutez-la à l'écran d'accueil.",
                buttonUrl: $url,
                buttonLabel: 'Choisir mon mot de passe',
            ));
        } catch (Throwable $e) {
            Log::warning('Invitation non envoyée', ['error' => $e->getMessage()]);

            return false;
        }

        return true;
    }

    public function resend(Request $request, User $user, MailSettings $mail): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return $this->invite($user, $mail)
            ? back()->with('status', "Nouvel email envoyé à {$user->email}.")
            : back()->withErrors(['email' => 'L\'email n\'a pas pu partir : vérifiez Réglages → Emails.']);
    }

    /** Désactiver / réactiver (l'historique du compte est conservé). */
    public function toggle(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_if($user->is($request->user()), 403, 'Vous ne pouvez pas désactiver votre propre compte.');

        $disable = $user->disabled_at === null;
        $user->forceFill(['disabled_at' => $disable ? now() : null])->save();
        if ($disable) {
            // Déconnexion immédiate de tous ses appareils.
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->forceFill(['remember_token' => Str::random(60)])->save();
        }
        ActivityLogger::log('user.'.($disable ? 'disabled' : 'enabled'), "Compte {$user->name} ".($disable ? 'désactivé' : 'réactivé'), $user);

        return back()->with('status', $disable ? "Compte de {$user->name} désactivé : il est déconnecté partout." : "Compte de {$user->name} réactivé.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_if($user->is($request->user()) || $user->isAdmin(), 403, 'Le compte du gérant ne peut pas être supprimé.');

        DB::table('sessions')->where('user_id', $user->id)->delete();
        $name = $user->name;
        $user->delete();
        ActivityLogger::log('user.deleted', "Compte supprimé : $name");

        return back()->with('status', "Compte de $name supprimé. Les devis et rendez-vous qu'il a créés sont conservés.");
    }
}

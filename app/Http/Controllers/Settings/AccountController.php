<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PushSubscription;
use App\Services\ActivityLogger;
use App\Services\PushService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function edit(Request $request, PushService $push): View
    {
        return view('settings.account', [
            'user' => $request->user(),
            'pushKey' => $push->publicKey(),
            'devices' => PushSubscription::query()->latest('updated_at')->get(),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:120', Rule::unique('users')->ignore($user)],
        ]);

        $user->update($data);
        ActivityLogger::log('account.profile', 'Profil modifié', $user);

        return back()->with('status', 'Profil enregistré.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        $user = $request->user();
        $user->update(['password' => $request->input('password')]);

        // Déconnecte les autres appareils ayant une session ouverte.
        Auth::logoutOtherDevices($request->input('password'));
        ActivityLogger::log('account.password', 'Mot de passe modifié', $user);

        return back()->with('status', 'Mot de passe modifié. Les autres appareils ont été déconnectés.');
    }

    /** Déconnecte tous les autres appareils (téléphone perdu, ordinateur partagé…). */
    public function logoutOthers(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']], [
            'current_password.current_password' => 'Mot de passe incorrect.',
        ]);

        Auth::logoutOtherDevices($request->input('current_password'));
        ActivityLogger::log('account.logout_others', 'Autres appareils déconnectés', $request->user());

        return back()->with('status', 'Tous vos autres appareils ont été déconnectés.');
    }

    /** Journal d'activité : qui a fait quoi, quand et depuis quelle adresse. */
    public function journal(Request $request): View
    {
        $logs = ActivityLog::query()
            ->when($request->query('q'), fn ($q, $term) => $q->where('description', 'like', '%'.$term.'%'))
            ->when($request->boolean('connexions'), fn ($q) => $q->where('action', 'like', 'auth.%'))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('settings.journal', compact('logs'));
    }
}

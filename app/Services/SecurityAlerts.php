<?php

namespace App\Services;

use App\Mail\ClientMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Alertes de sécurité : connexion depuis un appareil inconnu. */
class SecurityAlerts
{
    public function __construct(private readonly PushService $push, private readonly MailSettings $mail) {}

    public function newDevice(User $user, Request $request): void
    {
        $device = $this->describe((string) $request->userAgent());
        $text = 'Nouvelle connexion à votre espace de gestion depuis '.$device.' (adresse IP '.$request->ip().') le '.now()->format('d/m/Y à H:i').'. '
            .'Si ce n\'est pas vous, changez immédiatement votre mot de passe dans Réglages → Mon compte et déconnectez les autres appareils.';

        $this->push->send('Nouvelle connexion', $text, route('settings.account'));

        if (! $this->mail->isConfigured()) {
            return;
        }

        try {
            Mail::to($user->email)->send(new ClientMessage('Nouvelle connexion à votre compte', "Bonjour,\n\n$text", buttonUrl: route('settings.account'), buttonLabel: 'Vérifier mon compte'));
        } catch (Throwable $e) {
            Log::warning('Alerte de connexion non envoyée', ['error' => $e->getMessage()]);
        }
    }

    /** « iPhone (Safari) », « Windows (Chrome) »… */
    private function describe(string $agent): string
    {
        $system = match (true) {
            str_contains($agent, 'iPhone') => 'un iPhone',
            str_contains($agent, 'iPad') => 'un iPad',
            str_contains($agent, 'Android') => 'un téléphone Android',
            str_contains($agent, 'Windows') => 'un ordinateur Windows',
            str_contains($agent, 'Macintosh') => 'un Mac',
            str_contains($agent, 'Linux') => 'un ordinateur Linux',
            default => 'un appareil inconnu',
        };
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Chrome') || str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari') => 'Safari',
            default => null,
        };

        return $system.($browser ? " ($browser)" : '');
    }
}

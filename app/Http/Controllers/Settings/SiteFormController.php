<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\SiteEmail;
use App\Services\MailSettings;
use App\Services\Settings;
use App\Services\SiteFormImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/** Réglages de la lecture des emails du formulaire du site. */
class SiteFormController extends Controller
{
    public function edit(Settings $settings, MailSettings $mail): View
    {
        return view('settings.site-form', [
            'form' => $settings->group('site_form'),
            'mailConfigured' => $mail->isConfigured(),
            'imported' => SiteEmail::query()->whereNotNull('quote_request_id')->count(),
            'preview' => session('preview'),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:120'],
        ]);
        $enabled = $request->boolean('enabled');

        $settings->set([
            'site_form.enabled' => $enabled,
            // Seuls les emails reçus après l'activation sont lus.
            'site_form.enabled_at' => $enabled ? ($settings->get('site_form.enabled') ? $settings->get('site_form.enabled_at') : now()->toDateTimeString()) : null,
            'site_form.from' => trim((string) ($data['from'] ?? '')),
            'site_form.subject' => trim((string) ($data['subject'] ?? '')),
        ]);

        return back()->with('status', $enabled ? 'Formulaire du site relié : les nouvelles demandes arriveront dans « Demandes de devis ».' : 'Réglages enregistrés.');
    }

    /** Derniers emails de la boîte (expéditeur, objet) et lecture test, sans rien créer. */
    public function preview(SiteFormImporter $importer, MailSettings $mail): RedirectResponse
    {
        if (! $mail->isConfigured()) {
            return back()->withErrors(['preview' => 'Configurez d\'abord Gmail dans Réglages → Emails.']);
        }

        try {
            $messages = $importer->fetch(now()->subDays(14));
        } catch (Throwable $e) {
            return back()->withErrors(['preview' => 'Lecture de la boîte Gmail impossible : '.$e->getMessage()
                .'. Vérifiez que l\'accès IMAP est activé dans Gmail (Paramètres → Transfert et POP/IMAP).']);
        }

        $preview = collect($messages)->take(25)->map(fn ($m) => [
            'from' => $m['from'],
            'subject' => mb_strimwidth($m['subject'], 0, 90, '…'),
            'date' => $m['date']?->format('d/m H:i'),
            'matches' => $importer->matches($m),
            'parsed' => $importer->matches($m)
                ? (fn ($d) => array_map(fn ($k) => $d[$k] ?? null, ['first_name', 'last_name', 'phone', 'email', 'city']))($importer->parse($m['text']))
                : null,
        ])->all();

        return back()->with('preview', $preview);
    }
}

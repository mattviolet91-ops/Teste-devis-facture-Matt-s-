<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Mail\ClientMessage;
use App\Models\EmailTemplate;
use App\Services\ActivityLogger;
use App\Services\EmailComposer;
use App\Services\MailSettings;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** Réglages → Emails : connexion Gmail et modèles d'emails. */
class EmailSettingsController extends Controller
{
    public function edit(Settings $settings, MailSettings $mail): View
    {
        return view('settings.emails', [
            'mail' => $settings->group('mail'),
            'username' => $mail->username(),
            'hasPassword' => $mail->hasPassword(),
            'configured' => $mail->isConfigured(),
            'templates' => EmailTemplate::query()->ordered()->get(),
            'reminders' => $settings->group('reminders'),
            'variables' => EmailComposer::VARIABLES,
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'email', 'max:160'],
            'password' => ['nullable', 'string', 'max:64'],
            'from_name' => ['nullable', 'string', 'max:120'],
        ], [], ['username' => 'adresse Gmail', 'password' => 'mot de passe d\'application']);

        $values = [
            'mail.username' => $data['username'],
            'mail.from_name' => $data['from_name'] ?? '',
            'mail.bcc_self' => $request->boolean('bcc_self'),
        ];
        if ($request->boolean('forget_password')) {
            $values['mail.password'] = '';
        } elseif (! empty($data['password'])) {
            $values['mail.password'] = MailSettings::encrypt($data['password']);
        }

        $settings->set($values);
        ActivityLogger::log('settings.mail', 'Réglages d\'envoi des emails modifiés');

        return back()->with('status', 'Réglages des emails enregistrés.');
    }

    public function updateReminders(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'first_after_days' => ['required', 'integer', 'min:0', 'max:90'],
            'repeat_days' => ['required', 'integer', 'min:1', 'max:90'],
            'max' => ['required', 'integer', 'min:1', 'max:10'],
        ], [], ['first_after_days' => 'délai de la première relance', 'repeat_days' => 'intervalle', 'max' => 'nombre maximum']);

        $settings->set([
            'reminders.auto_enabled' => $request->boolean('auto_enabled'),
            'reminders.first_after_days' => (int) $data['first_after_days'],
            'reminders.repeat_days' => (int) $data['repeat_days'],
            'reminders.max' => (int) $data['max'],
        ]);
        ActivityLogger::log('settings.reminders', 'Relances automatiques '.($request->boolean('auto_enabled') ? 'activées' : 'désactivées'));

        return back()->with('status', 'Relances automatiques enregistrées.');
    }

    public function test(Settings $settings, MailSettings $mail): RedirectResponse
    {
        $mail->apply();
        $to = $settings->get('company.email');

        try {
            Mail::to($to)->send(new ClientMessage(
                'Test d\'envoi — '.$settings->get('company.trade_name'),
                "Bonjour,\n\nCet email de test confirme que l'application peut envoyer des emails.\n\nÀ bientôt !",
            ));
        } catch (Throwable $e) {
            return back()->withErrors(['mail_test' => 'Échec de l\'envoi : '.$e->getMessage()]);
        }

        return back()->with('status', "Email de test envoyé à $to. Vérifiez votre boîte de réception.");
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $template = EmailTemplate::query()->create($this->validateTemplate($request) + [
            'position' => (int) EmailTemplate::query()->max('position') + 1,
        ]);
        $this->applyDefault($template, $request->boolean('is_default'));

        return back()->with('status', 'Modèle ajouté.');
    }

    public function updateTemplate(Request $request, EmailTemplate $template): RedirectResponse
    {
        $template->update($this->validateTemplate($request));
        $this->applyDefault($template, $request->boolean('is_default'));

        return back()->with('status', 'Modèle enregistré.');
    }

    public function destroyTemplate(EmailTemplate $template): RedirectResponse
    {
        $template->delete();

        return back()->with('status', 'Modèle supprimé.');
    }

    /** @return array<string, mixed> */
    private function validateTemplate(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'context' => ['required', Rule::in(array_keys(EmailTemplate::CONTEXTS))],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
        ], [], ['name' => 'nom', 'subject' => 'objet', 'body' => 'message']);
    }

    /** Un seul modèle par défaut pour chaque type de document. */
    private function applyDefault(EmailTemplate $template, bool $default): void
    {
        if ($default) {
            EmailTemplate::query()->where('context', $template->context)->whereKeyNot($template->id)->update(['is_default' => false]);
        }
        $template->update(['is_default' => $default]);
    }
}

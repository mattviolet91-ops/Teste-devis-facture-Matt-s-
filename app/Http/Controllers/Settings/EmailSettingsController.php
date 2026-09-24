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
            'reviews' => $settings->group('reviews'),
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

    public function updateShareTexts(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'sms_quote' => ['required', 'string', 'max:1000'],
            'sms_invoice' => ['required', 'string', 'max:1000'],
            'reminder_before' => ['required', 'string', 'max:1000'],
            'reminder_after' => ['required', 'string', 'max:1000'],
            'maintenance' => ['required', 'string', 'max:1000'],
            'intervention' => ['required', 'string', 'max:1000'],
            'appointment' => ['required', 'string', 'max:1000'],
            'visit_reminder_subject' => ['required', 'string', 'max:200'],
            'visit_reminder' => ['required', 'string', 'max:1000'],
        ], [], [
            'maintenance' => 'relance d\'entretien', 'intervention' => 'confirmation d\'intervention', 'appointment' => 'confirmation de rendez-vous', 'visit_reminder' => 'rappel au client', 'visit_reminder_subject' => 'objet du rappel',
            'sms_quote' => 'message pour les devis', 'sms_invoice' => 'message pour les factures',
            'reminder_before' => 'rappel avant échéance', 'reminder_after' => 'relance en retard',
        ]);

        $settings->set([
            'mail.sms_quote' => $data['sms_quote'], 'mail.sms_invoice' => $data['sms_invoice'],
            'mail.reminder_before' => $data['reminder_before'], 'mail.reminder_after' => $data['reminder_after'],
            'mail.maintenance' => $data['maintenance'],
            'mail.intervention' => $data['intervention'],
            'mail.appointment' => $data['appointment'],
            'mail.visit_reminder_subject' => $data['visit_reminder_subject'],
            'mail.visit_reminder' => $data['visit_reminder'],
        ]);

        return back()->with('status', 'Messages SMS / WhatsApp enregistrés.');
    }

    /** Demande d'avis Google après un chantier payé. */
    public function updateReviews(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'google_url' => [Rule::requiredIf($request->boolean('enabled')), 'nullable', 'url:https', 'max:300'],
            'delay_days' => ['required', 'integer', 'min:0', 'max:30'],
            'review_subject' => ['required', 'string', 'max:200'],
            'review' => ['required', 'string', 'max:1000'],
        ], ['google_url.required' => 'Collez votre lien d\'avis Google pour activer la demande automatique.'], ['google_url' => 'lien d\'avis Google', 'review' => 'message']);

        $enabled = $request->boolean('enabled');
        $settings->set([
            'reviews.enabled' => $enabled,
            // Seuls les chantiers payés après l'activation sont concernés.
            'reviews.enabled_at' => $enabled ? ($settings->get('reviews.enabled') ? $settings->get('reviews.enabled_at') : now()->toDateTimeString()) : null,
            'reviews.google_url' => (string) ($data['google_url'] ?? ''),
            'reviews.delay_days' => (int) $data['delay_days'],
            'mail.review_subject' => $data['review_subject'],
            'mail.review' => $data['review'],
        ]);

        return redirect()->to(route('settings.emails').'#avis')->with('status', 'Demande d\'avis Google enregistrée.');
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
            'reminders.notify_enabled' => $request->boolean('notify_enabled'),
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

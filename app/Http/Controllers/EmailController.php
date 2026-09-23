<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\SentEmail;
use App\Services\EmailComposer;
use App\Services\EmailService;
use App\Services\InsuranceService;
use App\Services\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

/** Rédaction d'un email à partir d'un modèle, envoi et historique. */
class EmailController extends Controller
{
    public function index(Request $request): View
    {
        $emails = SentEmail::query()
            ->with(['client', 'document'])
            ->when($request->query('q'), fn ($query, $q) => $query->where(fn ($w) => $w
                ->where('to', 'like', '%'.$q.'%')->orWhere('subject', 'like', '%'.$q.'%')))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('emails.index', compact('emails'));
    }

    public function show(SentEmail $email): View
    {
        return view('emails.show', ['email' => $email->load(['client', 'document'])]);
    }

    public function create(Request $request, EmailComposer $composer, MailSettings $mail, InsuranceService $insurance): View|RedirectResponse
    {
        [$client, $document] = $this->context($request);

        $context = match (true) {
            $document instanceof Quote => 'quote',
            $document instanceof Invoice => 'invoice',
            default => 'any',
        };
        $templates = EmailTemplate::query()->for($context)->get();
        $rendered = $templates->mapWithKeys(fn (EmailTemplate $t) => [$t->id => $composer->render($t, $client, $document)]);
        $selected = $templates->first();

        return view('emails.create', [
            'client' => $client,
            'document' => $document,
            'templates' => $templates,
            'rendered' => $rendered,
            'selected' => $selected,
            'configured' => $mail->isConfigured(),
            'bcc' => $mail->bccAddress(),
            'certificate' => $insurance->currentCertificate(),
        ]);
    }

    public function store(Request $request, EmailService $emails, MailSettings $mail): RedirectResponse
    {
        [$client, $document] = $this->context($request);

        $data = $request->validate([
            'to' => ['required', 'string', 'max:255'],
            'cc' => ['nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
        ], [], ['to' => 'destinataire', 'cc' => 'copie', 'subject' => 'objet', 'body' => 'message']);

        $to = $this->addresses($data['to']);
        $cc = $this->addresses($data['cc'] ?? '');
        $invalid = array_filter(array_merge($to, $cc), fn ($a) => ! filter_var($a, FILTER_VALIDATE_EMAIL));

        validator([], [])->after(function (Validator $v) use ($invalid, $to, $mail) {
            if ($to === []) {
                $v->errors()->add('to', 'Indiquez au moins une adresse email.');
            }
            if ($invalid) {
                $v->errors()->add('to', 'Adresse email invalide : '.implode(', ', $invalid));
            }
            if (! $mail->isConfigured()) {
                $v->errors()->add('to', 'L\'envoi n\'est pas encore configuré : renseignez Gmail dans Réglages → Emails, ou utilisez « Ouvrir dans ma messagerie ».');
            }
        })->validate();

        $log = $emails->send($client, $document, $to, $cc, $data['subject'], $data['body'], $request->boolean('attach_pdf'), $request->boolean('attach_insurance'));

        if (! $log->isSent()) {
            return back()->withInput()->withErrors(['to' => 'L\'envoi a échoué : '.$log->error]);
        }

        $target = match (true) {
            $document instanceof Quote => route('quotes.show', $document),
            $document instanceof Invoice => route('invoices.show', $document),
            default => route('clients.show', $client),
        };

        return redirect($target)->with('status', 'Email envoyé à '.$log->to.'.');
    }

    /** @return array{0: Client, 1: Quote|Invoice|null} */
    private function context(Request $request): array
    {
        if ($id = $request->integer('devis')) {
            $document = Quote::query()->findOrFail($id);
        } elseif ($id = $request->integer('facture')) {
            $document = Invoice::query()->findOrFail($id);
        } else {
            $document = null;
        }

        $client = $document?->client ?? Client::query()->findOrFail($request->integer('client'));
        abort_if($client->trashed(), 404);

        return [$client, $document];
    }

    /** @return list<string> */
    private function addresses(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $value))));
    }
}

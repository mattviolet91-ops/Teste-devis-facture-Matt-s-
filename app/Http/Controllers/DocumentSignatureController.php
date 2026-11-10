<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Services\ActivityLogger;
use App\Services\DocumentSignatureService;
use App\Services\LinkMailer;
use App\Services\ScanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/** Faire signer un document : sur place (téléphone tendu au client) ou par un lien envoyé au client. */
class DocumentSignatureController extends Controller
{
    public function __construct(private readonly DocumentSignatureService $signatures) {}

    /** « Faire signer un document » : client, fichier (ou scan) et envoi, en un seul écran. */
    public function create(Request $request, LinkMailer $mailer): View
    {
        $admin = $request->user()->isAdmin();
        $clients = Client::query()->orderBy('last_name')->orderBy('company_name')->get();
        $targets = [];
        foreach (Quote::query()->whereNotNull('number')->latest('id')->get(['id', 'client_id', 'number', 'title']) as $quote) {
            $targets[$quote->client_id][] = ['id' => 'q:'.$quote->id, 'label' => 'Devis '.$quote->number.($quote->title ? ' — '.$quote->title : '')];
        }
        if ($admin) {
            foreach (Invoice::query()->whereNotNull('number')->latest('id')->get(['id', 'client_id', 'number', 'kind', 'title']) as $invoice) {
                $targets[$invoice->client_id][] = ['id' => 'i:'.$invoice->id, 'label' => $invoice->kindLabel().' '.$invoice->number.($invoice->title ? ' — '.$invoice->title : '')];
            }
        }

        return view('attachments.send', [
            'clients' => $clients,
            'data' => $clients->mapWithKeys(fn (Client $c) => [$c->id => ['email' => (string) $c->email, 'targets' => $targets[$c->id] ?? []]]),
            'clientId' => (int) $request->query('client', 0) ?: null,
            'canEmail' => $mailer->canSend(),
        ]);
    }

    public function store(Request $request, ScanService $scans, LinkMailer $mailer): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'target' => ['nullable', 'string', 'regex:/^[qi]:\d+$/'],
            'name' => ['nullable', 'string', 'max:120'],
            'file' => ['nullable', 'required_without:pages', 'file', 'max:15360', 'mimes:pdf,jpg,jpeg,png,webp'],
            'pages' => ['nullable', 'array', 'max:20'],
            'pages.*' => ['file', 'max:15360', 'mimes:jpg,jpeg,png,webp'],
            'action' => ['required', Rule::in(['send', 'on_site'])],
            'to' => ['nullable', 'email', 'max:160'],
        ], [
            'client_id.required' => 'Choisissez le client.',
            'file.required_without' => 'Ajoutez le document (PDF ou photo), ou scannez-le.',
            'file.mimes' => 'Seul un PDF ou une photo peut être signé. Un fichier Word ou Excel doit d\'abord être enregistré en PDF.',
            'file.max' => 'Fichier trop lourd (15 Mo maximum).',
            'pages.*.mimes' => 'Le scan accepte des photos (JPEG, PNG).',
        ], ['to' => 'adresse email']);

        $client = Client::query()->findOrFail($data['client_id']);
        [$quote, $invoice] = [null, null];
        if (! empty($data['target'])) {
            [$kind, $id] = explode(':', $data['target']);
            if ($kind === 'q') {
                $quote = Quote::query()->where('client_id', $client->id)->findOrFail((int) $id);
            } else {
                abort_unless($request->user()->isAdmin(), 403);
                $invoice = Invoice::query()->where('client_id', $client->id)->findOrFail((int) $id);
            }
        }

        // Le fichier : scan (photos → un PDF) ou PDF / photo fourni.
        $folder = 'documents/clients/'.$client->id;
        if ($request->hasFile('pages')) {
            $title = trim((string) ($data['name'] ?? '')) ?: 'Document du '.now()->format('d/m/Y');
            try {
                $pdf = $scans->toPdf($request->file('pages'), $title);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['pages' => 'Une des photos n\'a pas pu être lue. Reprenez-la.']);
            }
            $path = $folder.'/'.Str::random(40).'.pdf';
            Storage::disk('local')->put($path, $pdf);
            [$name, $mime, $size] = [Str::finish($title, '.pdf'), 'application/pdf', strlen($pdf)];
        } else {
            $file = $request->file('file');
            $path = $file->store($folder, 'local');
            $extension = strtolower($file->getClientOriginalExtension() ?: 'pdf');
            $name = trim((string) ($data['name'] ?? '')) ? Str::finish(trim($data['name']), '.'.$extension) : $file->getClientOriginalName();
            [$mime, $size] = [$file->getMimeType() ?: 'application/octet-stream', (int) $file->getSize()];
        }

        $attachment = new Attachment(['name' => Str::limit($name, 150, '')]);
        $attachment->client()->associate($client);
        $attachment->forceFill(['quote_id' => $quote?->id, 'invoice_id' => $invoice?->id, 'path' => $path, 'mime' => $mime, 'size' => $size, 'created_by' => $request->user()->id])->save();

        try {
            $this->signatures->request($attachment);
        } catch (InvalidArgumentException $e) {
            Storage::disk('local')->delete($path);
            $attachment->delete();
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        ActivityLogger::log('attachment.added', "Document à faire signer ajouté : {$attachment->name}", $quote ?? $invoice ?? $client);

        if ($data['action'] === 'on_site') {
            return redirect()->route('attachments.sign.on-site', $attachment);
        }

        $status = 'Document prêt à signer : envoyez le lien au client (WhatsApp, SMS ou email) depuis la liste ci-dessous.';
        if (! empty($data['to'])) {
            $status = $mailer->canSend() && $mailer->sendSignatureLink($attachment, $data['to'])->isSent()
                ? "Document envoyé à {$data['to']} pour signature. Vous serez prévenu quand il sera signé."
                : 'Document prêt à signer, mais l\'email n\'a pas pu partir : envoyez le lien depuis la liste ci-dessous.';
        }

        return redirect()->to($this->back($attachment))->with('status', $status);
    }

    /** Prépare le lien de signature à envoyer au client. */
    public function request(Request $request, Attachment $attachment): RedirectResponse
    {
        $this->authorizeAccess($request, $attachment);
        try {
            $this->signatures->request($attachment);
        } catch (InvalidArgumentException $e) {
            return redirect()->to($this->back($attachment))->withErrors(['signature_'.$attachment->id => $e->getMessage()]);
        }

        return redirect()->to($this->back($attachment))->with('status', 'Lien de signature prêt : envoyez-le au client ou faites signer sur place.');
    }

    public function cancel(Request $request, Attachment $attachment): RedirectResponse
    {
        $this->authorizeAccess($request, $attachment);
        $this->signatures->cancel($attachment);

        return redirect()->to($this->back($attachment))->with('status', 'Demande de signature annulée : le lien ne fonctionne plus.');
    }

    /** Envoie le lien de signature au client par email. */
    public function email(Request $request, Attachment $attachment, LinkMailer $mailer): RedirectResponse
    {
        $this->authorizeAccess($request, $attachment);
        abort_unless($attachment->awaitsSignature() && $attachment->public_token, 409, 'Ce document n\'attend pas de signature.');
        $key = 'signature_'.$attachment->id;
        $data = $request->validate(['to' => ['required', 'email', 'max:160'], 'message' => ['nullable', 'string', 'max:5000']], [], ['to' => 'adresse email']);
        if (! $mailer->canSend()) {
            return redirect()->to($this->back($attachment))->withErrors([$key => 'L\'envoi des emails n\'est pas configuré (Réglages → Emails).']);
        }

        $sent = $mailer->sendSignatureLink($attachment, $data['to'], $data['message'] ?? null);

        return $sent->isSent()
            ? redirect()->to($this->back($attachment))->with('status', 'Document à signer envoyé à '.$data['to'].'.')
            : redirect()->to($this->back($attachment))->withErrors([$key => 'L\'email n\'a pas pu être envoyé. Vérifiez l\'adresse ou réessayez.']);
    }

    /** Écran tendu au client pendant le rendez-vous. */
    public function onSite(Request $request, Attachment $attachment): View|RedirectResponse
    {
        $this->authorizeAccess($request, $attachment);
        if ($attachment->isSigned()) {
            return redirect()->to($this->back($attachment))->with('status', 'Ce document est déjà signé.');
        }
        abort_unless($attachment->canBeSigned(), 404);

        return view('attachments.sign', [
            'attachment' => $attachment->load(['client', 'quote', 'invoice']),
            'fileUrl' => route('attachments.show', $attachment),
            'action' => route('attachments.sign.on-site.store', $attachment),
            'back' => $this->back($attachment),
            'onSite' => true,
        ]);
    }

    public function signOnSite(Request $request, Attachment $attachment): RedirectResponse
    {
        $this->authorizeAccess($request, $attachment);
        abort_if($attachment->isSigned(), 409, 'Ce document est déjà signé.');
        abort_unless($attachment->canBeSigned(), 404);
        $data = self::validateSignature($request, onSite: true);

        if (! $attachment->public_token) {
            try {
                $this->signatures->request($attachment);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['signature' => $e->getMessage()]);
            }
        }
        try {
            $this->signatures->sign($attachment, trim($data['name']), $data['signature'], $request->ip(), $request->userAgent(), onSite: true);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['signature' => 'La signature n\'a pas pu être lue. Effacez et signez de nouveau.']);
        }

        return redirect()->to($this->back($attachment))->with('status', "« {$attachment->name} » signé sur place par ".trim($data['name']).'.');
    }

    /** Image du tracé de signature (preuve). */
    public function signature(Request $request, Attachment $attachment): Response
    {
        $this->authorizeAccess($request, $attachment);
        abort_unless($attachment->signature_path && Storage::disk('local')->exists($attachment->signature_path), 404);

        return Storage::disk('local')->response($attachment->signature_path, null, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
    }

    /** @return array{name: string, signature: string} */
    public static function validateSignature(Request $request, bool $onSite): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'signature' => ['required', 'string', 'max:800000'],
            'agree' => ['accepted'],
        ], [
            'name.required' => $onSite ? 'Indiquez le nom et prénom du client.' : 'Indiquez votre nom et prénom.',
            'signature.required' => $onSite ? 'Le client doit signer dans le cadre avec le doigt.' : 'Signez dans le cadre avec le doigt ou la souris.',
            'agree.accepted' => 'Cochez la case « Lu et approuvé ».',
        ]);
    }

    /** Documents d'une facture : réservés au gérant, comme les factures. */
    private function authorizeAccess(Request $request, Attachment $attachment): void
    {
        abort_if($attachment->invoice_id && ! $request->user()?->isAdmin(), 403);
        abort_unless($attachment->client()->exists(), 404);
    }

    private function back(Attachment $attachment): string
    {
        [$route, $id] = $attachment->adminRoute();

        return route($route, $id).'#documents';
    }
}

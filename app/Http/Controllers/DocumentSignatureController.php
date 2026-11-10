<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\DocumentSignatureService;
use App\Services\LinkMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/** Faire signer un document : sur place (téléphone tendu au client) ou par un lien envoyé au client. */
class DocumentSignatureController extends Controller
{
    public function __construct(private readonly DocumentSignatureService $signatures) {}

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

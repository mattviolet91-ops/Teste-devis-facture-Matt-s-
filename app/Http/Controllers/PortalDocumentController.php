<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Invoice;
use App\Models\Photo;
use App\Models\Quote;
use App\Services\DocumentSignatureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Espace client, sans compte : documents à signer (lien secret) et photos
 * des devis et factures consultés depuis leur lien.
 */
class PortalDocumentController extends Controller
{
    public function __construct(private readonly DocumentSignatureService $signatures) {}

    public function show(string $token): View
    {
        $attachment = $this->find($token);
        if (! auth()->check()) {
            $this->signatures->markViewed($attachment);
        }

        return view('attachments.sign', [
            'attachment' => $attachment->load(['client', 'quote', 'invoice']),
            'fileUrl' => route('portal.document.file', $token),
            'action' => route('portal.document.sign', $token),
            'back' => null,
            'onSite' => false,
        ]);
    }

    /** Le document (la version signée dès qu'elle existe). */
    public function file(Request $request, string $token): Response
    {
        $attachment = $this->find($token);
        abort_unless(Storage::disk('local')->exists($attachment->currentPath()), 404);

        return Storage::disk('local')->response($attachment->currentPath(), $attachment->currentName(), [
            'Content-Type' => $attachment->currentMime(),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ], $request->boolean('telecharger') ? 'attachment' : 'inline');
    }

    public function sign(Request $request, string $token): RedirectResponse
    {
        $attachment = $this->find($token);
        if ($attachment->isSigned()) {
            return redirect()->route('portal.document', $token)->with('status', 'Ce document est déjà signé. Merci !');
        }
        $data = DocumentSignatureController::validateSignature($request, onSite: false);

        try {
            $this->signatures->sign($attachment, trim($data['name']), $data['signature'], $request->ip(), $request->userAgent(), onSite: false);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['signature' => 'La signature n\'a pas pu être lue. Effacez et signez de nouveau.']);
        }

        return redirect()->route('portal.document', $token)->with('status', 'Merci ! Votre signature a bien été enregistrée.');
    }

    /** Photo d'un devis envoyé : seulement celles choisies pour ce devis. */
    public function quotePhoto(string $token, Photo $photo, string $variant): Response
    {
        $quote = Quote::query()->where('public_token', $token)->whereNotNull('number')->firstOrFail();

        return $this->photo($quote, $photo, $variant);
    }

    public function invoicePhoto(string $token, Photo $photo, string $variant): Response
    {
        $invoice = Invoice::query()->where('public_token', $token)->whereNotNull('number')->firstOrFail();

        return $this->photo($invoice, $photo, $variant);
    }

    private function photo(Quote|Invoice $document, Photo $photo, string $variant): Response
    {
        abort_unless($document->photos()->whereKey($photo->id)->exists(), 404);
        $path = $variant === 'mini' ? $photo->thumb_path : $photo->displayPath();
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=86400', 'X-Robots-Tag' => 'noindex']);
    }

    /** Seuls les documents dont la signature a été demandée ont un lien. */
    private function find(string $token): Attachment
    {
        return Attachment::query()->where('public_token', $token)->whereNotNull('signature_requested_at')->whereHas('client')->firstOrFail();
    }
}

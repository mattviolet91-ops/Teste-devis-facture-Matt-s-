<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\OnlinePayment;
use App\Models\Quote;
use App\Services\ClientLinkService;
use App\Services\DocumentCalculator;
use App\Services\MyposGateway;
use App\Services\PdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Espace client, sans compte : accessible uniquement avec le lien secret
 * envoyé par email. Consultation, PDF, acceptation signée, refus ou demande
 * de modification.
 */
class ClientPortalController extends Controller
{
    public function __construct(
        private readonly ClientLinkService $links,
        private readonly PdfService $pdf,
        private readonly DocumentCalculator $calculator,
    ) {}

    public function quote(string $token): View
    {
        $quote = $this->findQuote($token);
        if (! auth()->check()) {
            $this->links->markViewed($quote);
        }

        return view('portal.quote', [
            'quote' => $quote->load(['client', 'worksite', 'lines', 'replacedBy']),
            'totals' => $this->totals($quote),
        ]);
    }

    public function quotePdf(string $token): Response
    {
        $quote = $this->findQuote($token);

        return $this->pdfResponse($quote);
    }

    public function sign(Request $request, string $token): RedirectResponse
    {
        $quote = $this->findQuote($token);
        abort_unless($quote->canBeSignedOnline(), 409, 'Ce devis ne peut plus être accepté en ligne.');

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'signature' => ['required', 'string', 'max:800000'],
            'agree' => ['accepted'],
        ], [
            'name.required' => 'Indiquez votre nom et prénom.',
            'signature.required' => 'Signez dans le cadre avec le doigt ou la souris.',
            'agree.accepted' => 'Cochez la case « Bon pour accord » pour accepter le devis.',
        ]);

        try {
            $this->links->sign($quote, trim($data['name']), $data['signature'], $request->ip(), $request->userAgent());
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['signature' => 'La signature n\'a pas pu être lue. Effacez et signez de nouveau.']);
        }

        return redirect()->route('portal.quote', $token)->with('status', 'Merci ! Votre accord a bien été enregistré.');
    }

    public function refuse(Request $request, string $token): RedirectResponse
    {
        $quote = $this->findQuote($token);
        abort_unless($quote->canBeSignedOnline(), 409);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $this->links->refuse($quote, $data['comment'] ?? null);

        return redirect()->route('portal.quote', $token)->with('status', 'Votre réponse a bien été transmise. Merci de nous avoir répondu.');
    }

    public function requestChange(Request $request, string $token): RedirectResponse
    {
        $quote = $this->findQuote($token);
        abort_unless(in_array($quote->status, ['sent', 'expired'], true), 409);
        $data = $request->validate(['comment' => ['required', 'string', 'min:3', 'max:2000']], [
            'comment.required' => 'Décrivez la modification souhaitée.',
        ]);

        $this->links->requestChange($quote, $data['comment']);

        return redirect()->route('portal.quote', $token)->with('status', 'Votre demande a bien été transmise. Nous revenons vers vous rapidement avec un devis modifié.');
    }

    public function invoice(string $token): View
    {
        $invoice = Invoice::query()->where('public_token', $token)->whereNotNull('number')->firstOrFail();
        if (! auth()->check()) {
            $this->links->markViewed($invoice);
        }

        return view('portal.invoice', [
            'invoice' => $invoice->load(['client', 'worksite', 'lines', 'quote']),
            'totals' => $this->totals($invoice),
        ]);
    }

    /** Paiement par carte : formulaire signé envoyé automatiquement à myPOS. */
    public function pay(Request $request, string $token, MyposGateway $mypos): View|RedirectResponse
    {
        $invoice = Invoice::query()->where('public_token', $token)->whereNotNull('number')->firstOrFail();
        if (! $mypos->visibleTo($request) || ! $invoice->acceptsPayments() || $invoice->balance() <= 0) {
            return redirect()->route('portal.invoice', $token);
        }

        $attempt = $mypos->start($invoice);
        $form = $mypos->purchaseForm(
            $attempt,
            route('portal.invoice.paid', $token),
            route('portal.invoice.pay-cancel', $token),
            route('portal.mypos.notify'),
        );

        return view('portal.pay', ['invoice' => $invoice, 'form' => $form, 'test' => $attempt->test]);
    }

    /** Retour du client après paiement (la confirmation fiable arrive par la notification myPOS). */
    public function paid(string $token): RedirectResponse
    {
        return redirect()->route('portal.invoice', $token)->with('status', 'Merci ! Votre paiement a bien été transmis. La facture sera marquée réglée dès sa confirmation.');
    }

    public function payCancelled(Request $request, string $token): RedirectResponse
    {
        if ($order = $request->input('OrderID')) {
            OnlinePayment::query()->where('order_id', (string) $order)->where('status', 'pending')->update(['status' => 'cancelled']);
        }

        return redirect()->route('portal.invoice', $token)->with('status', 'Paiement annulé : aucun montant n\'a été débité.');
    }

    public function invoicePdf(string $token): Response
    {
        $invoice = Invoice::query()->where('public_token', $token)->whereNotNull('number')->firstOrFail();

        return $this->pdfResponse($invoice);
    }

    /** Seul un devis envoyé (numéroté) est visible par le client. */
    private function findQuote(string $token): Quote
    {
        return Quote::query()->where('public_token', $token)->whereNotNull('number')->firstOrFail();
    }

    private function totals(Quote|Invoice $document): array
    {
        return $this->calculator->calculate(
            $document->lines()->get()->map->toCalculation()->values()->all(),
            $document->discount_type,
            (int) $document->discount_value,
            $document->isFranchise(),
        );
    }

    private function pdfResponse(Quote|Invoice $document): Response
    {
        return response($this->pdf->content($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->pdf->filename($document).'"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}

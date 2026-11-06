<?php

namespace App\Http\Controllers;

use App\Models\OnlinePayment;
use App\Models\PaymentRequest;
use App\Services\ClientLinkService;
use App\Services\MyposGateway;
use App\Services\PaymentRequestService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Espace client : paiement par carte d'un montant libre, depuis le lien envoyé. */
class PortalPaymentRequestController extends Controller
{
    public function show(Request $request, string $token, MyposGateway $mypos, PaymentRequestService $requests, ClientLinkService $links): View
    {
        $paymentRequest = $this->find($token);
        if (! auth()->check() && ! $paymentRequest->viewed_at) {
            $paymentRequest->forceFill(['viewed_at' => now()])->saveQuietly();
            $links->notifyText('Lien de paiement ouvert', "{$paymentRequest->client?->displayName()} vient d'ouvrir le lien de paiement de ".Money::plain($paymentRequest->amount)." ({$paymentRequest->label}).",
                ClientLinkService::adminUrl('payments.requests.show', $paymentRequest));
        }

        return view('portal.payment-request', [
            'paymentRequest' => $paymentRequest->load(['client', 'invoice']),
            'canPay' => $paymentRequest->isPending() && $mypos->visibleTo($request),
            'legalProblem' => $paymentRequest->isPending() ? $this->waitDate($paymentRequest, $requests) : null,
        ]);
    }

    public function pay(Request $request, string $token, MyposGateway $mypos, PaymentRequestService $requests): View|RedirectResponse
    {
        $paymentRequest = $this->find($token);
        $back = route('portal.payment-request', array_filter(['token' => $token, 'essai' => $request->boolean('essai') ? 1 : null]));
        if (! $paymentRequest->isPending() || ! $mypos->visibleTo($request) || $this->waitDate($paymentRequest, $requests)) {
            return redirect()->to($back);
        }

        $attempt = $mypos->startRequest($paymentRequest);
        $form = $mypos->purchaseForm($attempt, route('portal.payment-request.paid', $token), route('portal.payment-request.cancelled', $token), route('portal.mypos.notify'));

        return view('portal.pay', [
            'title' => $paymentRequest->label,
            'amount' => $paymentRequest->amount,
            'form' => $form,
            'test' => $attempt->test,
        ]);
    }

    public function paid(string $token): RedirectResponse
    {
        $this->find($token);

        return redirect()->route('portal.payment-request', $token)->with('status', 'Merci ! Votre paiement a bien été transmis. Il apparaîtra comme reçu dès sa confirmation.');
    }

    public function cancelled(Request $request, string $token): RedirectResponse
    {
        $paymentRequest = $this->find($token);
        if ($order = $request->input('OrderID')) {
            OnlinePayment::query()->where('order_id', (string) $order)->where('status', 'pending')
                ->where('payment_request_id', $paymentRequest->id)->update(['status' => 'cancelled']);
        }

        return redirect()->route('portal.payment-request', $token)->with('status', 'Paiement annulé : aucun montant n\'a été débité.');
    }

    /** Délai légal de 7 jours encore en cours : message pour le client. */
    private function waitDate(PaymentRequest $paymentRequest, PaymentRequestService $requests): ?string
    {
        return $paymentRequest->client && $requests->legalProblem($paymentRequest->client, $paymentRequest->quote)
            ? 'Le paiement sera possible à l\'issue du délai légal de 7 jours après la signature du devis à votre domicile.'
            : null;
    }

    private function find(string $token): PaymentRequest
    {
        return PaymentRequest::query()->where('token', $token)->whereHas('client')->firstOrFail();
    }
}

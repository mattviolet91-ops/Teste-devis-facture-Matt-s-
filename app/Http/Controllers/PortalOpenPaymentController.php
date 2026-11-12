<?php

namespace App\Http\Controllers;

use App\Models\OnlinePayment;
use App\Services\MyposGateway;
use App\Services\PaymentRequestService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Lien permanent « montant libre » : le client indique son nom, le motif et le montant,
 * puis paie par carte. Adresse secrète (code) donnée par l'entreprise.
 */
class PortalOpenPaymentController extends Controller
{
    public function __construct(private readonly MyposGateway $mypos) {}

    public function show(Request $request, string $code): View
    {
        $this->authorizeCode($code);
        [$min, $max] = $this->mypos->freeLinkBounds();

        return view('portal.pay-open', [
            'code' => $code,
            'available' => $this->mypos->visibleTo($request),
            'test' => $this->mypos->isTest(),
            'min' => $min,
            'max' => $max,
        ]);
    }

    public function start(Request $request, string $code, PaymentRequestService $requests): View|RedirectResponse
    {
        $this->authorizeCode($code);
        $back = route('portal.pay-open', array_filter(['code' => $code, 'essai' => $request->boolean('essai') ? 1 : null]));
        if (! $this->mypos->visibleTo($request)) {
            return redirect()->to($back);
        }
        // Champ piège pour les robots : un visiteur ne le voit pas et le laisse vide.
        if ($request->filled('site_web')) {
            return redirect()->to($back);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'min:2', 'max:80'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30'],
            'label' => ['required', 'string', 'min:3', 'max:150'],
            'amount' => ['required', 'string', 'max:20'],
            'agree' => ['accepted'],
        ], [
            'agree.accepted' => 'Cochez la case pour accepter les conditions générales de vente.',
        ], ['first_name' => 'prénom', 'last_name' => 'nom', 'email' => 'adresse email', 'phone' => 'téléphone', 'label' => 'motif du paiement', 'amount' => 'montant']);

        [$min, $max] = $this->mypos->freeLinkBounds();
        $amount = Money::parse($data['amount']);
        if (! $amount || $amount < $min || $amount > $max) {
            throw ValidationException::withMessages(['amount' => 'Indiquez un montant entre '.Money::format($min).' et '.Money::format($max).'.']);
        }

        try {
            $paymentRequest = $requests->createOpenRequest([
                'first_name' => trim($data['first_name']), 'last_name' => trim($data['last_name']), 'email' => $data['email'],
                'phone' => $data['phone'] ?? null, 'label' => trim($data['label']), 'amount' => $amount,
            ]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        $attempt = $this->mypos->startRequest($paymentRequest);
        $form = $this->mypos->purchaseForm($attempt, route('portal.pay-open.thanks', $code), route('portal.pay-open.cancelled', $code), route('portal.mypos.notify'));

        return view('portal.pay', ['title' => $paymentRequest->label, 'amount' => $amount, 'form' => $form, 'test' => $attempt->test]);
    }

    public function thanks(string $code): View
    {
        $this->authorizeCode($code);

        return view('portal.pay-open-done', ['code' => $code, 'paid' => true]);
    }

    public function cancelled(Request $request, string $code): View
    {
        $this->authorizeCode($code);
        if ($order = $request->input('OrderID')) {
            OnlinePayment::query()->where('order_id', (string) $order)->where('status', 'pending')
                ->whereHas('paymentRequest', fn ($q) => $q->whereNotNull('payer_email'))->update(['status' => 'cancelled']);
        }

        return view('portal.pay-open-done', ['code' => $code, 'paid' => false]);
    }

    /** Le lien n'existe que s'il est activé et que le code est le bon. */
    private function authorizeCode(string $code): void
    {
        abort_unless($this->mypos->freeLinkEnabled() && hash_equals(basename($this->mypos->freeLinkUrl()), $code), 404);
    }
}

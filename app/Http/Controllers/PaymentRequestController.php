<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Services\LinkMailer;
use App\Services\MyposGateway;
use App\Services\PaymentRequestService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/** Encaisser un montant libre : déjà reçu, ou par carte avec un lien envoyé au client. */
class PaymentRequestController extends Controller
{
    public function __construct(private readonly PaymentRequestService $requests) {}

    public function create(Request $request, MyposGateway $mypos): View
    {
        $clients = Client::query()->orderBy('last_name')->orderBy('company_name')->get();
        // Factures envoyées et pas encore soldées : celles sur lesquelles un paiement peut être enregistré.
        $invoices = Invoice::query()->invoices()->whereIn('status', Invoice::OPEN)->whereNotNull('number')->latest('id')->get();

        return view('payments.collect', [
            'clients' => $clients,
            'invoicesByClient' => $invoices->groupBy('client_id')->map(fn ($list) => $list->map(fn (Invoice $i) => [
                'id' => $i->id,
                'label' => $i->kindLabel().' '.$i->number.' — reste '.Money::format($i->balance()),
                'balance' => $i->balance(),
                'title' => $i->kindLabel().' '.$i->number,
            ])->values()),
            'clientId' => (int) $request->query('client', 0) ?: null,
            'invoiceId' => (int) $request->query('facture', 0) ?: null,
            'myposReady' => $mypos->isEnabled(),
            'myposTest' => $mypos->isTest(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'invoice_id' => ['nullable', 'integer', Rule::exists('invoices', 'id')->whereNull('deleted_at')],
            'label' => ['nullable', 'required_without:invoice_id', 'string', 'max:150'],
            'amount' => ['required', 'string', 'max:20'],
            'mode' => ['required', Rule::in(['card', 'received'])],
            'paid_at' => ['exclude_unless:mode,received', 'required', 'date', 'before_or_equal:today'],
            'method' => ['exclude_unless:mode,received', 'required', Rule::in(array_keys(Payment::METHODS))],
            'method_detail' => ['exclude_unless:mode,received', 'nullable', 'required_if:method,autre', 'string', 'max:80'],
            'reference' => ['exclude_unless:mode,received', 'nullable', 'string', 'max:80'],
        ], [
            'client_id.required' => 'Choisissez le client.',
            'label.required_without' => 'Indiquez ce que le client règle (ex. « Acompte toiture »), ou choisissez la facture concernée.',
            'paid_at.before_or_equal' => 'La date du paiement ne peut pas être dans le futur.',
        ]);

        $amount = Money::parse($data['amount']);
        if (! $amount || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Indiquez un montant valide (ex. 500 ou 1 250,50).']);
        }
        $client = Client::query()->findOrFail($data['client_id']);
        $invoice = isset($data['invoice_id']) ? Invoice::query()->findOrFail($data['invoice_id']) : null;
        $label = trim((string) ($data['label'] ?? '')) ?: ($invoice ? $invoice->kindLabel().' '.$invoice->number : '');

        try {
            if ($data['mode'] === 'received') {
                $payment = $this->requests->recordReceived($client, $invoice, [
                    'label' => $label, 'amount' => $amount, 'paid_at' => $data['paid_at'], 'method' => $data['method'],
                    'method_detail' => $data['method_detail'] ?? null, 'reference' => $data['reference'] ?? null,
                ]);

                return $payment->invoice
                    ? redirect()->to(route('invoices.show', $payment->invoice).'#paiements')->with('status', Money::format($amount)." encaissé sur la facture {$payment->invoice->number}.")
                    : redirect()->to(route('payments.index').'#a-rattacher')->with('status', Money::format($amount).' encaissé. Aucune facture n\'est créée : rattachez cet encaissement à une facture quand elle est prête.');
            }

            $paymentRequest = $this->requests->createRequest($client, $invoice, $label, $amount);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['invoice_id' => $e->getMessage()]);
        }

        return redirect()->route('payments.requests.show', $paymentRequest)->with('status', 'Lien de paiement prêt : envoyez-le au client ou ouvrez-le pour qu\'il paie sur place.');
    }

    public function show(PaymentRequest $paymentRequest, MyposGateway $mypos, LinkMailer $mailer): View
    {
        return view('payments.request', [
            'canEmail' => $mailer->canSend(),
            'emailMessage' => $mailer->paymentMessage($paymentRequest),
            'paymentRequest' => $paymentRequest->load(['client', 'invoice']),
            'payment' => $paymentRequest->attempts()->whereNotNull('payment_id')->with('payment.invoice')->latest('id')->first()?->payment,
            'myposReady' => $mypos->isEnabled(),
            'myposTest' => $mypos->isTest(),
        ]);
    }

    /** Envoie le lien de paiement au client par email. */
    public function email(Request $request, PaymentRequest $paymentRequest, LinkMailer $mailer): RedirectResponse
    {
        abort_unless($paymentRequest->isPending(), 409, 'Cette demande n\'est plus en attente.');
        $data = $request->validate(['to' => ['required', 'email', 'max:160'], 'message' => ['nullable', 'string', 'max:5000']], [], ['to' => 'adresse email']);
        if (! $mailer->canSend()) {
            return back()->withErrors(['to' => 'L\'envoi des emails n\'est pas configuré (Réglages → Emails).'])->withInput();
        }

        $sent = $mailer->sendPaymentLink($paymentRequest, $data['to'], $data['message'] ?? null);

        return $sent->isSent()
            ? back()->with('status', 'Lien de paiement envoyé à '.$data['to'].'.')
            : back()->withErrors(['to' => 'L\'email n\'a pas pu être envoyé. Vérifiez l\'adresse ou réessayez.'])->withInput();
    }

    public function cancel(PaymentRequest $paymentRequest): RedirectResponse
    {
        $this->requests->cancel($paymentRequest);

        return redirect()->route('payments.requests.show', $paymentRequest)->with('status', 'Demande annulée : le lien ne permet plus de payer.');
    }
}

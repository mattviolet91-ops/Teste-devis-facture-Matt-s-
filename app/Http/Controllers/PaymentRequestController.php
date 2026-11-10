<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Quote;
use App\Models\VatRate;
use App\Services\LinkMailer;
use App\Services\MyposGateway;
use App\Services\PaymentRequestService;
use App\Services\Settings;
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

    public function create(Request $request, Settings $settings, MyposGateway $mypos): View
    {
        $clients = Client::query()->orderBy('last_name')->orderBy('company_name')->get();
        $quotes = Quote::query()->where('status', 'accepted')->whereNotNull('number')->latest('id')->get(['id', 'client_id', 'number', 'title', 'total_ttc']);

        return view('payments.collect', [
            'clients' => $clients,
            'quotesByClient' => $quotes->groupBy('client_id')->map(fn ($list) => $list->map(fn (Quote $q) => [
                'id' => $q->id,
                'label' => $q->number.($q->title ? ' — '.$q->title : '').' ('.Money::format($q->total_ttc).')',
            ])->values()),
            'clientId' => (int) $request->query('client', 0) ?: null,
            'quoteId' => (int) $request->query('devis', 0) ?: null,
            'franchise' => $settings->get('vat.regime') === 'franchise',
            'vatRates' => VatRate::query()->where('is_active', true)->ordered()->get(),
            'defaultRate' => (int) (VatRate::query()->where('is_default', true)->value('rate') ?? 0),
            'myposReady' => $mypos->isEnabled(),
            'myposTest' => $mypos->isTest(),
        ]);
    }

    public function store(Request $request, Settings $settings): RedirectResponse
    {
        $franchise = $settings->get('vat.regime') === 'franchise';
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'quote_id' => ['nullable', 'integer', Rule::exists('quotes', 'id')],
            'kind' => ['required', Rule::in(['deposit', 'standard'])],
            'label' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'string', 'max:20'],
            'vat_rate' => [$franchise ? 'nullable' : 'required', 'integer', Rule::exists('vat_rates', 'rate')],
            'mode' => ['required', Rule::in(['card', 'received'])],
            'paid_at' => ['exclude_unless:mode,received', 'required', 'date', 'before_or_equal:today'],
            'method' => ['exclude_unless:mode,received', 'required', Rule::in(array_keys(Payment::METHODS))],
            'method_detail' => ['exclude_unless:mode,received', 'nullable', 'required_if:method,autre', 'string', 'max:80'],
            'reference' => ['exclude_unless:mode,received', 'nullable', 'string', 'max:80'],
        ], [
            'client_id.required' => 'Choisissez le client.',
            'label.required' => 'Indiquez ce que le client règle (ex. « Acompte toiture »).',
            'paid_at.before_or_equal' => 'La date du paiement ne peut pas être dans le futur.',
            'vat_rate.required' => 'Choisissez le taux de TVA.',
        ]);

        $amount = Money::parse($data['amount']);
        if (! $amount || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Indiquez un montant valide (ex. 500 ou 1 250,50).']);
        }
        $client = Client::query()->findOrFail($data['client_id']);
        $quote = isset($data['quote_id']) ? Quote::query()->find($data['quote_id']) : null;
        $vatRate = (int) ($data['vat_rate'] ?? VatRate::query()->where('is_default', true)->value('rate') ?? 0);
        $label = trim($data['label']);

        try {
            if ($data['mode'] === 'received') {
                $invoice = $this->requests->recordReceived($client, $quote, [
                    'kind' => $data['kind'], 'label' => $label, 'amount' => $amount, 'vat_rate' => $vatRate,
                    'paid_at' => $data['paid_at'], 'method' => $data['method'],
                    'method_detail' => $data['method_detail'] ?? null, 'reference' => $data['reference'] ?? null,
                ]);

                return redirect()->route('invoices.show', $invoice)->with('status', Money::format($amount)." encaissé : {$invoice->kindLabel()} {$invoice->number} créée et réglée.");
            }

            $paymentRequest = $this->requests->createRequest($client, $quote, $data['kind'], $label, $amount, $vatRate);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['quote_id' => $e->getMessage()]);
        }

        return redirect()->route('payments.requests.show', $paymentRequest)->with('status', 'Lien de paiement prêt : envoyez-le au client ou ouvrez-le pour qu\'il paie sur place.');
    }

    public function show(PaymentRequest $paymentRequest, MyposGateway $mypos, LinkMailer $mailer): View
    {
        return view('payments.request', [
            'canEmail' => $mailer->canSend(),
            'emailMessage' => $mailer->paymentMessage($paymentRequest),
            'paymentRequest' => $paymentRequest->load(['client', 'quote', 'invoice']),
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

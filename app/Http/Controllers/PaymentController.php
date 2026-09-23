<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/** Paiements : liste, enregistrement sur une facture, suppression. */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): View
    {
        $data = $request->validate([
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'mode' => ['nullable', Rule::in(array_keys(Payment::METHODS))],
        ]);
        $from = isset($data['du']) ? Carbon::parse($data['du']) : today()->startOfMonth();
        $to = isset($data['au']) ? Carbon::parse($data['au']) : today();

        $query = Payment::query()
            ->whereDate('paid_at', '>=', $from->toDateString())
            ->whereDate('paid_at', '<=', $to->toDateString())
            ->when($data['mode'] ?? null, fn ($q, $mode) => $q->where('method', $mode));

        return view('payments.index', [
            'payments' => (clone $query)->with(['invoice', 'client'])->latest('paid_at')->latest('id')->paginate(50)->withQueryString(),
            'total' => (int) (clone $query)->sum('amount'),
            'byMethod' => (clone $query)->selectRaw('method, SUM(amount) as total, COUNT(*) as count')->groupBy('method')->get(),
            'from' => $from,
            'to' => $to,
            'mode' => $data['mode'] ?? null,
            'openInvoices' => Invoice::query()->invoices()->whereIn('status', Invoice::OPEN)->with('client')->orderBy('due_date')->get(),
        ]);
    }

    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'string', 'max:20'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'method_detail' => ['nullable', 'required_if:method,autre', 'string', 'max:80'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'paid_at.before_or_equal' => 'La date du paiement ne peut pas être dans le futur.',
            'method_detail.required_if' => 'Précisez le moyen de paiement.',
        ], ['paid_at' => 'date du paiement', 'amount' => 'montant', 'method' => 'moyen de paiement']);

        $amount = Money::parse($data['amount']);
        if ($amount === null) {
            return back()->withInput()->withErrors(['amount' => 'Montant invalide (ex. 800 ou 800,50).']);
        }

        try {
            $this->payments->record($invoice, ['amount' => $amount] + $data);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        $invoice->refresh();
        $message = $invoice->status === 'paid'
            ? "Paiement enregistré : la facture {$invoice->number} est entièrement payée."
            : 'Paiement enregistré. Reste à payer : '.Money::format($invoice->balance()).'.';

        return redirect()->route('invoices.show', $invoice)->with('status', $message)->with('thank', $invoice->status === 'paid');
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        $invoice = $payment->invoice;
        $this->payments->delete($payment);

        return redirect()->route('invoices.show', $invoice)->with('status', 'Paiement supprimé.');
    }
}

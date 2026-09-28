<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Services\ActivityLogger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Frais d'une facture (section « Frais » de la page facture) : l'application
 * calcule ce qu'il reste. Réservé au gérant, jamais montré au client.
 */
class ExpenseController extends Controller
{
    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_if($invoice->isCredit(), 404);

        $data = $request->validate([
            'expense_label' => ['required', 'string', 'max:160'],
            'expense_amount' => ['required', 'string', 'max:20'],
            'expense_vat' => ['nullable', 'string', 'max:20'],
            'category' => ['nullable', Rule::in(array_keys(Expense::CATEGORIES))],
            'receipt' => ['nullable', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf'],
        ], [], ['expense_label' => 'description', 'expense_amount' => 'montant', 'expense_vat' => 'TVA', 'receipt' => 'ticket']);

        $amount = Money::parse($data['expense_amount']);
        $vat = ($data['expense_vat'] ?? '') === '' ? 0 : Money::parse($data['expense_vat']);
        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages(['expense_amount' => 'Indiquez le montant du frais, par exemple 125,40.']);
        }
        if ($vat === null || $vat < 0 || $vat >= $amount) {
            throw ValidationException::withMessages(['expense_vat' => 'La TVA doit être inférieure au montant.']);
        }

        $expense = new Expense([
            'label' => $data['expense_label'],
            'category' => $data['category'] ?? 'materiaux',
            'amount_ttc' => $amount,
            'vat' => $vat,
            'spent_on' => today(),
            'invoice_id' => $invoice->id,
            'quote_id' => $invoice->quote_id,
        ]);
        if ($request->hasFile('receipt')) {
            $file = $request->file('receipt');
            $expense->receipt_path = $file->store('frais/'.now()->format('Y-m'), 'local');
            $expense->receipt_name = Str::limit($file->getClientOriginalName(), 200, '');
        }
        $expense->save();
        ActivityLogger::log('expense.created', 'Frais : '.$expense->label.' ('.Money::format($amount).')', $invoice);

        return redirect()->to(route('invoices.show', $invoice).'#frais')->with('status', 'Frais ajouté.');
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        if ($expense->receipt_path) {
            Storage::disk('local')->delete($expense->receipt_path);
        }
        $invoice = $expense->invoice;
        $expense->delete();

        return ($invoice ? redirect()->to(route('invoices.show', $invoice).'#frais') : redirect()->route('invoices.index'))
            ->with('status', 'Frais supprimé.');
    }

    /** Photo ou PDF du ticket (dossier privé). */
    public function receipt(Expense $expense): StreamedResponse
    {
        abort_unless($expense->receipt_path && Storage::disk('local')->exists($expense->receipt_path), 404);
        $name = Str::of($expense->receipt_name ?? 'ticket')->ascii()->replaceMatches('/[^A-Za-z0-9._ -]/', '')->limit(100, '')->trim()->value() ?: 'ticket';

        return Storage::disk('local')->response($expense->receipt_path, $name, [
            'Content-Disposition' => 'inline; filename="'.$name.'"',
        ]);
    }
}

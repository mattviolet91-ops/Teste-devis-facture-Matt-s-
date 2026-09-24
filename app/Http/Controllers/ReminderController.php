<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\ActivityLogger;
use App\Services\EmailComposer;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Relance d'une facture par SMS, WhatsApp, email ou copie, avec un texte prêt. */
class ReminderController extends Controller
{
    public const CHANNELS = ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'email', 'copy' => 'message copié'];

    public function index(): View
    {
        $invoices = Invoice::query()->invoices()->whereIn('status', Invoice::OPEN)
            ->whereNotNull('due_date')->whereDate('due_date', '<=', today()->addDays(3))
            ->with('client')->orderBy('due_date')->get();

        return view('reminders.index', compact('invoices'));
    }

    public function show(Invoice $invoice, EmailComposer $composer, Settings $settings): View
    {
        abort_unless($invoice->acceptsPayments(), 404);
        $invoice->load('client');

        $overdue = $invoice->due_date && $invoice->due_date->lt(today());
        $template = (string) $settings->get($overdue ? 'mail.reminder_after' : 'mail.reminder_before');

        return view('reminders.show', [
            'invoice' => $invoice,
            'overdue' => $overdue,
            'message' => $composer->renderText($template, $invoice->client, $invoice),
        ]);
    }

    /** Relance envoyée (appelé par le bouton WhatsApp / SMS / copie) : comptée et tracée. */
    public function track(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::in(array_keys(self::CHANNELS))]]);

        $invoice->forceFill(['reminder_count' => $invoice->reminder_count + 1, 'last_reminder_at' => now()])->save();
        ActivityLogger::log('invoice.reminded', "Relance de la facture {$invoice->number} par ".self::CHANNELS[$data['channel']], $invoice);

        return response()->json(['count' => $invoice->reminder_count]);
    }
}

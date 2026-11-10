<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\OnlinePayment;
use App\Services\ActivityLogger;
use App\Services\MyposGateway;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Réglages du paiement par carte en ligne (myPOS Checkout). */
class PaymentController extends Controller
{
    public function edit(Settings $settings, MyposGateway $mypos): View
    {
        $stored = (bool) $settings->get('mypos.package');

        return view('settings.payments', [
            'enabled' => (bool) $settings->get('mypos.enabled'),
            'test' => $mypos->isTest(),
            'emailReceipt' => (bool) $settings->get('mypos.email_receipt', true),
            'freeLinkEnabled' => $mypos->freeLinkEnabled(),
            'freeLinkUrl' => $mypos->freeLinkEnabled() ? $mypos->freeLinkUrl() : null,
            'freeBounds' => $mypos->freeLinkBounds(),
            'hasPackage' => $stored,
            'sid' => $mypos->storedCredentials()['sid'] ?? null,
            // Lien d'essai : une facture en attente de paiement, ouverte comme un client.
            'trialUrl' => ($invoice = Invoice::query()->invoices()->whereIn('status', Invoice::OPEN)->whereNotNull('public_token')->latest('id')->first())
                ? $invoice->publicUrl().'?essai=1' : null,
            'attempts' => OnlinePayment::query()->with(['invoice.client', 'paymentRequest.client'])->latest('id')->limit(15)->get(),
        ]);
    }

    /** « Tester la connexion avec myPOS » : vérifie le pack, sans paiement. */
    public function check(MyposGateway $mypos): RedirectResponse
    {
        $result = $mypos->checkConnection();
        ActivityLogger::log('settings.mypos.check', 'Test de connexion myPOS : '.($result['ok'] ? 'réussi' : 'échec').($result['status'] !== null ? ' (code '.$result['status'].')' : ''));

        return redirect()->route('settings.payments')->with('mypos_check', $result);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'package' => ['nullable', 'string', 'max:20000'],
            'free_min' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'free_max' => ['nullable', 'integer', 'min:1', 'max:50000', 'gte:free_min'],
        ], ['free_max.gte' => 'Le montant maximum doit être supérieur au minimum.'], ['free_min' => 'montant minimum', 'free_max' => 'montant maximum']);

        $values = [
            'mypos.enabled' => $request->boolean('enabled'),
            'mypos.test' => $request->boolean('test'),
            'mypos.email_receipt' => $request->boolean('email_receipt'),
            'mypos.free_link' => $request->boolean('free_link'),
            'mypos.free_min' => (int) ($data['free_min'] ?? 1) * 100,
            'mypos.free_max' => (int) ($data['free_max'] ?? 3000) * 100,
        ];

        if (filled($data['package'] ?? null)) {
            if (! MyposGateway::parsePackage($data['package'])) {
                throw ValidationException::withMessages(['package' => 'Pack de configuration illisible : copiez-le en entier depuis votre compte myPOS (Boutique en ligne → Intégration).']);
            }
            $values['mypos.package'] = MyposGateway::encryptPackage($data['package']);
        }
        // Nouveau lien permanent : l'ancien ne fonctionne plus.
        if ($request->boolean('free_renew')) {
            $values['mypos.free_code'] = Str::random(24);
        }
        if ($request->boolean('forget')) {
            $values['mypos.package'] = '';
        }

        if ($values['mypos.enabled'] && ! $values['mypos.test'] && ! ($values['mypos.package'] ?? $settings->get('mypos.package'))) {
            throw ValidationException::withMessages(['package' => 'Collez votre pack de configuration myPOS avant d\'activer les paiements réels.']);
        }

        $settings->set($values);
        ActivityLogger::log('settings.mypos', 'Réglages du paiement par carte modifiés ('.($values['mypos.enabled'] ? ($values['mypos.test'] ? 'activé en test' : 'activé') : 'désactivé').')');

        return back()->with('status', 'Paiement en ligne enregistré.');
    }
}

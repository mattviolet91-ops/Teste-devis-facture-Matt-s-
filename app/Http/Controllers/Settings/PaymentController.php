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
            'hasPackage' => $stored,
            'sid' => $mypos->storedCredentials()['sid'] ?? null,
            // Lien d'essai : une facture en attente de paiement, ouverte comme un client.
            'trialUrl' => ($invoice = Invoice::query()->invoices()->whereIn('status', Invoice::OPEN)->whereNotNull('public_token')->latest('id')->first())
                ? $invoice->publicUrl().'?essai=1' : null,
            'attempts' => OnlinePayment::query()->with('invoice.client')->latest('id')->limit(15)->get(),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'package' => ['nullable', 'string', 'max:20000'],
        ]);

        $values = [
            'mypos.enabled' => $request->boolean('enabled'),
            'mypos.test' => $request->boolean('test'),
        ];

        if (filled($data['package'] ?? null)) {
            if (! MyposGateway::parsePackage($data['package'])) {
                throw ValidationException::withMessages(['package' => 'Pack de configuration illisible : copiez-le en entier depuis votre compte myPOS (Boutique en ligne → Intégration).']);
            }
            $values['mypos.package'] = MyposGateway::encryptPackage($data['package']);
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

<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\OnlinePayment;
use App\Support\Money;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Paiement par carte avec myPOS Checkout (API IPC v1.4) : le client est envoyé
 * sur la page de paiement sécurisée de myPOS, qui prévient ensuite l'application
 * (notification signée) ; le paiement est alors enregistré automatiquement.
 */
class MyposGateway
{
    public const URL = 'https://www.mypos.com/vmp/checkout';

    public const TEST_URL = 'https://www.mypos.com/vmp/checkout-test';

    /** Accès public de test fourni par myPOS (aucun argent réel). */
    public const TEST_PACKAGE = 'eyJzaWQiOiIwMDAwMDAwMDAwMDAwMTAiLCJjbiI6IjYxOTM4MTY2NjEwIiwicGsiOiItLS0tLUJFR0lOIFJTQSBQUklWQVRFIEtFWS0tLS0tXHJcbk1JSUNYQUlCQUFLQmdRQ2YwVGRjVHVwaGI3WCtad2VrdDFYS0VXWkRjelNHZWNmbzZ2UWZxdnJhZjVWUHpjbkpcclxuMk1jNUo3MkhCbTB1OThFSkhhbitubGUyV09aTVZHSXRUYVwvMmsxRlJXd2J0N2lRNWR6RGg1UEVlWkFTZzJVV2VcclxuaG9SOEw4TXBOQnFINmg3WklUd1ZUZlJTNExzQnZsRWZUN1B6aG01WUpLZk0rQ2R6RE0rTDlXVkVHd0lEQVFBQlxyXG5Bb0dBWWZLeHdVdEVicTh1bFZyRDNubldoRitoazFrNktlamRVcTBkTFlOMjl3OFdqYkNNS2I5SWFva21xV2lRXHJcbjVpWkdFcll4aDdHNEJEUDhBV1wvK001SFhNNG9xbTVTRWtheGhiVGxna3MrRTFzOWRUcGRGUXZMNzZUdm9kcVN5XHJcbmwyRTJCZ2hWZ0xMZ2tkaFJuOWJ1YUZ6WXRhOTVKS2ZneUtHb25OeHNRQTM5UHdFQ1FRREtiRzBLcDZLRWtOZ0Jcclxuc3JDcTNDeDJvZDVPZmlQREc4ZzNSWVpLeFwvTzlkTXk1Q00xNjBEd3VzVkpwdXl3YnBSaGNXcjNna3owUWdSTWRcclxuSVJWd3l4TmJBa0VBeWgzc2lwbWNnTjdTRDh4QkdcL010QllQcVdQMXZ4aFNWWVBmSnp1UFUzZ1M1TVJKelFIQnpcclxuc1ZDTGhUQlk3aEhTb3FpcWxxV1lhc2k4MUp6QkV3RXVRUUpCQUt3OXFHY1pqeU1IOEpVNVREU0dsbHIzanlieFxyXG5GRk1QajhUZ0pzMzQ2QUI4b3pxTExcL1RodldQcHhIdHRKYkg4UUFkTnV5V2RnNmRJZlZBYTk1aDdZK01DUUVaZ1xyXG5qUkRsMUJ6N2VXR08yYzBGcTlPVHozSVZMV3BubUd3ZlcrSHlheGl6eEZoVitGT2oxR1VWaXI5aHlsVjdWMERVXHJcblFqSWFqeXZcL29lRFdoRlE5d1FFQ1FDeWRoSjZOYU5RT0NaaCs2UVRySDNUQzVNZUJBMVllaXBvZTcrQmhzTE5yXHJcbmNGRzhzOXNUeFJubHRjWmwxZFhhQlNlbXZwTnZCaXpuMEt6aThHM1pBZ2M9XHJcbi0tLS0tRU5EIFJTQSBQUklWQVRFIEtFWS0tLS0tIiwicGMiOiItLS0tLUJFR0lOIENFUlRJRklDQVRFLS0tLS1cclxuTUlJQnNUQ0NBUm9DQ1FDQ1BqTnR0R05RV0RBTkJna3Foa2lHOXcwQkFRc0ZBREFkTVFzd0NRWURWUVFHRXdKQ1xyXG5SekVPTUF3R0ExVUVDZ3dGYlhsUVQxTXdIaGNOTVRneE1ERXlNRGN3T1RFeldoY05Namd4TURBNU1EY3dPVEV6XHJcbldqQWRNUXN3Q1FZRFZRUUdFd0pDUnpFT01Bd0dBMVVFQ2d3RmJYbFFUMU13Z1o4d0RRWUpLb1pJaHZjTkFRRUJcclxuQlFBRGdZMEFNSUdKQW9HQkFNTCtWVG1pWTR5Q2hvT1RNWlRYQUlHXC9tayt4ZlwvOW1qd0h4V3p4dEJKYk5uY05LXHJcbjBPTEkwVlhZS1cyR2dWa2xHSEhRanZldzFoVEZrRUdqbkNKN2Y1Q0RuYmd4ZXZ0eUFTREdzdDkyYTZ4Y0FlZEVcclxuYWRQMG5GWGhVeitjWVlJZ0ljZ2ZEY1gzWldlTkVGNWtzY3F5NTJrcEQyTzduRk5DVis4NXZTNGR1SkJOQWdNQlxyXG5BQUV3RFFZSktvWklodmNOQVFFTEJRQURnWUVBQ2oweGIrdE5ZRVJKa0wrcCt6RGNCc0JLNFJ2a25QbHBrK1lQXHJcbmVwaHVuRzJkQkdPbWdcL1dLZ29EMVBMV0QyYkVmR2dKeFlCSWc5cjF3TFlwREMxdHhoeFYrMk9CUVM4NktVTGgwXHJcbk5FY3IwcUVZMDVtSTRGbEUrRFwvQnBUXC8rV0Z5S2tadWc5MnJLMEZsejcxWHlcLzltQlhiUWZtK1lLNmw5cm9SWWRcclxuSjRzSGVRYz1cclxuLS0tLS1FTkQgQ0VSVElGSUNBVEUtLS0tLSIsImlkeCI6MX0=';

    public function __construct(private readonly Settings $settings) {}

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('mypos.enabled') && $this->credentials() !== null;
    }

    public function isTest(): bool
    {
        return (bool) $this->settings->get('mypos.test', true);
    }

    /**
     * Identifiants myPOS : « pack de configuration » (base64 d'un JSON sid, cn, pk, pc, idx)
     * enregistré chiffré. En mode test sans pack, l'accès de test public de myPOS.
     *
     * @return array{sid: string, wallet: string, private_key: string, certificate: string, key_index: int}|null
     */
    public function credentials(): ?array
    {
        $package = null;
        if ($stored = $this->settings->get('mypos.package')) {
            try {
                $package = Crypt::decryptString($stored);
            } catch (Throwable) {
                $package = null;
            }
        }
        if (! $package && $this->isTest()) {
            $package = self::TEST_PACKAGE;
        }

        return $package ? self::parsePackage($package) : null;
    }

    /** @return array{sid: string, wallet: string, private_key: string, certificate: string, key_index: int}|null */
    public static function parsePackage(string $package): ?array
    {
        $data = json_decode((string) base64_decode(preg_replace('/\s+/', '', $package), true), true);
        if (! is_array($data) || empty($data['sid']) || empty($data['cn']) || empty($data['pk']) || empty($data['pc']) || ! isset($data['idx'])) {
            return null;
        }
        if (! openssl_pkey_get_private($data['pk']) || ! openssl_pkey_get_public($data['pc'])) {
            return null;
        }

        return [
            'sid' => (string) $data['sid'],
            'wallet' => (string) $data['cn'],
            'private_key' => (string) $data['pk'],
            'certificate' => (string) $data['pc'],
            'key_index' => (int) $data['idx'],
        ];
    }

    public static function encryptPackage(string $package): string
    {
        return Crypt::encryptString(preg_replace('/\s+/', '', $package));
    }

    /** Prépare une tentative de paiement du reste à payer de la facture. */
    public function start(Invoice $invoice): OnlinePayment
    {
        if (! $this->isEnabled() || ! $invoice->acceptsPayments() || $invoice->balance() <= 0) {
            throw new InvalidArgumentException('Paiement par carte indisponible pour cette facture.');
        }

        return OnlinePayment::query()->create([
            'invoice_id' => $invoice->id,
            // Un identifiant différent à chaque tentative (exigé par myPOS).
            'order_id' => $invoice->number.'-'.Str::upper(Str::random(6)),
            'amount' => $invoice->balance(),
            'status' => 'pending',
            'test' => $this->isTest(),
        ]);
    }

    /**
     * Formulaire signé à envoyer à myPOS (IPCPurchase).
     *
     * @return array{action: string, fields: array<string, string>}
     */
    public function purchaseForm(OnlinePayment $attempt, string $okUrl, string $cancelUrl, string $notifyUrl): array
    {
        $cred = $this->credentials();
        $invoice = $attempt->invoice;
        $amount = number_format($attempt->amount / 100, 2, '.', '');

        $fields = [
            'IPCmethod' => 'IPCPurchase',
            'IPCVersion' => '1.4',
            'IPCLanguage' => 'FR',
            'SID' => $cred['sid'],
            'WalletNumber' => $cred['wallet'],
            'KeyIndex' => (string) $cred['key_index'],
            'Source' => 'MattsCouverture',
            'Currency' => 'EUR',
            'Amount' => $amount,
            'OrderID' => $attempt->order_id,
            'URL_OK' => $okUrl,
            'URL_Cancel' => $cancelUrl,
            'URL_Notify' => $notifyUrl,
            'Note' => mb_substr($invoice->kindLabel().' '.$invoice->number, 0, 100),
            'customeremail' => (string) $invoice->client?->email,
            'CartItems' => '1',
            'Article_1' => mb_substr($invoice->kindLabel().' '.$invoice->number, 0, 100),
            'Quantity_1' => '1',
            'Price_1' => $amount,
            'Amount_1' => $amount,
            'Currency_1' => 'EUR',
            'CardTokenRequest' => '0',
            'PaymentParametersRequired' => '3',
        ];
        $fields['Signature'] = $this->sign($fields, $cred['private_key']);

        return ['action' => $attempt->test ? self::TEST_URL : self::URL, 'fields' => $fields];
    }

    /** Signature myPOS : RSA-SHA256 du base64 des valeurs jointes par « - ». */
    public function sign(array $fields, string $privateKey): string
    {
        openssl_sign(base64_encode(implode('-', $fields)), $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /** Vérifie un message signé par myPOS (notification, retour navigateur). */
    public function verify(array $post): bool
    {
        $cred = $this->credentials();
        $signature = null;
        foreach ($post as $key => $value) {
            if (strtolower((string) $key) === 'signature') {
                $signature = $value;
                unset($post[$key]);
            }
        }
        if (! $cred || ! is_string($signature) || $signature === '') {
            return false;
        }
        $values = [];
        array_walk_recursive($post, function ($value) use (&$values) {
            $values[] = (string) $value;
        });

        return openssl_verify(base64_encode(implode('-', $values)), (string) base64_decode($signature), $cred['certificate'], OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Notification de paiement réussi (IPCPurchaseNotify) : enregistre le paiement.
     * Renvoie la tentative mise à jour, ou null si le message est refusé.
     */
    public function handleNotify(array $post): ?OnlinePayment
    {
        if (($post['IPCmethod'] ?? null) !== 'IPCPurchaseNotify' || ! $this->verify($post)) {
            Log::warning('Notification myPOS refusée', ['method' => $post['IPCmethod'] ?? null, 'order' => $post['OrderID'] ?? null]);

            return null;
        }

        return DB::transaction(function () use ($post) {
            $attempt = OnlinePayment::query()->where('order_id', (string) ($post['OrderID'] ?? ''))->lockForUpdate()->first();
            if (! $attempt) {
                return null;
            }
            // Déjà traitée (myPOS peut renvoyer la même notification).
            if ($attempt->status === 'paid') {
                return $attempt;
            }

            $amount = (int) round(((float) ($post['Amount'] ?? 0)) * 100);
            $attempt->forceFill(['status' => 'paid', 'transaction_ref' => mb_substr((string) ($post['IPC_Trnref'] ?? ''), 0, 80) ?: null])->save();

            $invoice = $attempt->invoice;
            try {
                $payment = app(PaymentService::class)->record($invoice, [
                    'paid_at' => today()->toDateString(),
                    'amount' => $amount,
                    'method' => 'mypos',
                    'method_detail' => 'Carte en ligne',
                    'reference' => $attempt->transaction_ref ?? $attempt->order_id,
                    'notes' => $attempt->test ? 'Paiement de TEST myPOS (aucun argent réel)' : 'Payé en ligne par le client',
                ]);
                $attempt->forceFill(['payment_id' => $payment->id])->save();
                $message = "{$invoice->client?->displayName()} a payé ".Money::plain($amount)." par carte en ligne pour la facture {$invoice->number}. Le paiement est enregistré.";
            } catch (InvalidArgumentException $e) {
                // Facture déjà réglée ou montant supérieur au reste : à vérifier à la main.
                $attempt->forceFill(['error' => mb_substr($e->getMessage(), 0, 255)])->save();
                $message = 'Paiement par carte de '.Money::plain($amount)." reçu pour la facture {$invoice->number}, mais il n'a pas pu être enregistré ({$e->getMessage()}). Vérifiez dans myPOS.";
            }

            app(ClientLinkService::class)->notify(($attempt->test ? '[TEST] ' : '')."Paiement reçu : {$invoice->number}", $message, $invoice);

            return $attempt;
        });
    }
}

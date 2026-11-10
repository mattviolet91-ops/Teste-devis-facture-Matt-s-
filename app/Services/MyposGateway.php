<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\OnlinePayment;
use App\Models\PaymentRequest;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
     * Identifiants utilisés : en mode test, l'accès de test public de myPOS (le serveur
     * de test ne connaît pas les vraies boutiques) ; sinon le pack de la boutique.
     *
     * @return array{sid: string, wallet: string, private_key: string, certificate: string, key_index: int}|null
     */
    public function credentials(): ?array
    {
        return $this->isTest() ? self::parsePackage(self::TEST_PACKAGE) : $this->storedCredentials();
    }

    /**
     * « Pack de configuration » de la boutique (base64 d'un JSON sid, cn, pk, pc, idx), enregistré chiffré.
     *
     * @return array{sid: string, wallet: string, private_key: string, certificate: string, key_index: int}|null
     */
    public function storedCredentials(): ?array
    {
        $stored = $this->settings->get('mypos.package');
        if (! $stored) {
            return null;
        }
        try {
            return self::parsePackage(Crypt::decryptString($stored));
        } catch (Throwable) {
            return null;
        }
    }

    /** Mode test : le bouton n'est montré qu'avec le lien d'essai, jamais aux clients. */
    public function visibleTo(Request $request): bool
    {
        return $this->isEnabled() && (! $this->isTest() || $request->boolean('essai'));
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

    /** Montant minimum d'un paiement partiel par carte (centimes). */
    public const MIN_PARTIAL = 100;

    /** Prépare une tentative de paiement du reste à payer de la facture (ou d'une partie). */
    public function start(Invoice $invoice, ?int $amount = null): OnlinePayment
    {
        if (! $this->isEnabled() || ! $invoice->acceptsPayments() || $invoice->balance() <= 0) {
            throw new InvalidArgumentException('Paiement par carte indisponible pour cette facture.');
        }
        $amount ??= $invoice->balance();
        if ($amount > $invoice->balance() || ($amount < $invoice->balance() && $amount < self::MIN_PARTIAL)) {
            throw new InvalidArgumentException('Montant invalide.');
        }

        return OnlinePayment::query()->create([
            'invoice_id' => $invoice->id,
            // Un identifiant différent à chaque tentative (exigé par myPOS).
            'order_id' => $invoice->number.'-'.Str::upper(Str::random(6)),
            'amount' => $amount,
            'status' => 'pending',
            'test' => $this->isTest(),
        ]);
    }

    /** Adresse du lien permanent « montant libre » (code secret créé à la première demande). */
    public function freeLinkUrl(): string
    {
        $code = (string) $this->settings->get('mypos.free_code');
        if (! preg_match('/^[A-Za-z0-9]{16,40}$/', $code)) {
            $code = Str::random(24);
            $this->settings->set(['mypos.free_code' => $code]);
        }

        return rtrim((string) config('entreprise.client_url'), '/').'/payer/'.$code;
    }

    public function freeLinkEnabled(): bool
    {
        return (bool) $this->settings->get('mypos.free_link', true);
    }

    /** @return array{0: int, 1: int} montants minimum et maximum du lien permanent (centimes) */
    public function freeLinkBounds(): array
    {
        $min = max(100, (int) $this->settings->get('mypos.free_min', 100));

        return [$min, max($min, (int) $this->settings->get('mypos.free_max', 300000))];
    }

    /** Tentative de paiement d'un montant libre (demande de paiement). */
    public function startRequest(PaymentRequest $request): OnlinePayment
    {
        if (! $this->isEnabled() || ! $request->isPending()) {
            throw new InvalidArgumentException('Paiement par carte indisponible.');
        }

        $attempt = new OnlinePayment([
            'order_id' => 'P'.$request->id.'-'.Str::upper(Str::random(8)),
            'amount' => $request->amount,
            'status' => 'pending',
            'test' => $this->isTest(),
        ]);
        $attempt->payment_request_id = $request->id;
        $attempt->save();

        return $attempt;
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
        $request = $attempt->paymentRequest;
        $amount = number_format($attempt->amount / 100, 2, '.', '');
        // Texte affiché sur la page myPOS : sans accent ni caractère spécial (aucun risque d'encodage sur la signature).
        $note = trim(mb_substr(preg_replace('/[^A-Za-z0-9 .,()\/-]+/', ' ', Str::ascii($invoice ? $invoice->kindLabel().' '.$invoice->number : $request->label)), 0, 100)) ?: 'Paiement';
        $email = (string) (($invoice ?? $request)->client?->email ?? $request?->payer_email);

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
            'Note' => $note,
            'customeremail' => $email,
            'CartItems' => '1',
            'Article_1' => $note,
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

    /**
     * Vérifie le pack enregistré auprès de myPOS, sans paiement : demande signée de statut
     * d'une commande fictive (IPCGetTxnStatus) sur le serveur réel.
     * Codes myPOS : 2 = signature refusée, 4 = boutique (SID) inconnue, 1 = paramètre manquant.
     *
     * @return array{ok: bool, status: ?int, message: string}
     */
    public function checkConnection(): array
    {
        $cred = $this->storedCredentials();
        if (! $cred) {
            return ['ok' => false, 'status' => null, 'message' => 'Aucun pack de configuration enregistré : collez-le ci-dessus puis enregistrez.'];
        }

        $fields = [
            'IPCmethod' => 'IPCGetTxnStatus',
            'IPCVersion' => '1.4',
            'IPCLanguage' => 'FR',
            'SID' => $cred['sid'],
            'WalletNumber' => $cred['wallet'],
            'KeyIndex' => (string) $cred['key_index'],
            'Source' => 'MattsCouverture',
            'OrderID' => 'TEST-CONNEXION-'.Str::upper(Str::random(8)),
            'OutputFormat' => 'json',
        ];
        $fields['Signature'] = $this->sign($fields, $cred['private_key']);

        try {
            $response = Http::asForm()->timeout(20)->post(self::URL, $fields);
        } catch (Throwable $e) {
            Log::warning('Test de connexion myPOS impossible', ['error' => $e->getMessage()]);

            return ['ok' => false, 'status' => null, 'message' => 'myPOS ne répond pas pour le moment (réseau). Réessayez dans quelques minutes.'];
        }

        $body = (string) $response->body();
        $json = json_decode($body, true);
        $status = null;
        if (is_array($json)) {
            $flat = array_change_key_case(isset($json['IPC']) && is_array($json['IPC']) ? $json['IPC'] : $json);
            $status = isset($flat['status']) && is_numeric($flat['status']) ? (int) $flat['status'] : null;
        } elseif (preg_match('#<Status>\s*(\d+)\s*</Status>#i', $body, $m)) {
            $status = (int) $m[1];
        }
        Log::info('Test de connexion myPOS', ['http' => $response->status(), 'status' => $status, 'sid' => $cred['sid'], 'key_index' => $cred['key_index']]);

        $boutique = "boutique {$cred['sid']}, clé n° {$cred['key_index']}";

        return match (true) {
            $status === null => ['ok' => false, 'status' => null, 'message' => "Réponse inattendue de myPOS (code HTTP {$response->status()}). Réessayez plus tard."],
            $status === 2 => ['ok' => false, 'status' => 2, 'message' => "Signature refusée par myPOS ($boutique) : le pack enregistré ne correspond pas aux clés de la boutique. Générez un nouveau « Configuration pack » dans myPOS (Boutiques en ligne → votre boutique → Intégration), collez-le ici, enregistrez et refaites le test."],
            $status === 4 => ['ok' => false, 'status' => 4, 'message' => "Boutique inconnue chez myPOS ($boutique) : le pack ne correspond pas à une boutique en ligne active."],
            $status === 1 => ['ok' => false, 'status' => 1, 'message' => 'myPOS signale un paramètre manquant (code 1). Transmettez-moi ce message.'],
            default => ['ok' => true, 'status' => $status, 'message' => "Connexion réussie : myPOS accepte la signature de votre pack ($boutique). Code de réponse myPOS : $status (normal pour une commande de test qui n'existe pas)."],
        };
    }

    /** Facture envoyée par email au client après son paiement (un échec d'envoi ne remet pas le paiement en cause). */
    private function sendReceipt(Invoice $invoice, int $amount): void
    {
        try {
            app(LinkMailer::class)->sendReceipt($invoice, $amount);
        } catch (Throwable $e) {
            Log::warning('Facture non envoyée après paiement', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);
        }
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

            // Montant libre : la facture est créée maintenant.
            if ($attempt->payment_request_id) {
                $request = $attempt->paymentRequest;
                if ($attempt->test) {
                    $message = 'Essai réussi : myPOS a bien confirmé un paiement de test de '.Money::plain($amount)." pour « {$request->label} ». Aucune facture n'a été créée.";
                } else {
                    try {
                        $message = app(PaymentRequestService::class)->settle($attempt, $amount);
                        if ($paid = $attempt->fresh()->invoice) {
                            $this->sendReceipt($paid, $amount);
                        }
                    } catch (Throwable $e) {
                        Log::error('Montant libre payé mais non enregistré', ['order' => $attempt->order_id, 'error' => $e->getMessage()]);
                        $attempt->forceFill(['error' => mb_substr($e->getMessage(), 0, 255)])->save();
                        $message = 'Paiement par carte de '.Money::plain($amount)." reçu pour « {$request->label} », mais la facture n'a pas pu être créée. Vérifiez dans myPOS et créez-la à la main.";
                    }
                }
                app(ClientLinkService::class)->notifyText(
                    ($attempt->test ? '[TEST] ' : '').'Paiement reçu : '.Money::plain($amount),
                    $message,
                    // Lien permanent payé en mode test : aucune fiche client, donc pas de page à ouvrir.
                    $request->fresh()->client_id ? ClientLinkService::adminUrl('payments.requests.show', $request) : ClientLinkService::adminUrl('payments.index'),
                );

                return $attempt;
            }

            $invoice = $attempt->invoice;

            // Essai : rien n'est enregistré sur la vraie facture.
            if ($attempt->test) {
                app(ClientLinkService::class)->notify("[TEST] Paiement par carte réussi : {$invoice->number}",
                    'Essai réussi : myPOS a bien confirmé un paiement de test de '.Money::plain($amount).". Rien n'a été enregistré sur la facture. Vous pouvez passer aux vrais paiements.", $invoice);

                return $attempt;
            }

            try {
                $payment = app(PaymentService::class)->record($invoice, [
                    'paid_at' => today()->toDateString(),
                    'amount' => $amount,
                    'method' => 'mypos',
                    'method_detail' => 'Carte en ligne',
                    'reference' => $attempt->transaction_ref ?? $attempt->order_id,
                    'notes' => 'Payé en ligne par le client',
                ]);
                $attempt->forceFill(['payment_id' => $payment->id])->save();
                $message = "{$invoice->client?->displayName()} a payé ".Money::plain($amount)." par carte en ligne pour la facture {$invoice->number}. Le paiement est enregistré.";
                $receiptFor = $invoice;
            } catch (InvalidArgumentException $e) {
                // Facture déjà réglée ou montant supérieur au reste : à vérifier à la main.
                $attempt->forceFill(['error' => mb_substr($e->getMessage(), 0, 255)])->save();
                $message = 'Paiement par carte de '.Money::plain($amount)." reçu pour la facture {$invoice->number}, mais il n'a pas pu être enregistré ({$e->getMessage()}). Vérifiez dans myPOS.";
            }

            app(ClientLinkService::class)->notify("Paiement reçu : {$invoice->number}", $message, $invoice);
            if (isset($receiptFor)) {
                $this->sendReceipt($receiptFor, $amount);
            }

            return $attempt;
        });
    }
}

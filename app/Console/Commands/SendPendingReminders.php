<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\PaymentRequest;
use App\Services\LinkMailer;
use App\Services\PushService;
use App\Services\Settings;
use Illuminate\Console\Command;

/**
 * Relance automatique (si activée dans Réglages → Emails) des documents à signer et des
 * liens de paiement restés sans suite : un email X jours après la demande, un second
 * X jours plus tard, jamais plus. Seulement pour les clients qui ont une adresse email.
 */
class SendPendingReminders extends Command
{
    public const MAX = 2;

    protected $signature = 'app:pending-reminders';

    protected $description = 'Relance par email les documents à signer et les liens de paiement en attente';

    public function handle(Settings $settings, LinkMailer $mailer, PushService $push): int
    {
        $config = $settings->group('reminders');
        $days = (int) ($config['pending_days'] ?? 0);
        if (empty($config['pending_auto']) || $days < 1) {
            $this->info('Relance des documents et liens de paiement désactivée.');

            return self::SUCCESS;
        }
        if (! $mailer->canSend()) {
            $this->warn('Envoi des emails non configuré.');

            return self::SUCCESS;
        }

        $limit = now()->subDays($days);
        $sent = [];

        $documents = Attachment::query()->whereNotNull('signature_requested_at')->whereNotNull('public_token')->whereNull('signed_at')
            ->where('reminder_count', '<', self::MAX)->whereHas('client', fn ($q) => $q->whereNull('deleted_at'))->with('client')->get()
            ->filter(fn (Attachment $a) => ($a->reminded_at ?? $a->signature_requested_at)->lte($limit) && $a->client->email);
        foreach ($documents as $attachment) {
            if ($mailer->sendSignatureLink($attachment, $attachment->client->email, null, true)->isSent()) {
                $attachment->forceFill(['reminded_at' => now(), 'reminder_count' => $attachment->reminder_count + 1])->save();
                $sent[] = "document « {$attachment->name} » ({$attachment->client->displayName()})";
            }
        }

        $requests = PaymentRequest::query()->where('status', 'pending')->where('reminder_count', '<', self::MAX)
            ->whereHas('client', fn ($q) => $q->whereNull('deleted_at'))->with('client')->get()
            ->filter(fn (PaymentRequest $r) => ($r->reminded_at ?? $r->created_at)->lte($limit) && $r->client->email);
        foreach ($requests as $request) {
            if ($mailer->sendPaymentLink($request, $request->client->email, null, true)->isSent()) {
                $request->forceFill(['reminded_at' => now(), 'reminder_count' => $request->reminder_count + 1])->save();
                $sent[] = "paiement « {$request->label} » ({$request->client->displayName()})";
            }
        }

        if ($sent) {
            $push->send('Relances envoyées', count($sent).' relance(s) : '.implode(', ', array_slice($sent, 0, 3)).(count($sent) > 3 ? '…' : ''), url('/'));
        }
        $this->info(count($sent).' relance(s) envoyée(s).');

        return self::SUCCESS;
    }
}

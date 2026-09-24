<?php

namespace App\Console\Commands;

use App\Models\ReviewRequest;
use App\Services\MailSettings;
use App\Services\PushService;
use App\Services\ReviewService;
use Illuminate\Console\Command;

/** Chaque jour : demande d'avis Google aux clients dont le chantier est payé. */
class ReviewRequests extends Command
{
    protected $signature = 'app:review-requests';

    protected $description = 'Demande un avis Google aux clients après un chantier payé';

    public function handle(ReviewService $reviews, MailSettings $mail, PushService $push): int
    {
        $sent = 0;
        $manual = collect();

        foreach ($reviews->due() as $invoice) {
            $request = ReviewRequest::query()->create(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'status' => 'pending']);

            if ($invoice->client->email && $mail->isConfigured() && $reviews->sendEmail($request)) {
                $sent++;
            } else {
                $manual->push($request);
            }
        }

        if ($manual->count() === 1) {
            $request = $manual->first();
            $push->send('Avis Google à demander : '.$request->client->displayName(), 'Pas d\'email : appuyez pour lui envoyer le lien par SMS ou WhatsApp.', route('reviews.show', $request));
        } elseif ($manual->count() > 1) {
            $push->send($manual->count().' avis Google à demander', 'Clients sans email : envoyez le lien par SMS ou WhatsApp.', route('reviews.index'));
        }

        $this->info("$sent demande(s) envoyée(s) par email, {$manual->count()} à envoyer à la main.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\MaintenanceReminder;
use App\Services\PushService;
use App\Services\Settings;
use Illuminate\Console\Command;

/** Chaque matin : notification des entretiens qui arrivent à échéance aujourd'hui. */
class MaintenanceNotifications extends Command
{
    protected $signature = 'app:maintenance-notifications';

    protected $description = 'Prévient des clients à recontacter pour un entretien';

    public function handle(Settings $settings, PushService $push): int
    {
        if (! $settings->get('reminders.notify_enabled', true)) {
            return self::SUCCESS;
        }

        $reminders = MaintenanceReminder::query()->where('status', 'pending')
            ->whereDate('due_on', today())->whereHas('client')->with('client')->get();

        if ($reminders->count() === 1) {
            $reminder = $reminders->first();
            $push->send('Entretien à proposer : '.$reminder->client->displayName(),
                "{$reminder->label} fait il y a {$reminder->age()}. Appuyez pour lui envoyer un message.",
                route('maintenance.show', $reminder));
        } elseif ($reminders->count() > 1) {
            $push->send($reminders->count().' entretiens à proposer',
                $reminders->take(3)->map(fn ($r) => $r->client->displayName())->implode(', ').($reminders->count() > 3 ? '…' : ''),
                route('maintenance.index'));
        }

        $this->info($reminders->count().' entretien(s) signalé(s).');

        return self::SUCCESS;
    }
}

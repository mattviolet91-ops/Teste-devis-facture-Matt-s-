<?php

namespace App\Console\Commands;

use App\Models\Intervention;
use App\Services\EmailService;
use App\Services\MailSettings;
use App\Services\PlanningMessages;
use App\Services\PushService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Rappels du planning : la veille au soir (rendez-vous et chantiers du lendemain),
 * et 1 heure avant chaque rendez-vous (option --bientot, lancée toutes les 5 minutes).
 */
class PlanningNotifications extends Command
{
    protected $signature = 'app:planning-notifications {--bientot : Rendez-vous qui commencent dans l\'heure} {--rappels : Rappels 1 ou 2 jours avant}';

    protected $description = 'Rappelle les rendez-vous et chantiers prévus';

    public function handle(PushService $push): int
    {
        return match (true) {
            (bool) $this->option('bientot') => $this->soon($push),
            (bool) $this->option('rappels') => $this->reminders($push, app(MailSettings::class)),
            default => $this->tomorrow($push),
        };
    }

    private function tomorrow(PushService $push): int
    {
        $tomorrow = today()->addDay();
        $items = Intervention::query()->where('status', 'planned')->whereDate('starts_on', $tomorrow)
            ->visible()->with(['client', 'worksite'])->orderBy('start_time')->get();

        if ($items->isNotEmpty()) {
            $rdv = $items->filter->isAppointment()->count();
            $work = $items->count() - $rdv;
            $push->send(
                'Demain : '.collect([
                    $rdv ? $rdv.' rendez-vous' : null,
                    $work ? $work.' chantier'.($work > 1 ? 's' : '') : null,
                ])->filter()->implode(' et '),
                $items->map(fn (Intervention $i) => $this->summary($i))->implode(' · '),
                route('planning.index', ['date' => $tomorrow->toDateString()]),
            );
        }

        $this->info($items->count().' élément(s) demain.');

        return self::SUCCESS;
    }

    private function soon(PushService $push): int
    {
        $now = now();
        $items = Intervention::query()->where('kind', 'rdv')->where('status', 'planned')->whereNull('reminded_at')
            ->whereDate('starts_on', today())->whereNotNull('start_time')->visible()->with(['client', 'worksite'])->get()
            ->filter(function (Intervention $i) use ($now) {
                $start = Carbon::parse($i->starts_on->toDateString().' '.$i->start_time);

                return $start->gt($now) && $start->lte($now->copy()->addHour());
            });

        foreach ($items as $item) {
            $push->send('Rendez-vous à '.$item->timeLabel().' : '.$item->heading(),
                $item->title.($item->address() ? ' — '.$item->address() : ''),
                route('planning.show', $item));
            $item->forceFill(['reminded_at' => now()])->save();
        }

        $this->info($items->count().' rappel(s) envoyé(s).');

        return self::SUCCESS;
    }

    /** Chaque matin : rappel 1 ou 2 jours avant (vous, et le client par email si demandé). */
    private function reminders(PushService $push, MailSettings $mail): int
    {
        $items = Intervention::query()->where('status', 'planned')->whereNull('reminder_sent_at')
            ->whereIn('remind_days', [1, 2])->whereDate('starts_on', '>', today())->whereDate('starts_on', '<=', today()->addDays(2))
            ->visible()->with(['client', 'worksite', 'quote'])->get()
            ->filter(fn (Intervention $i) => $i->starts_on->isSameDay(today()->addDays($i->remind_days)));

        $messages = app(PlanningMessages::class);
        foreach ($items as $item) {
            $when = $item->remind_days === 1 ? 'Demain' : 'Dans 2 jours';
            $clientMail = $item->remind_client && $item->client?->email && $mail->isConfigured();

            if ($clientMail) {
                $log = app(EmailService::class)->send($item->client, null, [$item->client->email], [],
                    $messages->reminderSubject($item), $messages->reminder($item), false);
                $clientMail = $log->isSent();
            }

            $push->send(
                $when.' : '.($item->isAppointment() ? 'RDV ' : '').$item->heading(),
                trim(($item->start_time ? $item->timeLabel().' · ' : '').$item->title
                    .($item->address() ? ' — '.$item->address() : '')
                    .($clientMail ? ' Rappel envoyé au client par email.' : ($item->client ? ' Appuyez pour prévenir le client.' : ''))),
                route('planning.show', $item),
            );
            $item->forceFill(['reminder_sent_at' => now()])->save();
        }

        $this->info($items->count().' rappel(s).');

        return self::SUCCESS;
    }

    private function summary(Intervention $i): string
    {
        $city = $i->location ?: ($i->worksite?->city ?? $i->client?->city);

        return ($i->start_time ? $i->timeLabel().' ' : '').($i->isAppointment() ? 'RDV ' : '').$i->heading().($city ? ' ('.$city.')' : '');
    }
}

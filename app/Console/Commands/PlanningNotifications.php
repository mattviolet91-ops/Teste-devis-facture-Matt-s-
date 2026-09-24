<?php

namespace App\Console\Commands;

use App\Models\Intervention;
use App\Services\PushService;
use Illuminate\Console\Command;

/** La veille au soir : rappel des interventions du lendemain. */
class PlanningNotifications extends Command
{
    protected $signature = 'app:planning-notifications';

    protected $description = 'Rappelle les interventions prévues le lendemain';

    public function handle(PushService $push): int
    {
        $tomorrow = today()->addDay();
        $items = Intervention::query()->where('status', 'planned')->whereDate('starts_on', $tomorrow)
            ->whereHas('client')->with(['client', 'worksite'])->orderBy('start_time')->get();

        if ($items->isNotEmpty()) {
            $push->send(
                'Demain : '.$items->count().' intervention'.($items->count() > 1 ? 's' : ''),
                $items->map(fn (Intervention $i) => ($i->start_time ? $i->timeLabel().' ' : '').$i->client->displayName()
                    .(($city = $i->worksite?->city ?? $i->client->city) ? ' ('.$city.')' : ''))->implode(' · '),
                route('planning.index', ['date' => $tomorrow->toDateString()]),
            );
        }

        $this->info($items->count().' intervention(s) demain.');

        return self::SUCCESS;
    }
}

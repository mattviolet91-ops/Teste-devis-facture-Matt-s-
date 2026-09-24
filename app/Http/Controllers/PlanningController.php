<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Intervention;
use App\Models\Quote;
use App\Services\ActivityLogger;
use App\Services\PlanningMessages;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Planning des chantiers et rendez-vous : semaine par semaine, avec les devis acceptés à planifier. */
class PlanningController extends Controller
{
    public function index(Request $request): View
    {
        $mode = $request->query('vue') === 'mois' ? 'mois' : 'semaine';
        try {
            $date = Carbon::parse((string) $request->query('date', today()->toDateString()))->startOfDay();
        } catch (\Throwable) {
            $date = today();
        }

        [$from, $to] = $mode === 'mois'
            ? [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()->startOfDay()]
            : [$date->copy()->startOfWeek(), $date->copy()->endOfWeek()->startOfDay()];

        $interventions = Intervention::query()->between($from, $to)->visible()->with(['client', 'worksite'])
            ->orderBy('starts_on')->orderBy('start_time')->get();

        $days = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $list = $interventions->filter(fn (Intervention $i) => $day->between($i->starts_on, $i->ends_on))->values();
            if ($mode === 'semaine' || $list->isNotEmpty()) {
                $days[] = ['date' => $day->copy(), 'items' => $list];
            }
        }

        return view('planning.index', [
            'mode' => $mode,
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'previous' => ($mode === 'mois' ? $from->copy()->subMonth() : $from->copy()->subWeek())->toDateString(),
            'next' => ($mode === 'mois' ? $from->copy()->addMonth() : $from->copy()->addWeek())->toDateString(),
            // Devis acceptés sans intervention prévue : à planifier.
            'toPlan' => Quote::query()->where('status', 'accepted')
                ->whereDoesntHave('interventions', fn ($q) => $q->active()->where('kind', 'chantier'))
                ->whereHas('client')->with('client')->latest('accepted_at')->limit(20)->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $appointment = $request->query('type') === 'rdv';
        $intervention = new Intervention([
            'kind' => $appointment ? 'rdv' : 'chantier',
            'starts_on' => today(),
            'start_time' => $appointment ? '09:00' : '08:00',
            'end_time' => $appointment ? '10:00' : null,
            'title' => $appointment ? Intervention::APPOINTMENT_TITLES[0] : null,
            'status' => 'planned',
        ]);

        if ($quote = Quote::query()->find($request->integer('devis'))) {
            $intervention->fill(['client_id' => $quote->client_id, 'worksite_id' => $quote->worksite_id, 'quote_id' => $quote->id]);
            if (! $appointment) {
                $intervention->title = $quote->title ?: 'Travaux devis '.$quote->number;
            }
        } elseif ($client = Client::query()->find($request->integer('client'))) {
            $intervention->client_id = $client->id;
        }
        if ($request->filled('date')) {
            try {
                $intervention->starts_on = Carbon::parse((string) $request->query('date'));
            } catch (\Throwable) {
                // Date ignorée.
            }
        }
        $intervention->ends_on = $intervention->starts_on;

        return view('planning.form', $this->formData($intervention));
    }

    public function store(Request $request): RedirectResponse
    {
        $intervention = Intervention::query()->create($this->validated($request));
        $what = $intervention->isAppointment() ? 'Rendez-vous' : 'Intervention';
        ActivityLogger::log('planning.created', "$what planifié(e) {$intervention->whenLabel()} : {$intervention->title}", $intervention->client);

        return redirect()->route('planning.show', $intervention)
            ->with('status', $what.' ajouté'.($intervention->isAppointment() ? '' : 'e').' au planning.'.($intervention->client ? ' Prévenez le client ci-dessous.' : ''));
    }

    public function show(Intervention $intervention): View
    {
        abort_if($intervention->client_id && ! $intervention->client, 404);
        $intervention->load(['client', 'worksite', 'quote']);

        return view('planning.show', ['intervention' => $intervention, 'message' => $intervention->client ? $this->message($intervention) : null]);
    }

    public function edit(Intervention $intervention): View
    {
        return view('planning.form', $this->formData($intervention));
    }

    public function update(Request $request, Intervention $intervention): RedirectResponse
    {
        $intervention->fill($this->validated($request));
        // Date ou rappel changé : le rappel repartira.
        if ($intervention->isDirty(['starts_on', 'start_time', 'remind_days'])) {
            $intervention->reminder_sent_at = null;
            $intervention->reminded_at = null;
        }
        $intervention->save();

        return redirect()->route('planning.show', $intervention)->with('status', ($intervention->isAppointment() ? 'Rendez-vous' : 'Intervention').' enregistré'.($intervention->isAppointment() ? '' : 'e').'.');
    }

    public function destroy(Intervention $intervention): RedirectResponse
    {
        $date = $intervention->starts_on->toDateString();
        $intervention->delete();

        return redirect()->route('planning.index', ['date' => $date])->with('status', 'Supprimé du planning.');
    }

    /** Fichier agenda (.ics) pour l'ajouter au calendrier du téléphone. */
    public function ics(Intervention $intervention, Settings $settings): Response
    {
        $escape = fn (?string $text) => str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], (string) $text);
        $uid = 'intervention-'.$intervention->id.'@'.parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($intervention->start_time) {
            $start = Carbon::parse($intervention->starts_on->toDateString().' '.$intervention->start_time, config('app.timezone'));
            $dates = 'DTSTART;TZID=Europe/Paris:'.$start->format('Ymd\THis')."\r\n"
                .'DTEND;TZID=Europe/Paris:'.($intervention->end_time
                    ? Carbon::parse($intervention->ends_on->toDateString().' '.$intervention->end_time, config('app.timezone'))
                    : ($intervention->isAppointment() ? $start->copy()->addHour() : $intervention->ends_on->copy()->setTime(17, 0)))->format('Ymd\THis');
        } else {
            $dates = 'DTSTART;VALUE=DATE:'.$intervention->starts_on->format('Ymd')."\r\n"
                .'DTEND;VALUE=DATE:'.$intervention->ends_on->copy()->addDay()->format('Ymd');
        }

        $body = implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//'.$escape($settings->get('company.trade_name')).'//Planning//FR', 'BEGIN:VEVENT',
            'UID:'.$uid, 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'), $dates,
            'SUMMARY:'.$escape($intervention->title.($intervention->client ? ' – '.$intervention->client->displayName() : '')),
            'LOCATION:'.$escape($intervention->address()),
            'DESCRIPTION:'.$escape(trim(($intervention->client?->phone ? 'Tél. '.$intervention->client->phone."\n" : '').$intervention->notes)),
            'END:VEVENT', 'END:VCALENDAR',
        ])."\r\n";

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="intervention-'.$intervention->id.'.ics"',
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(Intervention $intervention): array
    {
        $clients = Client::query()->alphabetical()->with('worksites')->get();

        return [
            'intervention' => $intervention,
            'clients' => $clients,
            'worksites' => $clients->mapWithKeys(fn (Client $c) => [$c->id => $c->worksites->map(fn ($w) => ['id' => $w->id, 'label' => $w->fullAddress()])->values()]),
            'quotes' => Quote::query()->whereIn('status', ['accepted', 'sent'])->whereNotNull('number')->with('client')->latest('id')->limit(100)->get(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        // Rendez-vous : le champ « Fin (si plusieurs jours) », caché, est ignoré.
        // Chantier : une fin antérieure au début (date de début changée après coup) est ramenée au début.
        $starts = $request->input('starts_on');
        if ($request->input('kind') === 'rdv' || ! $request->filled('ends_on')
            || (is_string($starts) && strtotime((string) $request->input('ends_on')) < strtotime($starts))) {
            $request->merge(['ends_on' => null]);
        }

        $data = $request->validate([
            'kind' => ['nullable', Rule::in(array_keys(Intervention::KINDS))],
            // Un chantier est toujours chez un client ; un rendez-vous peut être sans client.
            'client_id' => [Rule::requiredIf($request->input('kind') !== 'rdv'), 'nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'location' => ['nullable', 'string', 'max:200'],
            'worksite_id' => ['nullable', 'integer', Rule::exists('worksites', 'id')->where('client_id', $request->integer('client_id'))],
            'quote_id' => ['nullable', 'integer', Rule::exists('quotes', 'id')->where('client_id', $request->integer('client_id'))],
            'title' => ['required', 'string', 'max:200'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'start_time' => [Rule::requiredIf($request->input('kind') === 'rdv'), 'nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'status' => ['nullable', Rule::in(array_keys(Intervention::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'remind_days' => ['nullable', 'integer', Rule::in(array_keys(Intervention::REMINDERS))],
        ], [
            'ends_on.after_or_equal' => 'La date de fin doit être après la date de début.',
            'quote_id.exists' => 'Ce devis n\'appartient pas à ce client.',
            'worksite_id.exists' => 'Ce chantier n\'appartient pas à ce client.',
            'start_time.required' => 'Indiquez l\'heure du rendez-vous.',
        ], ['client_id' => 'client', 'title' => 'objet', 'starts_on' => 'date', 'start_time' => 'heure', 'end_time' => 'heure de fin']);

        $data['kind'] ??= 'chantier';
        if ($data['kind'] === 'rdv') {
            // Un rendez-vous tient sur une journée ; heure de fin par défaut : 1 heure après.
            $data['ends_on'] = $data['starts_on'];
            if (empty($data['end_time']) || $data['end_time'] <= $data['start_time']) {
                $data['end_time'] = Carbon::createFromFormat('H:i', $data['start_time'])->addHour()->format('H:i');
            }
        } else {
            $data['end_time'] = null;
        }
        $data['ends_on'] = $data['ends_on'] ?? $data['starts_on'];
        $data['remind_days'] = (int) ($data['remind_days'] ?? 1);
        $data['remind_client'] = $request->boolean('remind_client') && ! empty($data['client_id']);
        $data['status'] = $data['status'] ?? 'planned';

        return $data;
    }

    private function message(Intervention $intervention): string
    {
        return app(PlanningMessages::class)->confirmation($intervention);
    }
}

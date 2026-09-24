<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Intervention;
use App\Models\Quote;
use App\Services\ActivityLogger;
use App\Services\EmailComposer;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Planning des chantiers : semaine par semaine, avec les devis acceptés à planifier. */
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

        $interventions = Intervention::query()->between($from, $to)->with(['client', 'worksite'])
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
                ->whereDoesntHave('interventions', fn ($q) => $q->active())
                ->whereHas('client')->with('client')->latest('accepted_at')->limit(20)->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $intervention = new Intervention(['starts_on' => today(), 'start_time' => '08:00', 'status' => 'planned']);

        if ($quote = Quote::query()->find($request->integer('devis'))) {
            $intervention->fill(['client_id' => $quote->client_id, 'worksite_id' => $quote->worksite_id, 'quote_id' => $quote->id, 'title' => $quote->title ?: 'Travaux devis '.$quote->number]);
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
        ActivityLogger::log('planning.created', "Intervention planifiée {$intervention->whenLabel()} : {$intervention->title}", $intervention->client);

        return redirect()->route('planning.show', $intervention)->with('status', 'Intervention planifiée. Prévenez le client ci-dessous.');
    }

    public function show(Intervention $intervention): View
    {
        abort_unless($intervention->client, 404);
        $intervention->load(['client', 'worksite', 'quote']);

        return view('planning.show', ['intervention' => $intervention, 'message' => $this->message($intervention)]);
    }

    public function edit(Intervention $intervention): View
    {
        return view('planning.form', $this->formData($intervention));
    }

    public function update(Request $request, Intervention $intervention): RedirectResponse
    {
        $intervention->update($this->validated($request));

        return redirect()->route('planning.show', $intervention)->with('status', 'Intervention enregistrée.');
    }

    public function destroy(Intervention $intervention): RedirectResponse
    {
        $date = $intervention->starts_on->toDateString();
        $intervention->delete();

        return redirect()->route('planning.index', ['date' => $date])->with('status', 'Intervention supprimée du planning.');
    }

    /** Fichier agenda (.ics) pour l'ajouter au calendrier du téléphone. */
    public function ics(Intervention $intervention, Settings $settings): Response
    {
        $escape = fn (?string $text) => str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], (string) $text);
        $uid = 'intervention-'.$intervention->id.'@'.parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($intervention->start_time) {
            $start = Carbon::parse($intervention->starts_on->toDateString().' '.$intervention->start_time, config('app.timezone'));
            $dates = 'DTSTART;TZID=Europe/Paris:'.$start->format('Ymd\THis')."\r\n"
                .'DTEND;TZID=Europe/Paris:'.$intervention->ends_on->copy()->setTime(17, 0)->format('Ymd\THis');
        } else {
            $dates = 'DTSTART;VALUE=DATE:'.$intervention->starts_on->format('Ymd')."\r\n"
                .'DTEND;VALUE=DATE:'.$intervention->ends_on->copy()->addDay()->format('Ymd');
        }

        $body = implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//'.$escape($settings->get('company.trade_name')).'//Planning//FR', 'BEGIN:VEVENT',
            'UID:'.$uid, 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'), $dates,
            'SUMMARY:'.$escape($intervention->title.' – '.$intervention->client?->displayName()),
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
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'worksite_id' => ['nullable', 'integer', Rule::exists('worksites', 'id')->where('client_id', $request->integer('client_id'))],
            'quote_id' => ['nullable', 'integer', Rule::exists('quotes', 'id')->where('client_id', $request->integer('client_id'))],
            'title' => ['required', 'string', 'max:200'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'status' => ['nullable', Rule::in(array_keys(Intervention::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'ends_on.after_or_equal' => 'La date de fin doit être après la date de début.',
            'quote_id.exists' => 'Ce devis n\'appartient pas à ce client.',
            'worksite_id.exists' => 'Ce chantier n\'appartient pas à ce client.',
        ], ['client_id' => 'client', 'title' => 'intitulé', 'starts_on' => 'date de début']);

        $data['ends_on'] = $data['ends_on'] ?? $data['starts_on'];
        $data['status'] = $data['status'] ?? 'planned';

        return $data;
    }

    private function message(Intervention $intervention): string
    {
        $text = strtr((string) app(Settings::class)->get('mail.intervention'), [
            '{date_intervention}' => ($intervention->days() > 1 ? '' : 'le ').$intervention->whenLabel(),
            '{adresse_chantier}' => (string) $intervention->address(),
        ]);

        return app(EmailComposer::class)->renderText($text, $intervention->client, $intervention->quote);
    }
}

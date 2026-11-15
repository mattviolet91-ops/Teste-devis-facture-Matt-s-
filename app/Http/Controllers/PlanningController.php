<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Intervention;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Quote;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\JobCostService;
use App\Services\PlanningMessages;
use App\Services\Settings;
use App\Services\WeatherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

        // Agenda : le commercial voit le sien ; le gérant voit tout, ou seulement celui d'une personne.
        $user = $request->user();
        $people = $user->isAdmin() ? $this->assignees() : collect();
        $for = $user->isAdmin() && $people->contains('id', $request->integer('pour')) ? $request->integer('pour') : null;
        $interventions = Intervention::query()->between($from, $to)->visible()->visibleTo($user)
            ->when($for, fn ($q) => $q->where('user_id', $for))
            ->with(['client', 'worksite', 'user'])->orderBy('starts_on')->orderBy('start_time')->get();

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
            // Météo des prochains jours (lue dans le cache, mise à jour toutes les heures).
            'weather' => $interventions->where('status', 'planned')->mapWithKeys(fn (Intervention $i) => [$i->id => app(WeatherService::class)->forIntervention($i)])->filter(),
            'previous' => ($mode === 'mois' ? $from->copy()->subMonth() : $from->copy()->subWeek())->toDateString(),
            'next' => ($mode === 'mois' ? $from->copy()->addMonth() : $from->copy()->addWeek())->toDateString(),
            // Devis acceptés sans intervention prévue : à planifier.
            'people' => $people,
            'for' => $for,
            'toPlan' => Quote::query()->visibleTo($user)->where('status', 'accepted')
                ->whereDoesntHave('interventions', fn ($q) => $q->active()->where('kind', 'chantier'))
                ->whereHas('client')->with('client')->latest('accepted_at')->limit(20)->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $kind = in_array($request->query('type'), ['rdv', 'rappel'], true) ? $request->query('type') : 'chantier';
        $appointment = $kind === 'rdv';
        $intervention = new Intervention([
            'kind' => $kind,
            'starts_on' => today(),
            'start_time' => $kind === 'chantier' ? '08:00' : '09:00',
            'end_time' => $appointment ? '10:00' : null,
            'title' => $appointment ? Intervention::APPOINTMENT_TITLES[0] : null,
            'status' => 'planned',
        ]);
        // Le gérant peut préparer directement un élément pour un commercial (?pour=ID).
        $intervention->user_id = $request->user()->isAdmin() && $this->assignees()->contains('id', $request->integer('pour'))
            ? $request->integer('pour') : $request->user()->id;

        if (($quote = Quote::query()->find($request->integer('devis'))) && $request->user()->canSee($quote)) {
            $intervention->fill(['client_id' => $quote->client_id, 'worksite_id' => $quote->worksite_id, 'quote_id' => $quote->id, 'project_id' => $quote->project_id]);
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
        $this->refreshWeather($intervention);
        $what = $intervention->isAppointment() ? 'Rendez-vous' : 'Intervention';
        ActivityLogger::log('planning.created', "$what planifié(e) {$intervention->whenLabel()} : {$intervention->title}", $intervention->client);

        return redirect()->route('planning.show', $intervention)
            ->with('status', $what.' ajouté'.($intervention->isAppointment() ? '' : 'e').' au planning.'.($intervention->client ? ' Prévenez le client ci-dessous.' : ''));
    }

    public function show(Intervention $intervention): View
    {
        abort_if($intervention->client_id && ! $intervention->client, 404);
        $intervention->load(['client', 'worksite', 'quote', 'project', 'invoice']);

        return view('planning.show', [
            'intervention' => $intervention,
            'message' => $intervention->client ? $this->message($intervention) : null,
            'weather' => $intervention->status === 'planned' ? app(WeatherService::class)->forIntervention($intervention) : [],
        ]);
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
        $this->refreshWeather($intervention);

        return redirect()->route('planning.show', $intervention)->with('status', ($intervention->isAppointment() ? 'Rendez-vous' : 'Intervention').' enregistré'.($intervention->isAppointment() ? '' : 'e').'.');
    }

    /** Marquer comme fait (glisser dans le planning, ou bouton « Fait »). */
    public function done(Intervention $intervention): RedirectResponse
    {
        $intervention->forceFill(['status' => 'done'])->save();
        ActivityLogger::log('planning.done', 'Fait : '.$intervention->title, $intervention->client);

        return back()->with('status', (Intervention::KINDS[$intervention->kind] ?? 'Chantier').' marqué comme fait.');
    }

    /** « Comment nous a-t-il connus ? » : modifiable depuis le rendez-vous, enregistré sur la fiche client. */
    public function source(Request $request, Intervention $intervention): RedirectResponse
    {
        $client = $intervention->client;
        abort_unless($client, 404);

        $data = $request->validate([
            'source' => ['nullable', Rule::in(array_keys(Client::SOURCES))],
            'source_detail' => ['nullable', 'string', 'max:160'],
        ], [], ['source' => 'provenance', 'source_detail' => 'précision']);

        $client->update(['source' => $data['source'] ?? null, 'source_detail' => $data['source_detail'] ?? null]);

        return redirect()->route('planning.show', $intervention)->with('status', 'Provenance du client enregistrée.');
    }

    /** Météo du nouveau lieu ou de la nouvelle date : téléchargée juste après l'affichage de la page. */
    private function refreshWeather(Intervention $intervention): void
    {
        if (! app()->runningUnitTests()) {
            dispatch(fn () => app(WeatherService::class)->forIntervention($intervention->fresh(['client', 'worksite']), fetch: true))->afterResponse();
        }
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
        app(JobCostService::class)->ensureProjects();
        $user = auth()->user();

        // Par client : adresses, chantiers, devis et factures (le formulaire ne montre que ceux du client choisi).
        $projects = Project::query()->where(fn ($q) => $q->has('quotes')->orHas('invoices'))->latest('id')->get()->groupBy('client_id');
        $quotes = Quote::query()->visibleTo($user)->whereNotNull('number')->whereNotIn('status', ['replaced'])->latest('id')->get()->groupBy('client_id');
        $invoices = Invoice::query()->visibleTo($user)->invoices()->whereNotNull('number')->where('status', '!=', 'cancelled')->latest('id')->get()->groupBy('client_id');

        return [
            'intervention' => $intervention,
            'clients' => $clients,
            'canSeeInvoices' => (bool) $user?->canOpen('invoices.index'),
            // « Pour qui » : le gérant choisit parmi les comptes actifs ; le commercial, c'est lui.
            'assignees' => $user?->isAdmin() ? $this->assignees() : collect([$user]),
            'links' => $clients->mapWithKeys(fn (Client $c) => [$c->id => [
                'worksites' => $c->worksites->map(fn ($w) => ['id' => $w->id, 'label' => $w->fullAddress()])->values(),
                'projects' => ($projects[$c->id] ?? collect())->map(fn (Project $p) => ['id' => $p->id, 'label' => $p->title])->values(),
                'quotes' => ($quotes[$c->id] ?? collect())->map(fn (Quote $q) => [
                    'id' => $q->id, 'project' => $q->project_id, 'worksite' => $q->worksite_id, 'title' => $q->title,
                    'label' => $q->number.($q->title ? ' · '.Str::limit($q->title, 40) : '').' · '.mb_strtolower($q->statusLabel()),
                ])->values(),
                'invoices' => ($invoices[$c->id] ?? collect())->map(fn (Invoice $i) => [
                    'id' => $i->id, 'project' => $i->project_id, 'label' => $i->number.' · '.$i->kindLabel().($i->title ? ' · '.Str::limit($i->title, 30) : ''),
                ])->values(),
            ]]),
        ];
    }

    /** Comptes actifs à qui le gérant peut attribuer un rendez-vous, un chantier ou un rappel. */
    private function assignees(): Collection
    {
        return User::query()->whereNull('disabled_at')->orderByRaw("CASE role WHEN 'admin' THEN 0 ELSE 1 END")->orderBy('name')->get();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        // Rendez-vous : le champ « Fin (si plusieurs jours) », caché, est ignoré.
        // Chantier : une fin antérieure au début (date de début changée après coup) est ramenée au début.
        $starts = $request->input('starts_on');
        if (in_array($request->input('kind'), ['rdv', 'rappel'], true) || ! $request->filled('ends_on')
            || (is_string($starts) && strtotime((string) $request->input('ends_on')) < strtotime($starts))) {
            $request->merge(['ends_on' => null]);
        }

        $data = $request->validate([
            'kind' => ['nullable', Rule::in(array_keys(Intervention::KINDS))],
            // Un chantier est toujours chez un client ; un rendez-vous peut être sans client.
            'user_id' => ['nullable', 'integer', Rule::in($request->user()->isAdmin() ? $this->assignees()->pluck('id')->all() : [$request->user()->id])],
            'client_id' => [Rule::requiredIf(! in_array($request->input('kind'), ['rdv', 'rappel'], true)), 'nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'location' => ['nullable', 'string', 'max:200'],
            'worksite_id' => ['nullable', 'integer', Rule::exists('worksites', 'id')->where('client_id', $request->integer('client_id'))],
            'quote_id' => ['nullable', 'integer', Rule::exists('quotes', 'id')->where('client_id', $request->integer('client_id'))],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('client_id', $request->integer('client_id'))],
            'invoice_id' => ['nullable', 'integer', Rule::exists('invoices', 'id')->where('client_id', $request->integer('client_id'))],
            'title' => ['required', 'string', 'max:200'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'start_time' => [Rule::requiredIf(in_array($request->input('kind'), ['rdv', 'rappel'], true)), 'nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'status' => ['nullable', Rule::in(array_keys(Intervention::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'remind_days' => ['nullable', 'integer', Rule::in(array_keys(Intervention::REMINDERS))],
        ], [
            'ends_on.after_or_equal' => 'La date de fin doit être après la date de début.',
            'quote_id.exists' => 'Ce devis n\'appartient pas à ce client.',
            'project_id.exists' => 'Ce chantier n\'appartient pas à ce client.',
            'invoice_id.exists' => 'Cette facture n\'appartient pas à ce client.',
            'worksite_id.exists' => 'Ce chantier n\'appartient pas à ce client.',
            'start_time.required' => 'Indiquez l\'heure.',
            'user_id.in' => 'Choisissez à qui est ce rendez-vous.',
        ], ['client_id' => 'client', 'title' => 'objet', 'starts_on' => 'date', 'start_time' => 'heure', 'end_time' => 'heure de fin']);

        $data['kind'] ??= 'chantier';
        // Un commercial : toujours dans son agenda, et seulement avec ses devis et ses factures.
        $data['user_id'] = (int) ($data['user_id'] ?? 0) ?: $request->user()->id;
        foreach (['quote_id' => Quote::class, 'invoice_id' => Invoice::class] as $field => $model) {
            if (! empty($data[$field]) && ! $request->user()->canSee($model::query()->find($data[$field]))) {
                throw ValidationException::withMessages([$field => 'Ce document est réservé à la personne qui l\'a créé.']);
            }
        }
        // Rappel : une simple tâche datée, sans devis, facture ni chantier.
        if ($data['kind'] === 'rappel') {
            foreach (['worksite_id', 'quote_id', 'project_id', 'invoice_id', 'location', 'end_time'] as $field) {
                $data[$field] = null;
            }
        }
        // Devis ou facture choisi sans chantier : celui du document.
        if (empty($data['project_id'])) {
            $data['project_id'] = (! empty($data['quote_id']) ? Quote::query()->whereKey($data['quote_id'])->value('project_id') : null)
                ?? (! empty($data['invoice_id']) ? Invoice::query()->whereKey($data['invoice_id'])->value('project_id') : null);
        }
        if ($data['kind'] === 'rappel') {
            $data['ends_on'] = $data['starts_on'];
        } elseif ($data['kind'] === 'rdv') {
            // Un rendez-vous tient sur une journée ; heure de fin par défaut : 1 heure après.
            $data['ends_on'] = $data['starts_on'];
            if (empty($data['end_time']) || $data['end_time'] <= $data['start_time']) {
                $data['end_time'] = Carbon::createFromFormat('H:i', $data['start_time'])->addHour()->format('H:i');
            }
        } else {
            $data['end_time'] = null;
        }
        $data['ends_on'] = $data['ends_on'] ?? $data['starts_on'];
        $data['remind_days'] = $data['kind'] === 'rappel' ? 0 : (int) ($data['remind_days'] ?? 1);
        $data['remind_client'] = $data['kind'] !== 'rappel' && $request->boolean('remind_client') && ! empty($data['client_id']);
        $data['status'] = $data['status'] ?? 'planned';

        return $data;
    }

    private function message(Intervention $intervention): string
    {
        return app(PlanningMessages::class)->confirmation($intervention);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\Client;
use App\Models\MaintenanceReminder;
use App\Services\ActivityLogger;
use App\Services\EmailComposer;
use App\Services\MaintenanceService;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Relances d'entretien : reproposer un démoussage, un nettoyage… quelques années après. */
class MaintenanceController extends Controller
{
    public const TABS = [
        'a-relancer' => 'À relancer',
        'a-venir' => 'À venir',
        'traites' => 'Traités',
    ];

    public function index(Request $request): View
    {
        $tab = array_key_exists($request->query('onglet'), self::TABS) ? $request->query('onglet') : 'a-relancer';
        $soon = today()->addDays(30);

        $query = MaintenanceReminder::query()->with(['client', 'worksite'])->whereHas('client');
        $query = match ($tab) {
            'a-venir' => $query->open()->whereDate('due_on', '>', $soon)->orderBy('due_on'),
            'traites' => $query->whereIn('status', ['done', 'dismissed'])->latest('updated_at'),
            default => $query->open()->whereDate('due_on', '<=', $soon)->orderBy('due_on'),
        };

        return view('maintenance.index', [
            'tab' => $tab,
            'reminders' => $query->paginate(30)->withQueryString(),
            'counts' => [
                'a-relancer' => MaintenanceReminder::query()->open()->whereHas('client')->whereDate('due_on', '<=', $soon)->count(),
                'a-venir' => MaintenanceReminder::query()->open()->whereHas('client')->whereDate('due_on', '>', $soon)->count(),
            ],
            'withDelay' => CatalogItem::query()->whereNotNull('maintenance_months')->count(),
        ]);
    }

    public function show(MaintenanceReminder $reminder): View
    {
        abort_unless($reminder->client, 404);

        return view('maintenance.show', [
            'reminder' => $reminder->load(['client', 'worksite', 'invoice']),
            'message' => $this->message($reminder),
        ]);
    }

    /** Relance faite par WhatsApp / SMS / copie : comptée. */
    public function track(Request $request, MaintenanceReminder $reminder): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::in(array_keys(ReminderController::CHANNELS))]]);
        $this->markContacted($reminder, ReminderController::CHANNELS[$data['channel']]);

        return response()->json(['count' => $reminder->contact_count]);
    }

    public function update(Request $request, MaintenanceReminder $reminder): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['done', 'dismissed', 'later', 'reopen'])]]);

        match ($data['action']) {
            'later' => $reminder->forceFill(['status' => 'pending', 'due_on' => today()->addYear()])->save(),
            'reopen' => $reminder->forceFill(['status' => 'pending'])->save(),
            default => $reminder->forceFill(['status' => $data['action']])->save(),
        };

        $messages = [
            'done' => 'Entretien marqué comme terminé.',
            'dismissed' => 'Rappel ignoré.',
            'later' => 'Rappel reporté au '.$reminder->due_on->format('d/m/Y').'.',
            'reopen' => 'Rappel remis à relancer.',
        ];

        return redirect()->route('maintenance.index')->with('status', $messages[$data['action']]);
    }

    /** Ajout à la main depuis la fiche client (travaux faits avant l'application…). */
    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:200'],
            'done_on' => ['required', 'date', 'before_or_equal:today'],
            'months' => ['required', 'integer', 'min:1', 'max:240'],
            'worksite_id' => ['nullable', 'integer', Rule::exists('worksites', 'id')->where('client_id', $client->id)],
        ], [], ['label' => 'prestation', 'done_on' => 'date des travaux', 'months' => 'délai']);

        $doneOn = Carbon::parse($data['done_on']);
        MaintenanceReminder::query()->create([
            'client_id' => $client->id,
            'worksite_id' => $data['worksite_id'] ?? null,
            'label' => $data['label'],
            'done_on' => $doneOn,
            'due_on' => $doneOn->copy()->addMonthsNoOverflow((int) $data['months']),
        ]);

        return back()->with('status', 'Rappel d\'entretien ajouté.');
    }

    public function scan(MaintenanceService $maintenance): RedirectResponse
    {
        $count = $maintenance->scanAll();

        return back()->with('status', $count ? "$count rappel(s) d'entretien créé(s) à partir des factures." : 'Aucun nouveau rappel : vérifiez le délai d\'entretien de vos prestations.');
    }

    /** Objet et message de l'email de relance d'entretien. */
    public function emailFor(MaintenanceReminder $reminder): array
    {
        return [
            'subject' => 'Entretien de votre toiture – '.app(Settings::class)->get('company.trade_name'),
            'body' => $this->message($reminder),
        ];
    }

    private function message(MaintenanceReminder $reminder): string
    {
        $text = strtr((string) app(Settings::class)->get('mail.maintenance'), [
            '{prestation}' => mb_strtolower(mb_substr($reminder->label, 0, 1)).mb_substr($reminder->label, 1),
            '{anciennete}' => $reminder->age(),
            '{date_travaux}' => $reminder->done_on->locale('fr')->isoFormat('MMMM YYYY'),
        ]);

        return app(EmailComposer::class)->renderText($text, $reminder->client);
    }

    private function markContacted(MaintenanceReminder $reminder, string $channel): void
    {
        $reminder->forceFill([
            'status' => $reminder->status === 'pending' ? 'contacted' : $reminder->status,
            'contact_count' => $reminder->contact_count + 1,
            'contacted_at' => now(),
        ])->save();
        ActivityLogger::log('maintenance.reminded', "Relance d'entretien ({$reminder->label}) par $channel", $reminder->client);
    }
}

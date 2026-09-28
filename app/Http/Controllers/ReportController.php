<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Intervention;
use App\Models\Report;
use App\Services\ActivityLogger;
use App\Services\EmailComposer;
use App\Services\EmailService;
use App\Services\MailSettings;
use App\Services\PdfService;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Rapport d'intervention avec photos, envoyé au client (PDF). */
class ReportController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $intervention = $request->integer('intervention') ? Intervention::query()->with('client')->find($request->integer('intervention')) : null;
        $client = $intervention?->client ?? ($request->integer('client') ? Client::query()->find($request->integer('client')) : null);
        if (! $client) {
            return redirect()->route('clients.index')->with('status', 'Ouvrez la fiche du client pour faire son rapport d\'intervention.');
        }

        $report = new Report([
            'client_id' => $client->id,
            'worksite_id' => $intervention?->worksite_id ?? $client->worksites()->value('id'),
            'intervention_id' => $intervention?->id,
            'title' => $intervention?->title ?: 'Intervention',
            'visit_date' => $intervention ? min($intervention->ends_on, today()) : today(),
        ]);

        return view('reports.form', ['report' => $report, 'client' => $client]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $report = Report::query()->create($data);
        ActivityLogger::log('report.created', 'Rapport d\'intervention : '.$report->title, $report->client);

        return redirect()->to(route('reports.show', $report).'#photos')->with('status', 'Rapport enregistré. Ajoutez les photos, puis envoyez-le au client.');
    }

    public function show(Report $report, Settings $settings): View
    {
        abort_unless($report->client, 404);
        $report->load(['client', 'worksite', 'photos', 'intervention']);
        $company = $settings->get('company.trade_name');
        $message = app(EmailComposer::class)->salutation($report->client)."\nVoici le rapport de notre intervention du ".$report->visit_date->format('d/m/Y')
            .", avec les photos. Vous pouvez le transmettre à votre assurance si besoin :\n{lien}\nBien cordialement,\n$company – ".$settings->get('company.phone');

        return view('reports.show', ['report' => $report, 'message' => $message, 'mailConfigured' => app(MailSettings::class)->isConfigured()]);
    }

    public function edit(Report $report): View
    {
        return view('reports.form', ['report' => $report, 'client' => $report->client]);
    }

    public function update(Request $request, Report $report): RedirectResponse
    {
        $report->update($this->validated($request, $report));

        return redirect()->route('reports.show', $report)->with('status', 'Rapport enregistré.');
    }

    public function destroy(Report $report): RedirectResponse
    {
        $client = $report->client;
        $report->photos()->detach();
        $report->delete();

        return redirect()->route('clients.show', $client)->with('status', 'Rapport supprimé (les photos restent sur la fiche du client).');
    }

    public function pdf(Report $report, PdfService $pdf): Response
    {
        return response($pdf->renderReport($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->reportFilename($report).'"',
        ]);
    }

    /** Envoi par email avec le PDF en pièce jointe et le lien. */
    public function email(Request $request, Report $report, EmailService $emails, PdfService $pdf): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email', 'max:160'],
            'message' => ['required', 'string', 'max:5000'],
        ], [], ['to' => 'adresse email', 'message' => 'message']);

        $url = $report->publicUrl();
        $log = $emails->send($report->client, null, [$data['to']], [], 'Rapport d\'intervention – '.app(Settings::class)->get('company.trade_name'),
            str_replace('{lien}', $url, $data['message']), false, false, $url, 'Voir le rapport',
            ['content' => $pdf->renderReport($report), 'name' => $pdf->reportFilename($report)]);

        if (! $log->isSent()) {
            return back()->withInput()->withErrors(['to' => 'L\'envoi a échoué : '.$log->error]);
        }
        $report->forceFill(['sent_at' => now()])->save();

        return back()->with('status', 'Rapport envoyé à '.$data['to'].'.');
    }

    /** Partage par SMS / WhatsApp : le lien est créé et l'envoi noté. */
    public function shared(Report $report): RedirectResponse
    {
        $report->publicUrl();
        $report->forceFill(['sent_at' => now()])->save();

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Report $report = null): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'worksite_id' => ['nullable', 'integer'],
            'intervention_id' => ['nullable', 'integer', Rule::exists('interventions', 'id')],
            'title' => ['required', 'string', 'max:160'],
            'visit_date' => ['required', 'date'],
            'findings' => ['required', 'string', 'max:10000'],
            'work_done' => ['nullable', 'string', 'max:10000'],
            'recommendations' => ['nullable', 'string', 'max:10000'],
        ], [], ['title' => 'objet', 'visit_date' => 'date', 'findings' => 'constat', 'work_done' => 'travaux réalisés', 'recommendations' => 'préconisations']);

        // Le chantier doit appartenir au client.
        $client = Client::query()->findOrFail($data['client_id']);
        $data['worksite_id'] ??= null;
        $data['intervention_id'] ??= null;
        if ($data['worksite_id'] && ! $client->worksites()->whereKey($data['worksite_id'])->exists()) {
            $data['worksite_id'] = null;
        }
        if ($report) {
            unset($data['client_id']);
        }

        return $data;
    }
}

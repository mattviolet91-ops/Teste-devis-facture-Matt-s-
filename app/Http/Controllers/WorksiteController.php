<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorksiteRequest;
use App\Models\Client;
use App\Models\Worksite;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class WorksiteController extends Controller
{
    public function create(Client $client): View
    {
        return view('worksites.form', ['client' => $client, 'worksite' => new Worksite]);
    }

    public function store(WorksiteRequest $request, Client $client): RedirectResponse
    {
        $worksite = $client->worksites()->create($request->validated());
        ActivityLogger::log('worksite.created', "Chantier créé : {$worksite->fullAddress()}", $worksite);

        return redirect()->to(route('clients.show', $client).'#chantier-'.$worksite->id)->with('status', 'Chantier enregistré.');
    }

    public function edit(Worksite $worksite): View
    {
        $this->ensureClientExists($worksite);

        return view('worksites.form', ['client' => $worksite->client, 'worksite' => $worksite]);
    }

    public function update(WorksiteRequest $request, Worksite $worksite): RedirectResponse
    {
        $this->ensureClientExists($worksite);
        $worksite->fill($request->validated());
        $changes = array_keys($worksite->getDirty());
        $worksite->save();

        if ($changes) {
            ActivityLogger::log('worksite.updated', "Chantier modifié : {$worksite->fullAddress()}", $worksite, ['champs' => $changes]);
        }

        return redirect()->to(route('clients.show', $worksite->client_id).'#chantier-'.$worksite->id)->with('status', 'Chantier enregistré.');
    }

    public function destroy(Worksite $worksite): RedirectResponse
    {
        $this->ensureClientExists($worksite);
        $worksite->delete();
        ActivityLogger::log('worksite.deleted', "Chantier mis à la corbeille : {$worksite->fullAddress()}", $worksite);

        return redirect()->route('clients.show', $worksite->client_id)->with('status', 'Chantier placé dans la corbeille.');
    }

    /** Un chantier dont le client est à la corbeille n'est plus modifiable. */
    private function ensureClientExists(Worksite $worksite): void
    {
        abort_unless($worksite->client()->exists(), 404);
    }
}

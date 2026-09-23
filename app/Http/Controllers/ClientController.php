<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Worksite;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(array_keys(Client::TYPES))],
            'status' => ['nullable', Rule::in(array_keys(Client::STATUSES))],
        ]);

        $clients = Client::query()
            ->withCount('worksites')
            ->when($filters['q'] ?? null, function ($query, $q) {
                // Un client est trouvé par ses propres informations ou par l'adresse d'un de ses chantiers.
                $query->where(fn ($sub) => $sub->search($q)
                    ->orWhereHas('worksites', fn ($w) => $w->search($q)));
            })
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString();

        return view('clients.index', compact('clients', 'filters'));
    }

    public function create(): View
    {
        return view('clients.create', ['client' => new Client(['type' => 'particulier', 'status' => 'prospect'])]);
    }

    public function store(ClientRequest $request): RedirectResponse
    {
        $client = DB::transaction(function () use ($request) {
            $client = Client::query()->create($request->safe()->except('create_worksite'));
            ActivityLogger::log('client.created', "Client créé : {$client->displayName()}", $client);

            // Chantier à l'adresse du client (cas le plus fréquent chez un particulier).
            if ($request->boolean('create_worksite') && $client->address && $client->postal_code && $client->city) {
                $worksite = $client->worksites()->create($client->only('address', 'postal_code', 'city'));
                ActivityLogger::log('worksite.created', "Chantier créé : {$worksite->fullAddress()}", $worksite);
            }

            return $client;
        });

        return redirect()->route('clients.show', $client)->with('status', 'Client enregistré.');
    }

    public function show(Client $client): View
    {
        $client->load('worksites');

        $history = ActivityLog::query()
            ->with('user')
            ->where(function ($query) use ($client) {
                $query->where(fn ($q) => $q->where('subject_type', $client->getMorphClass())->where('subject_id', $client->id))
                    ->orWhere(fn ($q) => $q->where('subject_type', (new Worksite)->getMorphClass())
                        ->whereIn('subject_id', Worksite::withTrashed()->where('client_id', $client->id)->select('id')));
            })
            ->latest('id')
            ->limit(30)
            ->get();

        return view('clients.show', compact('client', 'history'));
    }

    public function edit(Client $client): View
    {
        return view('clients.edit', compact('client'));
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        $client->fill($request->safe()->except('create_worksite'));
        $changes = array_keys($client->getDirty());
        $client->save();

        if ($changes) {
            ActivityLogger::log('client.updated', 'Fiche client modifiée', $client, ['champs' => $changes]);
        }

        return redirect()->route('clients.show', $client)->with('status', 'Client enregistré.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        $client->delete();
        ActivityLogger::log('client.deleted', "Client mis à la corbeille : {$client->displayName()}", $client);

        return redirect()->route('clients.index')->with('status', 'Client placé dans la corbeille (récupérable pendant 30 jours).');
    }
}

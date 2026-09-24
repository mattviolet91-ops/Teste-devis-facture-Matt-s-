<?php

namespace App\Services;

use App\Models\Client;
use App\Models\QuoteRequest;
use App\Support\Phone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Demande de devis du site internet : le prospect est créé (ou retrouvé par
 * téléphone / email), son chantier et ses photos enregistrés, et vous êtes prévenu.
 */
class QuoteRequestService
{
    public function __construct(private readonly PhotoService $photos) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files
     */
    public function receive(array $data, array $files, ?string $ip): QuoteRequest
    {
        $request = DB::transaction(function () use ($data, $ip) {
            $client = Client::findDuplicates($data['phone'] ?? null, $data['email'] ?? null)->first();
            if (! $client) {
                $client = Client::query()->create([
                    'type' => 'particulier',
                    'status' => 'prospect',
                    'civility' => $data['civility'] ?? null,
                    'first_name' => $data['first_name'] ?? null,
                    'last_name' => $data['last_name'],
                    'email' => $data['email'] ?? null,
                    'phone' => Phone::format($data['phone']),
                    'address' => $data['address'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                    'city' => $data['city'] ?? null,
                    'source' => 'site',
                    'source_detail' => 'Formulaire de demande de devis',
                ]);
                ActivityLogger::log('client.created', "Prospect créé depuis le site : {$client->displayName()}", $client);
            }

            $worksite = null;
            if (! empty($data['address']) && ! empty($data['postal_code']) && ! empty($data['city'])) {
                $worksite = $client->worksites()->where('postal_code', $data['postal_code'])
                    ->whereRaw('LOWER(address) = ?', [mb_strtolower(trim($data['address']))])->first()
                    ?? $client->worksites()->create(['address' => trim($data['address']), 'postal_code' => $data['postal_code'], 'city' => trim($data['city'])]);
            }

            return QuoteRequest::query()->create([
                'client_id' => $client->id,
                'worksite_id' => $worksite?->id,
                'works' => array_values($data['works'] ?? []),
                'message' => $data['message'] ?? null,
                'availability' => $data['availability'] ?? null,
                'status' => 'new',
                'ip_address' => $ip,
            ]);
        });

        foreach ($files as $file) {
            try {
                $this->photos->store($file, $request->client, $request->worksite, 'probleme', 'Photo envoyée par le client');
            } catch (Throwable $e) {
                Log::warning('Photo de demande de devis ignorée', ['error' => $e->getMessage()]);
            }
        }

        $client = $request->client;
        app(ClientLinkService::class)->notifyText(
            'Nouvelle demande de devis : '.$client->displayName(),
            trim(implode("\n", array_filter([
                ($request->worksLabel() ?: 'Travaux non précisés').($request->worksite ? ' — '.$request->worksite->city : ''),
                $client->phone ? 'Tél. '.$client->phone : null,
                $request->message ? '« '.mb_strimwidth($request->message, 0, 200, '…').' »' : null,
            ]))),
            ClientLinkService::adminUrl('requests.show', $request),
        );

        return $request;
    }
}

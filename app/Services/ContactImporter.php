<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Worksite;
use App\Support\Phone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import de contacts depuis un fichier CSV (export Wix « contacts.csv » ou
 * tableau simple Nom / Prénom / Téléphone / Email / Adresse / Code postal / Ville).
 * Les doublons (même téléphone ou même email) sont ignorés.
 */
class ContactImporter
{
    /** En-têtes reconnus → champ interne. */
    private const COLUMNS = [
        'first_name' => ['prénom', 'prenom', 'first name'],
        'last_name' => ['nom de famille', 'nom', 'last name'],
        'company_name' => ['société', 'societe', 'entreprise', 'company'],
        'email' => ['e-mail 1', 'email', 'e-mail', 'adresse e-mail', 'courriel'],
        'email_2' => ['e-mail 2'],
        'phone' => ['téléphone 1', 'téléphone', 'telephone', 'portable', 'mobile', 'tél', 'tel'],
        'phone_2' => ['téléphone 2'],
        'address' => ['adresse 1 - rue', 'adresse', 'rue'],
        'address_2' => ['adresse 1 - rue ligne 2'],
        'city' => ['adresse 1 - ville', 'ville'],
        'postal_code' => ['adresse 1 - code postal', 'code postal', 'cp'],
        'labels' => ['libellés', 'libelles'],
        'created' => ['créé le (utc+0)'],
        'source' => ['source'],
        'activity' => ['dernière activité'],
        'message' => ['message'],
        'comments' => ['commentaires', 'notes', 'remarques'],
    ];

    /**
     * Lit le fichier et prépare l'import sans rien enregistrer.
     *
     * @return array{new: list<array<string, mixed>>, duplicates: list<array<string, mixed>>, empty: int}
     */
    public function analyse(string $path): array
    {
        $rows = $this->read($path);
        $new = [];
        $duplicates = [];
        $empty = 0;
        $seenPhones = [];
        $seenEmails = [];

        foreach ($rows as $row) {
            $contact = $this->normalize($row);
            if ($contact === null) {
                $empty++;

                continue;
            }

            $phoneKey = $contact['phone'] ? substr(preg_replace('/\D/', '', $contact['phone']), -9) : null;
            $emailKey = $contact['email'];
            $inFile = ($phoneKey && isset($seenPhones[$phoneKey])) || ($emailKey && isset($seenEmails[$emailKey]));
            $existing = Client::findDuplicates($contact['phone'], $contact['email'])->first();
            // Sans téléphone ni email : même nom et même prénom = déjà présent.
            if (! $existing && ! $contact['phone'] && ! $contact['email']) {
                $existing = Client::query()->where('last_name', $contact['last_name'])->where('first_name', $contact['first_name'])->first();
            }

            if ($inFile || $existing) {
                $contact['duplicate_of'] = $existing?->displayName() ?? 'une autre ligne du fichier';
                $duplicates[] = $contact;

                continue;
            }

            if ($phoneKey) {
                $seenPhones[$phoneKey] = true;
            }
            if ($emailKey) {
                $seenEmails[$emailKey] = true;
            }
            $new[] = $contact;
        }

        return ['new' => $new, 'duplicates' => $duplicates, 'empty' => $empty];
    }

    /** Crée les clients (et leur chantier quand l'adresse est connue). Retourne le nombre créé. */
    public function import(string $path): int
    {
        $contacts = $this->analyse($path)['new'];

        DB::transaction(function () use ($contacts) {
            foreach ($contacts as $contact) {
                $client = new Client([
                    'type' => $contact['company_name'] ? 'entreprise' : 'particulier',
                    'status' => $contact['paid'] ? 'client' : 'prospect',
                    'first_name' => $contact['first_name'],
                    'last_name' => $contact['last_name'],
                    'company_name' => $contact['company_name'],
                    'email' => $contact['email'],
                    'phone' => $contact['phone'],
                    'phone_2' => $contact['phone_2'],
                    'address' => $contact['address'],
                    'postal_code' => $contact['postal_code'],
                    'city' => $contact['city'],
                    'source' => $contact['source'],
                    'notes' => $contact['notes'],
                ]);
                if ($contact['created_at']) {
                    $client->created_at = $contact['created_at'];
                }
                $client->save();

                if ($contact['address'] && $contact['postal_code'] && $contact['city']) {
                    $worksite = new Worksite([
                        'address' => $contact['address'],
                        'postal_code' => $contact['postal_code'],
                        'city' => $contact['city'],
                    ]);
                    $worksite->client()->associate($client);
                    $worksite->save();
                }
            }
        });

        ActivityLogger::log('clients.imported', count($contacts).' client(s) importé(s) depuis un fichier CSV');

        return count($contacts);
    }

    /** @return list<array<string, string>> */
    private function read(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $header = fgetcsv($handle, null, $delimiter, '"', '');
        if (! $header) {
            throw new RuntimeException('Fichier vide ou illisible.');
        }
        $map = $this->mapHeader($header);
        if (! isset($map['last_name']) && ! isset($map['first_name']) && ! isset($map['email'])) {
            throw new RuntimeException('Colonnes non reconnues : il faut au moins un nom, un prénom ou un email.');
        }

        $rows = [];
        while (($line = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $row = [];
            foreach ($map as $field => $index) {
                $row[$field] = trim((string) ($line[$index] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($handle);

        if (count($rows) > 5000) {
            throw new RuntimeException('Fichier trop grand (5 000 contacts maximum).');
        }

        return $rows;
    }

    /** @return array<string, int> */
    private function mapHeader(array $header): array
    {
        $map = [];
        $normalized = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $header);
        foreach (self::COLUMNS as $field => $names) {
            foreach ($names as $name) {
                $index = array_search($name, $normalized, true);
                if ($index !== false && ! in_array($index, $map, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    /** @return array<string, mixed>|null null si la ligne ne contient rien d'exploitable */
    private function normalize(array $row): ?array
    {
        $get = fn (string $key) => ($row[$key] ?? '') !== '' ? $row[$key] : null;

        $email = $get('email') && filter_var($get('email'), FILTER_VALIDATE_EMAIL) ? mb_strtolower($get('email')) : null;
        $phone = $this->phone($get('phone'));
        $first = $get('first_name') ? Str::title(mb_strtolower($get('first_name'))) : null;
        $last = $get('last_name') ? $get('last_name') : null;

        if (! $first && ! $last && ! $email && ! $phone) {
            return null;
        }

        // Un seul nom connu : il sert de nom ; aucun nom : l'email ou le téléphone.
        if (! $last) {
            $last = $first ?? ($email ? Str::before($email, '@') : $phone);
            $first = null;
        }

        $address = trim(implode(' ', array_filter([$get('address'), $get('address_2')])));
        $postal = $get('postal_code') && preg_match('/^\d{5}$/', preg_replace('/\s/', '', $get('postal_code'))) ? preg_replace('/\s/', '', $get('postal_code')) : null;
        $city = $get('city');

        // Adresse écrite d'un bloc : « 16 rue X 91140 Villebon-sur-Yvette ».
        if ($address && (! $postal || ! $city) && preg_match('/^(.*?)[,\s]+(\d{5})\s+(.+)$/u', $address, $m)) {
            $address = trim($m[1], ' ,');
            $postal ??= $m[2];
            $city ??= trim($m[3]);
        }

        $rawPhone = $get('phone') && ! $phone ? 'Téléphone indiqué sur Wix : '.$get('phone') : null;

        $notes = collect([
            'Importé de Wix le '.now()->format('d/m/Y').'.',
            $rawPhone,
            $get('labels') ? 'Libellés Wix : '.str_replace(';', ', ', $get('labels')) : null,
            $get('email_2') ? 'Autre email : '.$get('email_2') : null,
            $get('message') ? "Message :\n".$get('message') : null,
            $get('comments') ? "Commentaires :\n".$get('comments') : null,
        ])->filter()->implode("\n\n");

        $source = str_contains((string) $get('source'), 'formulaire') || str_contains((string) $get('source'), 'Membres') ? 'site' : null;

        $created = null;
        if ($get('created')) {
            try {
                $created = Carbon::parse($get('created'), 'UTC')->setTimezone(config('app.timezone'));
            } catch (\Throwable) {
                $created = null;
            }
        }

        return [
            'first_name' => $first,
            'last_name' => mb_substr((string) $last, 0, 80),
            'company_name' => $get('company_name'),
            'email' => $email,
            'phone' => $phone,
            'phone_2' => $this->phone($get('phone_2')),
            'address' => $address ? mb_substr($address, 0, 160) : null,
            'postal_code' => $postal,
            'city' => $city ? mb_substr($city, 0, 80) : null,
            'source' => $source,
            'notes' => mb_substr($notes, 0, 5000),
            'paid' => str_contains((string) $get('activity'), 'payé'),
            'created_at' => $created,
        ];
    }

    private function phone(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        $value = ltrim($value, "'");
        $digits = preg_replace('/\D/', '', $value);
        if (strlen($digits) < 6) {
            return null;
        }
        // Zéro initial perdu par un tableur : « 612345678 ».
        if (strlen($digits) === 9 && ! str_starts_with($value, '+')) {
            $value = '0'.$digits;
        }

        return Phone::format($value);
    }
}

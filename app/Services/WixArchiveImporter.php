<?php

namespace App\Services;

use App\Models\Client;
use App\Models\WixArchive;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

/**
 * Import des devis et factures Wix (PDF, ou archive .zip de PDF) : lecture du
 * numéro, de la date, du montant, du statut et du client ; le PDF d'origine est
 * rangé sur la fiche du client (créé s'il n'existe pas encore).
 */
class WixArchiveImporter
{
    private const MONTHS = [
        'janv' => 1, 'janvier' => 1, 'févr' => 2, 'fevr' => 2, 'février' => 2, 'mars' => 3, 'avr' => 4, 'avril' => 4,
        'mai' => 5, 'juin' => 6, 'juil' => 7, 'juillet' => 7, 'août' => 8, 'aout' => 8, 'sept' => 9, 'septembre' => 9,
        'oct' => 10, 'octobre' => 10, 'nov' => 11, 'novembre' => 11, 'déc' => 12, 'dec' => 12, 'décembre' => 12,
    ];

    /**
     * @param  list<array{path: string, name: string}>  $files  PDF ou ZIP envoyés
     * @return array{imported: int, skipped: int, clients_created: int, errors: list<string>}
     */
    public function import(array $files): array
    {
        $result = ['imported' => 0, 'skipped' => 0, 'clients_created' => 0, 'errors' => []];

        foreach ($this->pdfs($files) as $pdf) {
            try {
                $data = $this->parse($pdf['path']);
            } catch (Throwable) {
                $result['errors'][] = $pdf['name'].' : PDF illisible';

                continue;
            }
            if (! $data) {
                $result['errors'][] = $pdf['name'].' : ni devis ni facture Wix reconnu';

                continue;
            }
            if (WixArchive::query()->where('kind', $data['kind'])->where('number', $data['number'])->exists()) {
                $result['skipped']++;

                continue;
            }

            [$client, $created] = $this->client($data);
            $result['clients_created'] += $created ? 1 : 0;

            $path = 'archives-wix/'.$data['kind'].'-'.$data['number'].'-'.Str::random(6).'.pdf';
            Storage::disk('local')->put($path, (string) file_get_contents($pdf['path']));

            $archive = new WixArchive([
                'kind' => $data['kind'],
                'number' => $data['number'],
                'title' => $data['title'],
                'issue_date' => $data['issue_date'],
                'total' => $data['total'],
                'status' => $data['status'],
                'path' => $path,
                'original_name' => Str::limit($pdf['name'], 190, ''),
            ]);
            $archive->client()->associate($client);
            $archive->save();
            $result['imported']++;
        }

        ActivityLogger::log('wix.imported', $result['imported'].' document(s) Wix archivé(s)');

        return $result;
    }

    /**
     * Informations lues dans un PDF Wix, ou null si ce n'est ni un devis ni une facture.
     *
     * @return array<string, mixed>|null
     */
    public function parse(string $path): ?array
    {
        $text = (new Parser)->parseFile($path)->getText();
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text)), fn ($l) => $l !== ''));

        $index = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^(Devis|Facture|Avoir)\s+N°\s*([\w-]+)/u', $line, $m)) {
                $index = $i;
                $kind = ['Devis' => 'devis', 'Facture' => 'facture', 'Avoir' => 'avoir'][$m[1]];
                $number = $m[2];
                break;
            }
        }
        if ($index === null) {
            return null;
        }

        // Statut affiché par Wix juste au-dessus du numéro (Facturé, Expiré, Payée…).
        $status = null;
        $before = $lines[$index - 1] ?? '';
        if (preg_match('/^[A-Za-zÀ-ÿ ]{3,25}$/u', $before) && ! preg_match('/\d/', $before)) {
            $status = $before;
        }

        $issue = null;
        if (preg_match('/Émis le\s*:\s*(\d{1,2})\s+([a-zéû]+)\.?\s+(\d{4})/iu', $text, $m)) {
            $month = self::MONTHS[mb_strtolower($m[2])] ?? null;
            $issue = $month ? Carbon::create((int) $m[3], $month, (int) $m[1])->toDateString() : null;
        }

        $total = 0;
        if (preg_match('/(?:Prix total|Total TTC|Montant total|Total dû|Total)\s*:?\s*([\d\s\x{202F}\x{A0}.,]+)\s*€/u', $text, $m)) {
            $total = Money::parse(str_replace('.', '', trim($m[1]))) ?? 0;
        }

        // Bloc client : entre les coordonnées de l'entreprise (SIRET) et le statut / numéro.
        $start = 0;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\d{14}$/', str_replace(' ', '', $line))) {
                $start = $i + 1;
                break;
            }
        }
        $end = $status ? $index - 1 : $index;
        $block = array_slice($lines, $start, max(0, $end - $start));

        $client = ['name' => null, 'email' => null, 'phone' => null, 'address' => null, 'postal_code' => null, 'city' => null];
        $title = null;
        $rest = [];
        foreach ($block as $line) {
            if (! $client['email'] && filter_var($line, FILTER_VALIDATE_EMAIL)) {
                $client['email'] = mb_strtolower($line);
            } elseif (! $client['phone'] && preg_match('/^\+?[\d\s.]{9,}$/', $line)) {
                $client['phone'] = $line;
            } elseif (! $client['city'] && preg_match('/^(.+?),\s*(\d{5})$/u', $line, $m)) {
                $client['city'] = trim($m[1]);
                $client['postal_code'] = $m[2];
            } else {
                $rest[] = $line;
            }
        }
        // Premier texte : l'objet s'il ressemble à un titre de devis, sinon le nom du client.
        if (isset($rest[0]) && count($rest) >= 3 || (isset($rest[0]) && preg_match('/^(Devis|Facture|Travaux|Réparation|Nettoyage|Remplacement)/iu', $rest[0]))) {
            $title = array_shift($rest);
        }
        $client['name'] = $rest[0] ?? null;
        $client['address'] = $rest[1] ?? null;

        return [
            'kind' => $kind,
            'number' => $number,
            'status' => $status,
            'issue_date' => $issue,
            'total' => $total,
            'title' => $title ? Str::limit($title, 190, '') : null,
            'client' => $client,
        ];
    }

    /** @return array{0: ?Client, 1: bool} client trouvé (email, téléphone, nom) ou créé */
    private function client(array $data): array
    {
        $info = $data['client'];
        $phone = $info['phone'] ? Phone::format(strlen(preg_replace('/\D/', '', $info['phone'])) === 9 ? '0'.preg_replace('/\D/', '', $info['phone']) : $info['phone']) : null;

        $client = Client::findDuplicates($phone, $info['email'])->first();
        if (! $client && $info['name']) {
            $parts = preg_split('/\s+/', trim($info['name']));
            $last = array_pop($parts);
            $client = Client::query()->where('last_name', $last)->where('first_name', implode(' ', $parts) ?: null)->first()
                ?? Client::query()->where('last_name', $info['name'])->first();
        }
        if ($client || (! $info['name'] && ! $info['email'] && ! $phone)) {
            return [$client, false];
        }

        $parts = preg_split('/\s+/', trim((string) $info['name']));
        $last = count($parts) > 1 ? array_pop($parts) : ($parts[0] ?? ($info['email'] ? Str::before($info['email'], '@') : $phone));
        $client = Client::query()->create([
            'type' => 'particulier',
            'status' => $data['status'] === 'Facturé' ? 'client' : 'prospect',
            'first_name' => count($parts) ? implode(' ', $parts) : null,
            'last_name' => mb_substr((string) $last, 0, 80),
            'email' => $info['email'],
            'phone' => $phone,
            'address' => $info['address'] ? mb_substr($info['address'], 0, 160) : null,
            'postal_code' => $info['postal_code'],
            'city' => $info['city'] ? mb_substr($info['city'], 0, 80) : null,
            'notes' => 'Créé depuis l\'archive Wix n° '.$data['number'].'.',
        ]);

        return [$client, true];
    }

    /**
     * PDF à traiter : ceux envoyés directement et ceux contenus dans un .zip.
     * Les copies « (1) » d'un même fichier sont ignorées.
     *
     * @param  list<array{path: string, name: string}>  $files
     * @return list<array{path: string, name: string}>
     */
    private function pdfs(array $files): array
    {
        $pdfs = [];
        foreach ($files as $file) {
            if (str_ends_with(mb_strtolower($file['name']), '.zip')) {
                $zip = new ZipArchive;
                if ($zip->open($file['path']) !== true) {
                    continue;
                }
                $dir = storage_path('app/private/tmp/'.Str::random(12));
                @mkdir($dir, 0775, true);
                for ($i = 0; $i < $zip->numFiles && $i < 2000; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (! str_ends_with(mb_strtolower($name), '.pdf') || str_contains($name, '__MACOSX')) {
                        continue;
                    }
                    $target = $dir.'/'.Str::random(10).'.pdf';
                    file_put_contents($target, $zip->getFromIndex($i));
                    $pdfs[] = ['path' => $target, 'name' => basename($name)];
                }
                $zip->close();
            } else {
                $pdfs[] = $file;
            }
        }

        return array_values(array_filter($pdfs, fn ($pdf) => ! preg_match('/\(\d+\)\.pdf$/i', $pdf['name'])));
    }
}

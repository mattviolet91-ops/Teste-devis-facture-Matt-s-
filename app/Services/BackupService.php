<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Sauvegardes : chaque nuit la base de données (petite, complète), le 1er du
 * mois la base + tous les fichiers (photos, PDF, signatures, documents).
 * Conservation : 30 sauvegardes quotidiennes et 12 mensuelles.
 */
class BackupService
{
    public const FOLDER = 'sauvegardes';

    public const KEEP_DAILY = 30;

    public const KEEP_MONTHLY = 12;

    /** Tables techniques non sauvegardées (recréées automatiquement). */
    private const SKIPPED_TABLES = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'];

    /** Crée une sauvegarde : « quotidienne » (base seule) ou « mensuelle » / « complète » (base + fichiers). */
    public function create(string $type = 'quotidienne'): string
    {
        $disk = Storage::disk('local');
        $name = self::FOLDER.'/'.$type.'-'.now()->format('Y-m-d-His').'.zip';
        $disk->makeDirectory(self::FOLDER);
        $path = $disk->path($name);

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible de créer l\'archive de sauvegarde.');
        }

        $zip->addFromString('base-de-donnees.sql', $this->dumpDatabase());
        $zip->addFromString('LISEZ-MOI.txt', $this->readme($type));

        if ($type !== 'quotidienne') {
            foreach ($disk->allFiles() as $file) {
                if (! str_starts_with($file, self::FOLDER.'/') && ! str_starts_with($file, '.')) {
                    $zip->addFile($disk->path($file), 'fichiers/'.$file);
                }
            }
        }

        if (! $zip->close()) {
            throw new RuntimeException('Impossible d\'écrire l\'archive de sauvegarde.');
        }

        return $name;
    }

    /** Supprime les anciennes sauvegardes au-delà de la durée de conservation. */
    public function prune(): int
    {
        $deleted = 0;
        foreach (['quotidienne' => self::KEEP_DAILY, 'mensuelle' => self::KEEP_MONTHLY, 'complete' => 3] as $type => $keep) {
            $old = $this->list()->where('type', $type)->slice($keep);
            foreach ($old as $backup) {
                Storage::disk('local')->delete($backup['path']);
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @return Collection<int, array{path: string, name: string, type: string, size: int, date: Carbon}> */
    public function list(): Collection
    {
        return collect(Storage::disk('local')->files(self::FOLDER))
            ->filter(fn ($file) => str_ends_with($file, '.zip'))
            ->map(function ($file) {
                preg_match('/(quotidienne|mensuelle|complete)-(\d{4}-\d{2}-\d{2}-\d{6})\.zip$/', $file, $m);

                return [
                    'path' => $file,
                    'name' => basename($file),
                    'type' => $m[1] ?? 'quotidienne',
                    'size' => Storage::disk('local')->size($file),
                    'date' => isset($m[2]) ? Carbon::createFromFormat('Y-m-d-His', $m[2]) : Carbon::createFromTimestamp(Storage::disk('local')->lastModified($file)),
                ];
            })
            ->sortByDesc('date')
            ->values();
    }

    /** Export des données (INSERT), à réimporter après « php artisan migrate » sur une base vide. */
    public function dumpDatabase(): string
    {
        $pdo = DB::connection()->getPdo();
        $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
        $out = "-- Sauvegarde Gestion Matt's Couverture du ".now()->format('d/m/Y H:i')."\n"
            ."-- Restauration : base vide + « php artisan migrate --force », puis importer ce fichier.\n";
        $out .= $mysql ? "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n" : "PRAGMA foreign_keys = OFF;\n";

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, self::SKIPPED_TABLES, true)) {
                continue;
            }
            $quoted = $mysql ? "`$table`" : "\"$table\"";
            $out .= "\n-- Table $table\nDELETE FROM $quoted;\n";

            foreach (DB::table($table)->cursor() as $row) {
                $row = (array) $row;
                $columns = implode(', ', array_map(fn ($c) => $mysql ? "`$c`" : "\"$c\"", array_keys($row)));
                $values = implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), $row));
                $out .= "INSERT INTO $quoted ($columns) VALUES ($values);\n";
            }
        }

        if ($mysql) {
            $out .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";
        }

        return $out;
    }

    private function readme(string $type): string
    {
        return "Sauvegarde de Gestion Matt's Couverture — ".now()->format('d/m/Y à H:i')."\n\n"
            ."- base-de-donnees.sql : clients, devis, factures, paiements, réglages…\n"
            .($type === 'quotidienne' ? '' : "- fichiers/ : photos, PDF envoyés, signatures, documents, attestations.\n")
            ."\nConservez ce fichier en lieu sûr (ordinateur, clé USB, cloud). Il contient des données personnelles de vos clients : ne le partagez pas.\n";
    }
}

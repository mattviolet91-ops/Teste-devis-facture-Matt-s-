<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\BackupService;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/** Réglages → Sauvegardes : liste, sauvegarde complète à la demande, téléchargement. */
class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    public function index(Settings $settings): View
    {
        return view('settings.backups', [
            'backups' => $this->backups->list(),
            'lastDownload' => $settings->get('backups.last_download_at'),
        ]);
    }

    /** Crée une sauvegarde complète (données + fichiers) et la télécharge. */
    public function create(Settings $settings): BinaryFileResponse|RedirectResponse
    {
        try {
            $name = $this->backups->create('complete');
            $this->backups->prune();
        } catch (Throwable $e) {
            return back()->withErrors(['backup' => 'La sauvegarde a échoué : '.$e->getMessage()]);
        }

        return $this->send($name, $settings);
    }

    public function download(string $name, Settings $settings): BinaryFileResponse
    {
        abort_unless(preg_match('/^(quotidienne|mensuelle|complete)-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/', $name) === 1, 404);
        $path = BackupService::FOLDER.'/'.$name;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return $this->send($path, $settings);
    }

    private function send(string $path, Settings $settings): BinaryFileResponse
    {
        $settings->set(['backups.last_download_at' => now()->toIso8601String()]);
        ActivityLogger::log('backup.downloaded', 'Sauvegarde téléchargée : '.basename($path));

        return response()->download(Storage::disk('local')->path($path), 'sauvegarde-matts-couverture-'.basename($path), [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

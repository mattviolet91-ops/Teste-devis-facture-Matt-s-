<?php

namespace App\Http\Controllers;

use App\Models\WixArchive;
use App\Services\WixArchiveImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Archives Wix : devis et factures émis avant l'application (PDF d'origine). */
class WixArchiveController extends Controller
{
    public function index(Request $request): View
    {
        $kind = $request->query('type');
        $archives = WixArchive::query()->with('client')
            ->when(array_key_exists((string) $kind, WixArchive::KINDS), fn ($q) => $q->where('kind', $kind))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w->where('number', 'like', "%$term%")
                ->orWhere('title', 'like', "%$term%")
                ->orWhereHas('client', fn ($c) => $c->search($term))))
            ->orderByDesc('issue_date')->orderByDesc('number')
            ->paginate(50)->withQueryString();

        return view('archives.index', [
            'archives' => $archives,
            'kind' => $kind,
            'totals' => WixArchive::query()->selectRaw('kind, COUNT(*) as count, SUM(total) as total')->groupBy('kind')->get()->keyBy('kind'),
        ]);
    }

    public function store(Request $request, WixArchiveImporter $importer): RedirectResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'max:200'],
            'files.*' => ['file', 'max:51200', 'mimes:pdf,zip'],
        ], [
            'files.required' => 'Choisissez les PDF de vos devis / factures Wix, ou le fichier .zip.',
            'files.*.mimes' => 'Seuls les PDF et les fichiers .zip sont acceptés.',
        ]);

        $files = array_map(fn ($file) => ['path' => $file->getRealPath(), 'name' => $file->getClientOriginalName()], $request->file('files'));
        $result = $importer->import($files);
        File::deleteDirectory(storage_path('app/private/tmp'));

        $message = $result['imported'].' document(s) Wix archivé(s)'
            .($result['clients_created'] ? ', '.$result['clients_created'].' client(s) créé(s)' : '')
            .($result['skipped'] ? ', '.$result['skipped'].' déjà présent(s)' : '').'.';

        return redirect()->route('archives.index')->with('status', $message)->with('archive_errors', $result['errors']);
    }

    public function show(WixArchive $archive): Response
    {
        abort_unless(Storage::disk('local')->exists($archive->path), 404);

        return Storage::disk('local')->response($archive->path, $archive->label().'.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}

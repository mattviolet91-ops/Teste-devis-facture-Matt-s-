<?php

namespace App\Http\Controllers;

use App\Services\ContactImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

/** Import de contacts (export Wix ou fichier CSV) : envoi, aperçu, confirmation. */
class ClientImportController extends Controller
{
    private const FOLDER = 'imports';

    public function __construct(private readonly ContactImporter $importer) {}

    public function create(): View
    {
        return view('clients.import');
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
        ], [
            'file.required' => 'Choisissez le fichier « contacts.csv ».',
            'file.mimes' => 'Le fichier doit être au format CSV (export des contacts Wix).',
        ]);

        $token = Str::random(32);
        $path = $request->file('file')->storeAs(self::FOLDER, $token.'.csv', 'local');

        try {
            $analysis = $this->importer->analyse(Storage::disk('local')->path($path));
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete($path);

            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return view('clients.import-preview', ['token' => $token] + $analysis);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{32}$/']]);
        $path = self::FOLDER.'/'.$data['token'].'.csv';
        abort_unless(Storage::disk('local')->exists($path), 404);

        $count = $this->importer->import(Storage::disk('local')->path($path));
        Storage::disk('local')->delete($path);

        return redirect()->route('clients.index')->with('status', "$count client(s) importé(s).");
    }
}

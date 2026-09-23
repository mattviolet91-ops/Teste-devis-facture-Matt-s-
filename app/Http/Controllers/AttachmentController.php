<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Client;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Documents rangés sur une fiche client (PDF, images, tableurs…). */
class AttachmentController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'max:10'],
            'files.*' => ['file', 'max:15360', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,odt,ods,txt'],
        ], [
            'files.*.mimes' => 'Format non accepté (PDF, images, Word, Excel ou texte).',
            'files.*.max' => 'Fichier trop lourd (15 Mo maximum).',
        ]);

        foreach ($request->file('files') as $file) {
            $name = Str::limit($file->getClientOriginalName(), 150, '');
            $path = $file->store('documents/clients/'.$client->id, 'local');

            $attachment = new Attachment(['name' => $name]);
            $attachment->client()->associate($client);
            $attachment->forceFill([
                'path' => $path,
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
                'created_by' => auth()->id(),
            ])->save();
        }

        ActivityLogger::log('attachment.added', count($request->file('files')).' document(s) ajouté(s)', $client);

        return back()->with('status', 'Document(s) ajouté(s).');
    }

    public function show(Request $request, Attachment $attachment): Response
    {
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);
        $disposition = $request->boolean('telecharger') ? 'attachment' : 'inline';

        return Storage::disk('local')->response($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            // Un document envoyé par un tiers ne doit jamais s'exécuter dans l'application.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], $disposition);
    }

    public function destroy(Attachment $attachment): RedirectResponse
    {
        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();
        ActivityLogger::log('attachment.deleted', "Document supprimé : {$attachment->name}", $attachment->client);

        return back()->with('status', 'Document supprimé.');
    }
}

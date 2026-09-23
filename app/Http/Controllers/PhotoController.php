<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Photo;
use App\Models\Quote;
use App\Models\Worksite;
use App\Services\ActivityLogger;
use App\Services\PdfService;
use App\Services\PhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Photos de chantier : galerie, envoi, classement, annotation et annexe PDF. */
class PhotoController extends Controller
{
    public function __construct(private readonly PhotoService $photos) {}

    public function index(Request $request): View
    {
        $category = $request->query('categorie');
        $photos = Photo::query()
            ->whereHas('client')
            ->with(['client', 'worksite'])
            ->when(array_key_exists((string) $category, Photo::CATEGORIES), fn ($q) => $q->where('category', $category))
            ->latest('id')
            ->paginate(48)
            ->withQueryString();

        $worksites = Worksite::query()->whereHas('client')->with('client')->latest('updated_at')->limit(100)->get();

        return view('photos.index', compact('photos', 'worksites', 'category'));
    }

    public function worksite(Worksite $worksite): View
    {
        abort_unless($worksite->client()->exists(), 404);

        return view('photos.worksite', [
            'worksite' => $worksite->load('client'),
            'photos' => $worksite->photos()->get(),
        ]);
    }

    public function store(Request $request, Worksite $worksite): JsonResponse|RedirectResponse
    {
        abort_unless($worksite->client()->exists(), 404);

        $data = $request->validate([
            'photos' => ['required', 'array', 'max:20'],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
            'category' => ['required', Rule::in(array_keys(Photo::CATEGORIES))],
            'caption' => ['nullable', 'string', 'max:255'],
        ], [
            'photos.*.mimes' => 'Format non pris en charge : prenez la photo en JPEG (réglage « Le plus compatible » sur iPhone).',
            'photos.*.max' => 'Photo trop lourde (20 Mo maximum).',
        ]);

        $saved = [];
        foreach ($data['photos'] as $file) {
            try {
                $saved[] = $this->photos->store($file, $worksite->client, $worksite, $data['category'], $data['caption'] ?? null);
            } catch (RuntimeException $e) {
                return $this->fail($request, $e->getMessage());
            }
        }

        ActivityLogger::log('photo.added', count($saved).' photo(s) ajoutée(s) : '.$worksite->fullAddress(), $worksite);

        if ($request->expectsJson()) {
            return response()->json(['count' => count($saved), 'ids' => array_map(fn ($p) => $p->id, $saved)], 201);
        }

        return redirect()->route('photos.worksite', $worksite)->with('status', count($saved).' photo(s) ajoutée(s).');
    }

    public function update(Request $request, Photo $photo): RedirectResponse
    {
        $photo->update($request->validate([
            'category' => ['required', Rule::in(array_keys(Photo::CATEGORIES))],
            'caption' => ['nullable', 'string', 'max:255'],
        ]));

        return back()->with('status', 'Photo enregistrée.');
    }

    public function annotate(Request $request, Photo $photo): JsonResponse|RedirectResponse
    {
        if ($request->boolean('remove')) {
            $this->photos->removeAnnotation($photo);

            return back()->with('status', 'Annotation retirée : photo d\'origine rétablie.');
        }

        $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:20480']]);
        $this->photos->annotate($photo, $request->file('image'));

        return response()->json(['ok' => true]);
    }

    public function destroy(Photo $photo): RedirectResponse
    {
        $subject = $photo->worksite ?? $photo->client;
        $this->photos->delete($photo);
        ActivityLogger::log('photo.deleted', 'Photo supprimée', $subject);

        return back()->with('status', 'Photo supprimée.');
    }

    /** Fichier image : « mini », « photo » (annotée si elle existe) ou « original ». */
    public function file(Photo $photo, string $variant): Response
    {
        $path = match ($variant) {
            'mini' => $photo->thumb_path,
            'original' => $photo->path,
            default => $photo->displayPath(),
        };
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=604800']);
    }

    /** Choix des photos imprimées en annexe du PDF. */
    public function attachToQuote(Request $request, Quote $quote): RedirectResponse
    {
        return $this->attach($request, $quote);
    }

    public function attachToInvoice(Request $request, Invoice $invoice): RedirectResponse
    {
        return $this->attach($request, $invoice);
    }

    /** Nouvelles photos prises depuis un devis ou une facture : ajoutées directement au PDF. */
    public function uploadToQuote(Request $request, Quote $quote): JsonResponse|RedirectResponse
    {
        return $this->upload($request, $quote);
    }

    public function uploadToInvoice(Request $request, Invoice $invoice): JsonResponse|RedirectResponse
    {
        return $this->upload($request, $invoice);
    }

    private function attach(Request $request, Quote|Invoice $document): RedirectResponse
    {
        abort_unless($document->photosEditable(), 403, 'Le document est envoyé : son PDF ne change plus.');

        $ids = collect($request->input('photos', []))->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $allowed = Photo::query()->whereIn('id', $ids)->where('client_id', $document->client_id)->pluck('id');

        $document->photos()->sync($ids->intersect($allowed)->values()->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i + 1]])->all());
        $this->refreeze($document);

        return back()->with('status', $allowed->isEmpty() ? 'Aucune photo dans le PDF.' : $allowed->count().' photo(s) en annexe du PDF.');
    }

    private function upload(Request $request, Quote|Invoice $document): JsonResponse|RedirectResponse
    {
        abort_unless($document->photosEditable(), 403, 'Le document est envoyé : son PDF ne change plus.');

        $data = $request->validate([
            'photos' => ['required', 'array', 'max:20'],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
            'category' => ['required', Rule::in(array_keys(Photo::CATEGORIES))],
            'caption' => ['nullable', 'string', 'max:255'],
        ], [
            'photos.*.mimes' => 'Format non pris en charge : prenez la photo en JPEG (réglage « Le plus compatible » sur iPhone).',
            'photos.*.max' => 'Photo trop lourde (20 Mo maximum).',
        ]);

        $position = (int) $document->photos()->max('position');
        $saved = [];
        foreach ($data['photos'] as $file) {
            try {
                $photo = $this->photos->store($file, $document->client, $document->worksite, $data['category'], $data['caption'] ?? null);
            } catch (RuntimeException $e) {
                return $this->fail($request, $e->getMessage());
            }
            $document->photos()->attach($photo->id, ['position' => ++$position]);
            $saved[] = $photo;
        }
        $this->refreeze($document);
        ActivityLogger::log('photo.added', count($saved).' photo(s) ajoutée(s) au PDF', $document);

        if ($request->expectsJson()) {
            return response()->json(['count' => count($saved)], 201);
        }

        return back()->with('status', count($saved).' photo(s) ajoutée(s) au PDF.');
    }

    /** Devis envoyé mais pas encore accepté : son PDF est refait avec les photos. */
    private function refreeze(Quote|Invoice $document): void
    {
        if (! $document->isDraft()) {
            app(PdfService::class)->freeze($document->fresh());
        }
    }

    private function fail(Request $request, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], 422)
            : back()->withErrors(['photos' => $message]);
    }
}

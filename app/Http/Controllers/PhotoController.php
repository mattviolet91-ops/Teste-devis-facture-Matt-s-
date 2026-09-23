<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Photo;
use App\Models\Quote;
use App\Models\Worksite;
use App\Services\ActivityLogger;
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
            ->whereHas('worksite', fn ($q) => $q->whereHas('client'))
            ->with('worksite.client')
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
                $saved[] = $this->photos->store($file, $worksite, $data['category'], $data['caption'] ?? null);
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
        $worksite = $photo->worksite;
        $this->photos->delete($photo);
        ActivityLogger::log('photo.deleted', 'Photo supprimée : '.$worksite?->fullAddress(), $worksite);

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

    /** Photos imprimées en annexe du PDF (brouillons uniquement : un document envoyé est figé). */
    public function attachToQuote(Request $request, Quote $quote): RedirectResponse
    {
        return $this->attach($request, $quote);
    }

    public function attachToInvoice(Request $request, Invoice $invoice): RedirectResponse
    {
        return $this->attach($request, $invoice);
    }

    private function attach(Request $request, Quote|Invoice $document): RedirectResponse
    {
        abort_unless($document->isDraft(), 403, 'Le document est envoyé : son PDF ne change plus.');

        $ids = collect($request->input('photos', []))->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $allowed = Photo::query()->whereIn('id', $ids)
            ->whereHas('worksite', fn ($q) => $q->where('client_id', $document->client_id))
            ->pluck('id');

        $document->photos()->sync($ids->intersect($allowed)->values()->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i + 1]])->all());

        return back()->with('status', $allowed->isEmpty() ? 'Aucune photo dans le PDF.' : $allowed->count().' photo(s) ajoutée(s) en annexe du PDF.');
    }

    private function fail(Request $request, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], 422)
            : back()->withErrors(['photos' => $message]);
    }
}

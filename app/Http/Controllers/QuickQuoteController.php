<?php

namespace App\Http\Controllers;

use App\Services\QuickQuoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Devis express : une phrase (tapée ou dictée) devient un devis brouillon, après aperçu. */
class QuickQuoteController extends Controller
{
    public function create(): View
    {
        return view('quotes.express', ['text' => old('text', ''), 'parsed' => null]);
    }

    public function preview(Request $request, QuickQuoteService $service): View
    {
        $data = $this->validated($request);

        return view('quotes.express', [
            'text' => $data['text'],
            'parsed' => $service->parse($data['text'], $data['client_id'] ?? null),
        ]);
    }

    public function store(Request $request, QuickQuoteService $service): RedirectResponse|View
    {
        $data = $this->validated($request);
        $parsed = $service->parse($data['text'], $data['client_id'] ?? null);
        if ($parsed['errors'] !== []) {
            return view('quotes.express', ['text' => $data['text'], 'parsed' => $parsed]);
        }

        $quote = $service->create($parsed, $data['title'] ?? null);

        return redirect()->route('quotes.show', $quote)->with('status', 'Devis brouillon créé. Vérifiez-le, puis envoyez-le.');
    }

    /** @return array{text: string, client_id?: int|null, title?: string|null} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'text' => ['required', 'string', 'max:5000'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'title' => ['nullable', 'string', 'max:255'],
        ], [], ['text' => 'texte du devis']);
    }
}

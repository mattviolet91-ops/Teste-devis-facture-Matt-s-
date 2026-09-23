<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\TextTemplate;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Textes prédéfinis : conditions de paiement, notes, étapes types. */
class TextTemplateController extends Controller
{
    public function edit(): View
    {
        return view('settings.texts', [
            'groups' => collect(TextTemplate::TYPES)->mapWithKeys(fn ($label, $type) => [
                $type => ['label' => $label, 'items' => TextTemplate::query()->ofType($type)->get()],
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $template = TextTemplate::query()->create($data + ['position' => (int) TextTemplate::query()->where('type', $data['type'])->max('position') + 1]);
        $this->applyDefault($template, $request->boolean('is_default'));
        ActivityLogger::log('settings.text', "Texte type ajouté : {$template->label}", $template);

        return back()->with('status', 'Texte ajouté.');
    }

    public function update(Request $request, TextTemplate $template): RedirectResponse
    {
        $template->update($this->validated($request, $template->type));
        $this->applyDefault($template, $request->boolean('is_default'));

        return back()->with('status', 'Texte enregistré.');
    }

    public function destroy(TextTemplate $template): RedirectResponse
    {
        $template->delete();

        return back()->with('status', 'Texte supprimé.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?string $type = null): array
    {
        return $request->validate([
            'type' => [$type ? 'prohibited' : 'required', Rule::in(array_keys(TextTemplate::TYPES))],
            'label' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
        ], [], ['label' => 'nom', 'body' => 'texte']);
    }

    /**
     * « Par défaut » : les conditions de paiement n'en ont qu'une ; les notes par
     * défaut (plusieurs possibles) sont préremplies sur chaque nouveau devis.
     */
    private function applyDefault(TextTemplate $template, bool $isDefault): void
    {
        DB::transaction(function () use ($template, $isDefault) {
            if ($isDefault && $template->type === 'payment_terms') {
                TextTemplate::query()->where('type', 'payment_terms')->whereKeyNot($template->id)->update(['is_default' => false]);
            }
            $template->update(['is_default' => $isDefault && $template->type !== 'step']);
        });
    }
}

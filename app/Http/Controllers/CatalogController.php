<?php

namespace App\Http\Controllers;

use App\Models\CatalogCategory;
use App\Models\CatalogItem;
use App\Models\Unit;
use App\Models\VatRate;
use App\Services\ActivityLogger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

/** Bibliothèque de prestations réutilisables. */
class CatalogController extends Controller
{
    public function index(Request $request): View
    {
        $q = mb_substr(trim((string) $request->query('q')), 0, 100);

        $items = CatalogItem::query()
            ->with('category')
            ->when($q !== '', fn ($query) => $query->search($q))
            ->orderBy('is_active', 'desc')
            ->orderBy('position')
            ->get()
            ->groupBy(fn (CatalogItem $item) => $item->category?->name ?? 'Sans catégorie');

        $order = CatalogCategory::query()->ordered()->pluck('name')->push('Sans catégorie');
        $items = $items->sortBy(fn ($group, $name) => $order->search($name));

        return view('catalog.index', ['groups' => $items, 'q' => $q, 'categories' => CatalogCategory::query()->ordered()->withCount('items')->get()]);
    }

    public function create(): View
    {
        return view('catalog.form', $this->formData(new CatalogItem(['unit' => 'forfait', 'is_active' => true])));
    }

    public function store(Request $request): RedirectResponse
    {
        $item = CatalogItem::query()->create($this->validated($request) + ['position' => (int) CatalogItem::query()->max('position') + 1]);
        ActivityLogger::log('catalog.created', "Prestation ajoutée : {$item->name}", $item);

        return redirect()->route('catalog.index')->with('status', 'Prestation ajoutée.');
    }

    public function edit(CatalogItem $item): View
    {
        return view('catalog.form', $this->formData($item));
    }

    public function update(Request $request, CatalogItem $item): RedirectResponse
    {
        $item->update($this->validated($request));
        ActivityLogger::log('catalog.updated', "Prestation modifiée : {$item->name}", $item);

        return redirect()->route('catalog.index')->with('status', 'Prestation enregistrée.');
    }

    public function destroy(CatalogItem $item): RedirectResponse
    {
        // Les devis gardent leur propre copie des lignes : supprimer ici ne change rien aux devis existants.
        $item->delete();
        ActivityLogger::log('catalog.deleted', "Prestation supprimée : {$item->name}");

        return redirect()->route('catalog.index')->with('status', 'Prestation supprimée.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80', 'unique:catalog_categories,name']]);
        CatalogCategory::query()->create($data + ['position' => (int) CatalogCategory::query()->max('position') + 1]);

        return back()->with('status', 'Catégorie ajoutée.');
    }

    public function updateCategory(Request $request, CatalogCategory $category): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80', Rule::unique('catalog_categories', 'name')->ignore($category)]]);
        $category->update($data);
        $category->items->each->save(); // met à jour l'index de recherche

        return back()->with('status', 'Catégorie renommée.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer', 'exists:catalog_categories,id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit' => ['required', 'string', 'max:20'],
            'unit_price' => ['nullable', 'string', 'max:20'],
            'vat_rate' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'maintenance_months' => ['nullable', 'integer', 'min:1', 'max:240'],
        ], [], ['name' => 'nom', 'unit' => 'unité', 'unit_price' => 'prix', 'maintenance_months' => 'délai d\'entretien']);

        validator($data, [])->after(function (Validator $validator) use ($data) {
            if (($data['unit_price'] ?? '') !== '' && Money::parse($data['unit_price']) === null) {
                $validator->errors()->add('unit_price', 'Prix invalide (ex. 8 ou 8,50).');
            }
        })->validate();

        $data['unit_price'] = ($data['unit_price'] ?? '') === '' ? 0 : Money::parse($data['unit_price']);
        $data['vat_rate'] = ($data['vat_rate'] ?? '') === '' ? null : (int) $data['vat_rate'];
        $data['maintenance_months'] = ($data['maintenance_months'] ?? '') === '' ? null : (int) $data['maintenance_months'];
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    /** @return array<string, mixed> */
    private function formData(CatalogItem $item): array
    {
        return [
            'item' => $item,
            'categories' => CatalogCategory::query()->ordered()->pluck('name', 'id'),
            'units' => Unit::query()->ordered()->pluck('label', 'code'),
            'vatRates' => VatRate::query()->where('is_active', true)->ordered()->get(),
        ];
    }
}

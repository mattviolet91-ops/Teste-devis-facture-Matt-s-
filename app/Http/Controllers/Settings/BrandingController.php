<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BrandingController extends Controller
{
    public function edit(Settings $settings): View
    {
        return view('settings.branding', [
            'branding' => $settings->group('branding'),
            'fonts' => array_keys(config('entreprise.fonts')),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $hex = ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $font = ['required', Rule::in(array_keys(config('entreprise.fonts')))];

        $data = $request->validate([
            'color_accent' => $hex,
            'color_primary' => $hex,
            'color_text' => $hex,
            'color_background' => $hex,
            'font_heading' => $font,
            'font_body' => $font,
            // Pas de SVG : un SVG peut contenir du code exécutable.
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'icon' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
        ], [
            'regex' => 'Couleur attendue au format #RRGGBB.',
        ]);

        $values = collect($data)->except(['logo', 'icon'])
            ->mapWithKeys(fn ($value, $key) => ["branding.$key" => str_starts_with($key, 'color') ? strtoupper($value) : $value])
            ->all();

        foreach (['logo', 'icon'] as $kind) {
            if ($request->hasFile($kind)) {
                $this->deleteImage($settings, $kind);
                $values["branding.{$kind}_path"] = $request->file($kind)->store('branding', 'local');
            } elseif ($request->boolean("remove_$kind")) {
                $this->deleteImage($settings, $kind);
                $values["branding.{$kind}_path"] = null;
            }
        }

        $settings->set($values);
        ActivityLogger::log('settings.branding', 'Apparence modifiée');

        return back()->with('status', 'Apparence enregistrée.');
    }

    public function reset(Settings $settings): RedirectResponse
    {
        $defaults = collect(config('entreprise.branding'))->except(['logo_path', 'icon_path'])
            ->mapWithKeys(fn ($value, $key) => ["branding.$key" => $value])
            ->all();

        $settings->set($defaults);
        ActivityLogger::log('settings.branding', 'Apparence réinitialisée');

        return back()->with('status', 'Couleurs et polices du site rétablies.');
    }

    private function deleteImage(Settings $settings, string $kind): void
    {
        if ($path = $settings->get("branding.{$kind}_path")) {
            Storage::disk('local')->delete($path);
        }
    }
}

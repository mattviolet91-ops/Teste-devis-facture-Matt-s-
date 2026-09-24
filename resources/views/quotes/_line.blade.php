{{-- Une ligne de l'éditeur. $i = index (ou « __i__ » dans les modèles), $l = valeurs (App\Support\LineInput). --}}
@php $n = "lines[$i]"; @endphp
<div class="line line-{{ $l['type'] }}" data-line data-type="{{ $l['type'] }}">
    <input type="hidden" name="{{ $n }}[type]" value="{{ $l['type'] }}">
    <div class="line-toolbar">
        <span class="line-kind">{{ ['item' => 'Prestation', 'section' => 'Section', 'text' => 'Texte'][$l['type']] }}</span>
        <span class="line-total" data-line-total></span>
        <button type="button" class="icon-btn icon-btn-sm" data-move="up" title="Monter"><x-icon name="chevron-up" /><span class="visually-hidden">Monter</span></button>
        <button type="button" class="icon-btn icon-btn-sm" data-move="down" title="Descendre"><x-icon name="chevron-down" /><span class="visually-hidden">Descendre</span></button>
        <button type="button" class="icon-btn icon-btn-sm" data-duplicate title="Dupliquer"><x-icon name="copy" /><span class="visually-hidden">Dupliquer</span></button>
        <button type="button" class="icon-btn icon-btn-sm danger" data-remove title="Supprimer"><x-icon name="trash" /><span class="visually-hidden">Supprimer</span></button>
    </div>

    @if ($l['type'] === 'section')
        <input type="text" class="line-title" name="{{ $n }}[title]" value="{{ $l['title'] }}" placeholder="Titre de la section, ex. NETTOYAGE DE TOITURE" aria-label="Titre de la section">
        <label class="check small"><input type="checkbox" name="{{ $n }}[hide_prices]" value="1" @checked($l['hide_prices'])> <span>N'afficher que le total de la section au client</span></label>
    @elseif ($l['type'] === 'text')
        <textarea name="{{ $n }}[description]" rows="2" placeholder="Texte libre affiché sur le document" aria-label="Texte libre" data-autogrow>{{ $l['description'] }}</textarea>
    @else
        <input type="hidden" name="{{ $n }}[catalog_item_id]" value="{{ $l['catalog_item_id'] }}">
        <input type="text" class="line-title" name="{{ $n }}[title]" value="{{ $l['title'] }}" placeholder="Désignation, ex. Traitement de la toiture" aria-label="Désignation">
        <textarea name="{{ $n }}[description]" rows="3" placeholder="Détail des étapes, une par ligne" aria-label="Détail des étapes" data-autogrow>{{ $l['description'] }}</textarea>
        <select class="step-picker" data-step-picker aria-label="Ajouter une étape type">
            <option value="">+ Ajouter une étape type…</option>
            @foreach ($steps as $step)
                <option value="{{ $step->body }}">{{ $step->label }}</option>
            @endforeach
        </select>
        <div class="line-grid">
            <div class="field"><label>Quantité <button type="button" class="link-btn small" data-roof-calc title="Calculer une surface de toiture">📐 m²</button></label><input type="text" inputmode="decimal" name="{{ $n }}[quantity]" value="{{ $l['quantity'] }}" data-calc></div>
            <div class="field"><label>Unité</label>
                <select name="{{ $n }}[unit]">
                    @foreach ($units as $code => $label)
                        <option value="{{ $code }}" @selected($l['unit'] === $code)>{{ $code }}</option>
                    @endforeach
                    @if ($l['unit'] && ! $units->has($l['unit']))
                        <option value="{{ $l['unit'] }}" selected>{{ $l['unit'] }}</option>
                    @endif
                </select>
            </div>
            <div class="field"><label>Prix unitaire HT</label><input type="text" inputmode="decimal" name="{{ $n }}[unit_price]" value="{{ $l['unit_price'] }}" placeholder="0,00" data-calc></div>
            <div class="field" data-vat-field @if ($franchise) hidden @endif><label>TVA</label>
                <select name="{{ $n }}[vat_rate]" data-calc>
                    @foreach ($vatRates as $rate)
                        <option value="{{ $rate->rate }}" @selected((int) $l['vat_rate'] === $rate->rate)>{{ $rate->percentLabel() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>Remise %</label><input type="text" inputmode="decimal" name="{{ $n }}[discount_percent]" value="{{ $l['discount_percent'] }}" placeholder="0" data-calc></div>
        </div>
        <div class="line-flags">
            <label class="check small"><input type="checkbox" name="{{ $n }}[is_optional]" value="1" @checked($l['is_optional']) data-calc> <span>Option (hors total, au choix du client)</span></label>
            <label class="check small"><input type="checkbox" name="{{ $n }}[is_offered]" value="1" @checked($l['is_offered']) data-calc> <span>Offert</span></label>
        </div>
    @endif
</div>

<div class="field span-2">
    <label class="check"><input type="checkbox" name="show_bank" value="1" @checked(old('show_bank', $document->show_bank))>
        <span>Afficher le RIB (IBAN) sur le document</span></label>
    @if (! $settings->get('bank.iban'))<span class="hint">Aucun IBAN enregistré : @if (auth()->user()->canOpen('settings.company'))ajoutez-le dans <a href="{{ route('settings.company') }}">Réglages → Entreprise</a>.@else demandez au gérant de l'ajouter.@endif</span>@endif
</div>

<div class="field span-2">
    <label class="check"><input type="checkbox" name="show_bank" value="1" @checked(old('show_bank', $document->show_bank))>
        <span>Afficher le RIB (IBAN) sur le document</span></label>
    @if (! $settings->get('bank.iban'))<span class="hint">Aucun IBAN enregistré : ajoutez-le dans <a href="{{ route('settings.company') }}">Réglages → Entreprise</a>.</span>@endif
</div>

<div class="annex-title">Formulaire de rétractation</div>
<p class="small muted">Contrat conclu hors établissement — articles L221-18 et suivants du Code de la consommation.</p>
<p class="small">Vous disposez d'un délai de <b>14 jours</b> à compter de la signature du devis pour vous rétracter, sans avoir à justifier de motif.
    Pour exercer ce droit, envoyez ce formulaire (ou toute autre déclaration dénuée d'ambiguïté) avant l'expiration du délai, par courrier ou par email aux coordonnées ci-dessous.
    Aucun paiement ne peut être exigé avant l'expiration d'un délai de 7 jours à compter de la signature.</p>
<p class="small muted">(Veuillez compléter et renvoyer le présent formulaire uniquement si vous souhaitez vous rétracter du contrat.)</p>

<div class="box" style="margin-top: 8pt;">
    <p>À l'attention de <b>{{ $company['trade_name'] }} — {{ $company['owner_name'] }} {{ $company['legal_form'] }}</b>,
        {{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }} — {{ $company['email'] }}</p>
    <p>Je / Nous (*) vous notifie / notifions (*) par la présente ma / notre (*) rétractation du contrat portant sur la prestation de services ci-dessous :</p>
    <p>Devis n° <b>{{ $document->number ?? '…………' }}</b>@if ($document->title) — {{ $document->title }}@endif</p>
    <p>Signé le (*) :</p><div class="form-line"></div>
    <p style="margin-top: 8pt;">Nom du (des) consommateur(s) :</p><div class="form-line"></div>
    <p style="margin-top: 8pt;">Adresse du (des) consommateur(s) :</p><div class="form-line"></div><div class="form-line"></div>
    <p style="margin-top: 8pt;">Signature du (des) consommateur(s) (uniquement en cas de notification du présent formulaire sur papier) :</p>
    <div style="height: 22mm;"></div>
    <p>Date :</p><div class="form-line"></div>
    <p class="small muted" style="margin-top: 6pt;">(*) Rayez la mention inutile.</p>
</div>

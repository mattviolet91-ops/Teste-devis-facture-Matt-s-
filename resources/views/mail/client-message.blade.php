@php
    $company = $settings->group('company');
    $accent = $settings->get('branding.color_accent', '#3CBDE8');

    // Le lien client devient un bouton : il remplace l'adresse écrite dans le message,
    // ou s'ajoute sous le message si le modèle ne la contient pas.
    $html = nl2br(e($text));
    $button = null;
    if ($buttonUrl) {
        $button = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px 0;"><tr>'
            .'<td style="border-radius:8px; background:'.e($accent).';">'
            .'<a href="'.e($buttonUrl).'" style="display:inline-block; padding:14px 28px; font-size:16px; font-weight:bold; '
            .'color:#0B2A36; text-decoration:none; border-radius:8px; font-family:Arial, Helvetica, sans-serif;">'
            .e($buttonLabel ?? 'Ouvrir').'</a></td></tr></table>';
        $escaped = e($buttonUrl);
        if (str_contains($html, $escaped)) {
            $position = strpos($html, $escaped);
            $html = substr_replace($html, '%%BOUTON%%', $position, strlen($escaped));
            $html = str_replace('%%BOUTON%%', $button, str_replace($escaped, '', $html));
            // Pas de saut de ligne superflu autour du bouton.
            $html = preg_replace('#<br\s*/?>\s*(<table role="presentation")#', '$1', $html);
            $html = preg_replace('#(</table>)\s*<br\s*/?>#', '$1', $html);
            $button = null;
        }
    }
@endphp
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0; padding:0; background:#ECF0F1; font-family: Arial, Helvetica, sans-serif; color:#2C3E50;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ECF0F1; padding:24px 12px;">
        <tr><td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background:#ffffff; border-radius:8px; overflow:hidden;">
                <tr><td style="height:6px; background:{{ $accent }};"></td></tr>
                <tr><td style="padding:28px 28px 8px; font-size:15px; line-height:1.6;">
                    {!! $html !!}
                    {!! $button !!}
                </td></tr>
                <tr><td style="padding:16px 28px 24px; font-size:12px; color:#5F6F7D; border-top:1px solid #E5EAEC;">
                    <strong style="color:#2C3E50;">{{ $company['trade_name'] }}</strong><br>
                    {{ $company['address'] }}, {{ $company['postal_code'] }} {{ $company['city'] }}<br>
                    {{ $company['phone'] }} · {{ $company['email'] }}@if (! empty($company['website'])) · {{ $company['website'] }}@endif
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>

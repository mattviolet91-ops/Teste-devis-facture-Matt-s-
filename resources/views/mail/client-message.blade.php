@php $company = $settings->group('company'); @endphp
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0; padding:0; background:#ECF0F1; font-family: Arial, Helvetica, sans-serif; color:#2C3E50;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ECF0F1; padding:24px 12px;">
        <tr><td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background:#ffffff; border-radius:8px; overflow:hidden;">
                <tr><td style="height:6px; background:{{ $settings->get('branding.color_accent', '#3CBDE8') }};"></td></tr>
                <tr><td style="padding:28px 28px 8px; font-size:15px; line-height:1.6;">{!! nl2br(e($text)) !!}</td></tr>
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

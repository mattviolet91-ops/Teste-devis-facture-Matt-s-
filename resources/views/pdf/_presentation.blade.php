<div style="text-align: center; margin-top: 30mm;">
    @if ($logo)<img src="{{ $logo }}" style="max-height: 40mm; max-width: 120mm;" />@endif
    <div class="doc-title" style="margin-top: 10mm;">{{ $company['trade_name'] }}</div>
    @if (! empty($company['slogan']))<div class="doc-number" style="margin-top: 3mm;">{{ $company['slogan'] }}</div>@endif
    @if (! empty($company['agreements']))<p class="muted">{{ $company['agreements'] }}</p>@endif
</div>
<div style="margin: 15mm 10mm 0;" class="cgv">
    @foreach (preg_split('/\R{2,}/', trim($pdf['presentation_text'])) as $paragraph)
        <p>{!! nl2br(e(trim($paragraph))) !!}</p>
    @endforeach
</div>
<div style="text-align: center; margin-top: 15mm;" class="muted">
    {{ $company['phone'] }} · {{ $company['email'] }}@if (! empty($company['website'])) · {{ $company['website'] }}@endif
</div>

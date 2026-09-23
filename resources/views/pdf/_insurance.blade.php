{{-- Encadré « Assurance décennale » (mention obligatoire, mise en valeur). --}}
@if (! empty($insurance['insurer']) && ! empty($insurance['policy_number']))
    @php
        $shield = 'data:image/svg+xml;base64,'.base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48">'
            .'<path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5l-8-3z" fill="'.$accent.'"/>'
            .'<path d="m8.5 12.2 2.4 2.4 4.8-5" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        );
        $period = ! empty($insurance['valid_from']) && ! empty($insurance['valid_until'])
            ? 'du '.\Illuminate\Support\Carbon::parse($insurance['valid_from'])->format('d/m/Y').' au '.\Illuminate\Support\Carbon::parse($insurance['valid_until'])->format('d/m/Y')
            : null;
    @endphp
    <table width="100%" class="insurance" style="page-break-inside: avoid;">
        <tr>
            <td width="15mm" style="vertical-align: middle; text-align: center; padding: 6pt 4pt 6pt 8pt;">
                <img src="{{ $shield }}" style="width: 11mm; height: 11mm;" />
            </td>
            <td style="vertical-align: middle; padding: 6pt 8pt 6pt 4pt;">
                <div class="insurance-title">Assurance décennale</div>
                <div class="insurance-text">
                    <b>{{ $insurance['insurer'] }}</b>{{ ! empty($insurance['insurer_address']) ? ' — '.$insurance['insurer_address'] : '' }}<br>
                    Contrat n° <b>{{ $insurance['policy_number'] }}</b>{{ ! empty($insurance['broker']) ? ' (via '.$insurance['broker'].')' : '' }}{{ $period ? ' — valable '.$period : '' }}<br>
                    {{ collect([
                        ! empty($insurance['activities']) ? 'Activité couverte : '.$insurance['activities'] : null,
                        ! empty($insurance['coverage_area']) ? 'Couverture géographique : '.$insurance['coverage_area'] : null,
                    ])->filter()->implode(' — ') }}
                </div>
            </td>
        </tr>
    </table>
@endif

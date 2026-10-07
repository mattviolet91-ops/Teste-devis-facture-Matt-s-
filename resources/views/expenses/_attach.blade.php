{{-- Ranger ce devis (ou cette facture sans devis) dans un autre chantier du même client. --}}
@php $others = app(\App\Services\JobCostService::class)->otherProjects($clientId, $job['project']); @endphp
@if ($others->isNotEmpty() || $job['quotes']->count() + $job['invoices']->whereNull('quote_id')->count() > 1)
    <details class="small" style="margin-bottom:.75rem">
        <summary>Ranger {{ $what }} dans un autre chantier</summary>
        <form method="POST" action="{{ $action }}" class="form-grid cols-2" style="margin-top:.5rem">
            @csrf
            <div class="field span-2">
                <label for="project-{{ $job['project']->id }}">Chantier</label>
                <select id="project-{{ $job['project']->id }}" name="project" required>
                    @foreach ($others as $other)
                        <option value="{{ $other->id }}">{{ $other->title }}</option>
                    @endforeach
                    <option value="apart">Un chantier à part (séparé)</option>
                </select>
                <span class="hint">Plusieurs devis du même chantier : leurs factures et leurs frais sont additionnés.</span>
            </div>
            <div class="span-2"><button class="btn btn-secondary btn-sm" type="submit">Ranger</button></div>
        </form>
    </details>
@endif

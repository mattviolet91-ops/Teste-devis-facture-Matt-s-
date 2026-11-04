{{--
    Lien client d'un devis ou d'une facture : photos choisies pour le document et
    documents à signer qui lui sont rattachés. Attend : $document (Quote ou Invoice).
--}}
@php
    $photoRoute = $document instanceof \App\Models\Quote ? 'portal.quote.photo' : 'portal.invoice.photo';
    $photos = $document->photos;
    $toSign = $document->attachments()->whereNotNull('signature_requested_at')->whereNotNull('public_token')->orderBy('id')->get();
@endphp

@if ($toSign->isNotEmpty())
    <div class="card" id="documents">
        <h2>Documents à signer</h2>
        <ul class="attachment-list">
            @foreach ($toSign as $attachment)
                <li>
                    <div>
                        <a href="{{ route('portal.document', $attachment->public_token) }}">{{ $attachment->name }}</a>
                        @if ($attachment->isSigned())
                            <span class="badge badge-success">Signé le {{ $attachment->signed_at->format('d/m/Y') }}</span>
                        @else
                            <span class="badge badge-warning">À signer</span>
                        @endif
                    </div>
                    @unless ($attachment->isSigned())
                        <a class="btn btn-sm" style="margin-top:.5rem" href="{{ route('portal.document', $attachment->public_token) }}"><x-icon name="pen" /> Lire et signer</a>
                    @endunless
                </li>
            @endforeach
        </ul>
    </div>
@endif

@if ($photos->isNotEmpty())
    <div class="card" id="photos">
        <h2>Photos</h2>
        <div class="portal-photos">
            @foreach ($photos as $photo)
                <figure>
                    <a href="{{ route($photoRoute, [$document->public_token, $photo, 'photo']) }}" target="_blank" rel="noopener">
                        <img src="{{ route($photoRoute, [$document->public_token, $photo, 'mini']) }}" alt="{{ $photo->caption ?: $photo->categoryLabel() }}" loading="lazy">
                    </a>
                    <figcaption>{{ $photo->categoryLabel() }}@if ($photo->caption) — {{ $photo->caption }}@endif</figcaption>
                </figure>
            @endforeach
        </div>
    </div>
@endif

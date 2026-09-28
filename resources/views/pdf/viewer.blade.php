@extends('layouts.base', ['title' => $title])

@section('body')
    <header class="pdf-bar">
        <a class="pdf-bar-btn" href="{{ $back }}" aria-label="Fermer le PDF"><x-icon name="x" /><span>Fermer</span></a>
        <h1 class="pdf-bar-title">{{ $title }}</h1>
        <button class="pdf-bar-btn" type="button" data-pdf-share aria-label="Partager ou enregistrer le PDF"><x-icon name="send" /><span>Partager</span></button>
    </header>
    <main class="pdf-pages" id="pdf-viewer"
        data-src="{{ $src }}" data-filename="{{ $filename }}"
        data-lib="{{ asset('vendor/pdfjs/pdf.min.js') }}?v={{ filemtime(public_path('vendor/pdfjs/pdf.min.js')) }}"
        data-worker="{{ asset('vendor/pdfjs/pdf.worker.min.js') }}?v={{ filemtime(public_path('vendor/pdfjs/pdf.worker.min.js')) }}">
        <p class="pdf-status" data-pdf-status role="status" aria-live="polite">Chargement du PDF…</p>
        <p class="pdf-fallback" data-pdf-fallback hidden>
            Le PDF ne peut pas s'afficher ici. <a href="{{ $src }}" target="_blank" rel="noopener">Ouvrir le PDF</a>
        </p>
    </main>
    <script type="module" src="{{ asset('js/pdf-viewer.js') }}?v={{ filemtime(public_path('js/pdf-viewer.js')) }}"></script>
@endsection

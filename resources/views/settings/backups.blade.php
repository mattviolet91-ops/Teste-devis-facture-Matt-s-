@extends('settings.layout', ['title' => 'Sauvegardes'])

@php
    $size = fn (int $bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', ' ').' Mo' : max(1, (int) round($bytes / 1024)).' Ko';
    $labels = ['quotidienne' => 'Quotidienne (données)', 'mensuelle' => 'Mensuelle (données + fichiers)', 'complete' => 'Complète (données + fichiers)'];
@endphp

@section('settings')
    @error('backup')<div class="alert alert-error" role="alert">{{ $message }}</div>@enderror

    <div class="card">
        <h2>Télécharger ma sauvegarde</h2>
        <p class="muted small">Une sauvegarde est faite automatiquement chaque nuit sur le serveur (30 jours conservés, plus une sauvegarde complète chaque mois gardée 12 mois).
            Téléchargez aussi régulièrement une copie <strong>chez vous</strong> (ordinateur, clé USB, cloud) : en cas de problème chez l'hébergeur, vous ne perdez rien.</p>
        <p class="small">Dernier téléchargement : <strong>{{ $lastDownload ? \Illuminate\Support\Carbon::parse($lastDownload)->format('d/m/Y à H:i') : 'jamais' }}</strong></p>
        <form method="POST" action="{{ route('settings.backups.create') }}">
            @csrf
            <button class="btn" type="submit"><x-icon name="shield" /> Télécharger ma sauvegarde complète</button>
        </form>
        <p class="small muted" style="margin-bottom:0">Le fichier contient les données personnelles de vos clients : gardez-le en lieu sûr et ne le partagez pas.</p>
    </div>

    <div class="card">
        <h2>Sauvegardes sur le serveur</h2>
        @if ($backups->isEmpty())
            <p class="muted" style="margin:0">Aucune sauvegarde pour l'instant : la première sera faite cette nuit (si la tâche automatique du serveur est active).</p>
        @else
            <ul class="stat-list">
                @foreach ($backups as $backup)
                    <li>
                        <span>{{ $backup['date']->format('d/m/Y H:i') }}<br><span class="muted small">{{ $labels[$backup['type']] ?? $backup['type'] }} · {{ $size($backup['size']) }}</span></span>
                        <a class="btn btn-secondary btn-sm" href="{{ route('settings.backups.download', $backup['name']) }}">Télécharger</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection

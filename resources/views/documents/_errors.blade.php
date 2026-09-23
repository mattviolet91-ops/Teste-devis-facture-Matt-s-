@if ($errors->any())
    <div class="alert alert-error" role="alert">
        <ul class="error-list">
            @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
        </ul>
    </div>
@endif
